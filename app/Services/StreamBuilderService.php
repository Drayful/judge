<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Entry;
use App\Models\Group;
use App\Models\MusicTrack;
use App\Models\Performance;
use App\Models\StreamSession;
use App\Models\Tournament;
use App\Support\PerformanceApparatus;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Формирование потоков (categories) и очередей выступлений из группы.
 *
 * Поток = временной блок группы; его выступления генерируются по набору предметов
 * группы — одно выступление на круг на участницу, порядок круг-за-кругом
 * (сначала все 1-й круг по стартовому, затем 2-й и т.д.).
 */
class StreamBuilderService
{
    public function __construct(
        private readonly StreamScheduleService $schedule,
        private readonly GroupStreamSessionService $groupSessions,
    ) {}

    /**
     * Авто-разбивка пула группы на потоки по размеру + генерация очередей.
     *
     * @param  list<array{start:?string,end:?string,minutes_per_athlete?:int,schedule_chain?:string,schedule_sequence?:int}>  $times  метки времени по индексу потока (0-based)
     */
    public function generateStreams(Group $group, int $streamSize, array $times = [], string $numberMode = 'per_stream', ?string $scheduledOn = null): void
    {
        $streamSize = max(1, $streamSize);

        DB::transaction(function () use ($group, $streamSize, $times, $numberMode, $scheduledOn) {
            $music = $this->musicLinks($group);
            $entries = $group->entries()
                ->orderBy('order_index')
                ->orderBy('id')
                ->get();

            $chunks = $entries->chunk($streamSize)->values();

            $running = 0;
            foreach ($chunks as $i => $chunk) {
                $streamNo = $i + 1;
                $j = 0;
                foreach ($chunk as $entry) {
                    $j++;
                    $entry->stream_no = $streamNo;
                    $entry->start_number = $numberMode === 'per_stream' ? $j : ++$running;
                    $entry->save();
                }
            }

            $group->number_mode = $numberMode;
            $group->save();

            $kept = [];
            foreach ($chunks as $i => $chunk) {
                $streamNo = $i + 1;
                $t = $times[$i] ?? ['start' => null, 'end' => null];
                $category = $this->upsertStreamCategory($group, $streamNo, $t);
                $this->rebuildPerformances($group, $category, $chunk);
                $kept[] = $streamNo;
            }

            $this->purgeStaleStreams($group, $kept);
            $this->relinkMusic($group, $music);

            if ($kept !== []) {
                $firstCategory = Category::query()
                    ->where('group_id', $group->id)
                    ->where('stream_no', min($kept))
                    ->first();
                if ($firstCategory !== null) {
                    $this->schedule->recalculate($firstCategory);
                }
                if ($scheduledOn !== null) {
                    foreach ($group->categories()->get() as $category) {
                        if ($category->sessions()->exists()) {
                            continue;
                        }
                        $session = $category->sessions()->create([
                            'session_no' => 1, 'scheduled_on' => $scheduledOn,
                            'starts_at' => $category->starts_at_label, 'ends_at' => $category->ends_at_label,
                            'apparatus' => $group->apparatusLabels(),
                        ]);
                        $category->performances()->whereNull('stream_session_id')->update(['stream_session_id' => $session->id]);
                    }
                }
            }
        });
    }

    /**
     * Создание потоков со страницы «Потоки»: равномерное деление на N потоков
     * и дни (сессии) группы со своими видами. Время считается по каждому дню.
     *
     * @param  array{stream_count:int,number_mode:string,minutes:int,order:string,apparatus_mode:string,apparatus:list<string>,apparatus_count:?int,days:list<array{date:string,start:string,apparatus:list<string>}>}  $options
     */
    public function createStreams(Group $group, array $options): void
    {
        DB::transaction(function () use ($group, $options) {
            $this->ensureNotStarted($group);
            $choice = $options['apparatus_mode'] === 'choice';

            $group->update([
                'apparatus' => $choice ? [] : array_values($options['apparatus']),
                'apparatus_selection_mode' => $choice ? 'choice' : 'fixed',
                'apparatus_count' => $choice ? (int) $options['apparatus_count'] : null,
                'number_mode' => $options['number_mode'],
                'schedule_mode' => 'sessions',
            ]);

            $entries = $group->entries()->orderBy('order_index')->orderBy('id')->get()->values();
            if ($options['order'] === 'draw') {
                $entries = $entries->shuffle()->values();
            }
            $count = max(1, min((int) $options['stream_count'], max(1, $entries->count())));
            $base = intdiv($entries->count(), $count);
            $extra = $entries->count() % $count;
            $position = 0;
            for ($stream = 1; $stream <= $count; $stream++) {
                $size = $base + ($stream <= $extra ? 1 : 0);
                for ($k = 0; $k < $size; $k++) {
                    $entry = $entries[$position];
                    $entry->stream_no = $stream;
                    $entry->order_index = ++$position;
                    $entry->save();
                }
            }

            StreamSession::query()->whereIn('category_id', $group->categories()->select('id'))->delete();
            $this->renumber($group);

            $categories = $group->categories()->orderBy('stream_no')->get();
            foreach ($categories as $category) {
                $category->update(['minutes_per_athlete' => max(1, (int) $options['minutes']), 'schedule_chain' => null]);
                foreach (array_values($options['days']) as $i => $day) {
                    $session = StreamSession::query()->create([
                        'category_id' => $category->id,
                        'session_no' => $i + 1,
                        'scheduled_on' => $day['date'],
                        'starts_at' => $day['start'],
                        'apparatus' => $choice ? [] : array_values($day['apparatus']),
                    ]);
                    $this->groupSessions->syncCategorySession($category, $session);
                }
            }

            $this->schedule->recalculateGroupSessions($group->fresh());
        });
    }

    /**
     * Раскладка с доски: порядок и потоки так, как их расставил секретарь.
     * Пустые потоки убираются, нумерация и очереди пересобираются.
     *
     * @param  list<list<int>>  $streams  id записей по потокам
     * @param  list<int>  $unassigned  записи группы вне потоков
     */
    public function arrange(Group $group, array $streams, array $unassigned): void
    {
        DB::transaction(function () use ($group, $streams, $unassigned) {
            $this->ensureNotStarted($group);

            $entries = $group->entries()->lockForUpdate()->get()->keyBy('id');
            $given = array_merge(array_merge(...array_values($streams) ?: [[]]), $unassigned);
            if (count($given) !== count(array_unique($given)) || count($given) !== $entries->count()
                || array_diff($given, $entries->keys()->all()) !== []) {
                throw new DomainException('Состав группы изменился. Обновите страницу и повторите.');
            }

            $order = 0;
            $streamNo = 0;
            foreach ($streams as $ids) {
                if ($ids === []) {
                    continue;
                }
                $streamNo++;
                foreach ($ids as $id) {
                    $entries[$id]->forceFill(['stream_no' => $streamNo, 'order_index' => ++$order])->save();
                }
            }
            foreach ($unassigned as $id) {
                $entries[$id]->forceFill(['stream_no' => null, 'start_number' => null, 'order_index' => ++$order])->save();
            }

            $this->renumber($group);
        });
    }

    /**
     * Дата/начало дня группы и минуты на выход.
     */
    public function updateDay(Group $group, int $sessionNo, string $date, string $start, int $minutes): void
    {
        DB::transaction(function () use ($group, $sessionNo, $date, $start, $minutes) {
            $sessions = StreamSession::query()
                ->whereIn('category_id', $group->categories()->select('id'))
                ->where('session_no', $sessionNo)
                ->lockForUpdate()
                ->get();
            if ($sessions->isEmpty()) {
                throw new DomainException('День не найден. Обновите страницу.');
            }
            if (Performance::query()->whereIn('stream_session_id', $sessions->pluck('id'))->whereNotIn('status', ['scheduled', 'withdrawn'])->exists()) {
                throw new DomainException('Этот день уже начался — время менять нельзя.');
            }
            foreach ($sessions as $session) {
                $session->update(['scheduled_on' => $date, 'starts_at' => $start]);
            }
            $group->categories()->update(['minutes_per_athlete' => max(1, $minutes)]);
            $this->schedule->recalculateGroupSessions($group);
        });
    }

    public function hasStarted(Group $group): bool
    {
        return Performance::query()
            ->whereIn('category_id', $group->categories()->select('id'))
            ->whereNotIn('status', ['scheduled', 'withdrawn'])
            ->exists();
    }

    private function ensureNotStarted(Group $group): void
    {
        if ($this->hasStarted($group)) {
            throw new DomainException('В группе уже начались выступления — менять потоки нельзя. Порядок оставшихся меняйте в Live.');
        }
    }

    /**
     * Перенести участницу в другой поток (ручная правка): встаёт в конец нового
     * потока, затем пересчёт номеров/очередей.
     */
    public function moveEntryToStream(Entry $entry, int $streamNo): void
    {
        $group = $entry->group;

        $entry->stream_no = max(1, $streamNo);
        if ($group !== null) {
            $entry->order_index = (int) (Entry::query()->where('group_id', $group->id)->max('order_index') ?? 0) + 1;
        }
        $entry->save();

        if ($group !== null) {
            $this->renumber($group);
        }
    }

    public function moveEntryWithinStream(Entry $entry, string $direction): bool
    {
        $group = $entry->group;
        if ($group === null || $entry->stream_no === null) {
            return false;
        }

        return DB::transaction(function () use ($entry, $group, $direction) {
            $entries = $group->entries()
                ->where('stream_no', $entry->stream_no)
                ->orderBy('order_index')
                ->orderBy('id')
                ->get()
                ->values();

            $position = $entries->search(fn (Entry $candidate) => $candidate->id === $entry->id);
            $targetPosition = $position === false ? -1 : $position + ($direction === 'up' ? -1 : 1);

            if ($targetPosition < 0 || $targetPosition >= $entries->count()) {
                return false;
            }

            $target = $entries[$targetPosition];
            $entryOrder = $entry->order_index;
            $entry->order_index = $target->order_index;
            $target->order_index = $entryOrder;
            $entry->save();
            $target->save();

            $this->renumber($group);

            return true;
        });
    }

    /**
     * Перемешать порядок участниц ВНУТРИ каждого потока (жеребьёвка): состав потоков
     * и их диапазоны номеров сохраняются, меняется только порядок выхода/номера внутри.
     */
    public function shuffle(Group $group): void
    {
        DB::transaction(function () use ($group) {
            $byStream = $group->entries()
                ->whereNotNull('stream_no')
                ->orderBy('stream_no')
                ->get()
                ->groupBy('stream_no');

            $order = 0;
            foreach ($byStream as $chunk) {
                foreach ($chunk->shuffle() as $entry) {
                    $entry->order_index = ++$order;
                    $entry->save();
                }
            }

            $this->renumber($group);
        });
    }

    /**
     * Жеребьёвка команд групповой программы между системными группами одного
     * Excel-листа. Команда остаётся неделимой Entry; сохраняются количество
     * команд в каждой группе и занятые ими места в потоках.
     */
    public function shuffleImportedTeamsBetweenGroups(Tournament $tournament, string $sheet): int
    {
        return DB::transaction(function () use ($tournament, $sheet) {
            $entries = Entry::query()
                ->where('tournament_id', $tournament->id)
                ->where('program', 'group')
                ->whereNotNull('group_id')
                ->orderBy('group_id')
                ->orderBy('stream_no')
                ->orderBy('order_index')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(function (Entry $entry) use ($sheet) {
                    $entrySheet = $entry->importSheet();

                    return $entrySheet !== null && hash_equals($sheet, $entrySheet);
                })
                ->values();

            if ($entries->count() < 2) {
                throw new DomainException('На выбранном Excel-листе недостаточно команд для жеребьёвки.');
            }

            $groupIds = $entries->pluck('group_id')->map(fn ($id) => (int) $id)->unique()->values();
            if ($groupIds->count() < 2) {
                throw new DomainException('Команды этого Excel-листа находятся только в одной группе. Нужны минимум две группы.');
            }

            $groups = Group::query()
                ->where('tournament_id', $tournament->id)
                ->whereIn('id', $groupIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($groups->count() !== $groupIds->count()) {
                throw new DomainException('Одна из групп Excel-листа больше не существует. Обновите страницу и повторите действие.');
            }

            $locked = Category::query()
                ->whereIn('group_id', $groupIds)
                ->whereHas('performances', fn ($query) => $query->where('status', '!=', 'scheduled'))
                ->exists();
            if ($locked) {
                throw new DomainException('Нельзя менять состав: в одной из групп уже начались выступления.');
            }

            $slots = $entries->map(fn (Entry $entry) => [
                'group_id' => (int) $entry->group_id,
                'stream_no' => $entry->stream_no,
                'start_number' => $entry->start_number,
                'order_index' => $entry->order_index,
            ])->values();

            $shuffled = $entries->shuffle()->values();
            $hasCrossGroupMove = $shuffled->contains(
                fn (Entry $entry, int $index) => (int) $entry->group_id !== $slots[$index]['group_id']
            );

            // Случайная последовательность теоретически может полностью сохранить
            // распределение. В таком случае циклический сдвиг гарантирует обмен.
            if (! $hasCrossGroupMove) {
                $shuffled = $shuffled->slice(1)->push($shuffled->first())->values();
            }

            $moved = 0;
            foreach ($shuffled as $index => $entry) {
                $slot = $slots[$index];
                if ((int) $entry->group_id !== $slot['group_id']) {
                    $moved++;
                }

                $entry->update($slot);
            }

            foreach ($groups as $group) {
                $this->renumber($group);
            }

            return $moved;
        });
    }

    /**
     * Пересчёт стартовых номеров по текущему распределению (stream_no) и пересборка очередей.
     */
    public function renumber(Group $group): void
    {
        DB::transaction(function () use ($group) {
            $music = $this->musicLinks($group);
            $entries = $group->entries()
                ->whereNotNull('stream_no')
                ->orderBy('stream_no')
                ->orderBy('order_index')
                ->orderBy('id')
                ->get();

            $byStream = $entries->groupBy('stream_no');

            $running = 0;
            $kept = [];
            foreach ($byStream as $streamNo => $chunk) {
                $streamNo = (int) $streamNo;
                $j = 0;
                foreach ($chunk as $entry) {
                    $j++;
                    $entry->start_number = $group->number_mode === 'per_stream' ? $j : ++$running;
                    $entry->save();
                }

                $category = $this->upsertStreamCategory($group, $streamNo, ['start' => null, 'end' => null]);
                $this->rebuildPerformances($group, $category, $chunk);
                $kept[] = $streamNo;
            }

            $this->purgeStaleStreams($group, $kept);
            $this->relinkMusic($group, $music);

            if ($kept !== []) {
                $firstCategory = Category::query()
                    ->where('group_id', $group->id)
                    ->where('stream_no', min($kept))
                    ->first();
                if ($firstCategory !== null) {
                    $this->schedule->recalculate($firstCategory);
                }
            }
        });
    }

    /**
     * @param  array{start:?string,end:?string,minutes_per_athlete?:int,schedule_chain?:string,schedule_sequence?:int}  $times
     */
    private function upsertStreamCategory(Group $group, int $streamNo, array $times): Category
    {
        $category = Category::query()->firstOrNew([
            'tournament_id' => $group->tournament_id,
            'group_id' => $group->id,
            'stream_no' => $streamNo,
        ]);

        $isNew = ! $category->exists;

        // Время: из переданных меток, иначе сохраняем текущее (renumber не сбрасывает).
        $start = ($times['start'] ?? null) !== null ? $times['start'] : $category->starts_at_label;
        $end = ($times['end'] ?? null) !== null ? $times['end'] : $category->ends_at_label;

        $category->starts_at_label = $start;
        $category->ends_at_label = $end;
        if (array_key_exists('minutes_per_athlete', $times)) {
            $category->minutes_per_athlete = $times['minutes_per_athlete'];
        }
        if (array_key_exists('schedule_chain', $times)) {
            $category->schedule_chain = $times['schedule_chain'];
        }
        if (array_key_exists('schedule_sequence', $times)) {
            $category->schedule_sequence = $times['schedule_sequence'];
        }

        $timeSuffix = '';
        if ($start !== null && $start !== '') {
            $timeSuffix = $end !== null && $end !== ''
                ? ' ('.$start.'–'.$end.')'
                : ' ('.$start.')';
        }

        $category->name = $group->name.' — Поток '.$streamNo.$timeSuffix;
        $category->program = $group->program;
        $category->birth_year = $group->birth_year;
        $category->division = $group->division;
        $category->apparatus = $this->apparatusSummary($group);
        $category->auto_advance = true;

        if ($isNew) {
            $category->is_published = false;
        }

        $category->save();

        if ($isNew) {
            $this->groupSessions->copyGroupScheduleToNewCategory($category);
        }

        return $category;
    }

    /**
     * @param  Collection<int, Entry>  $chunk
     */
    private function rebuildPerformances(Group $group, Category $category, Collection $chunk): void
    {
        // Пересобираем только запланированную очередь — начатые/завершённые не трогаем.
        Performance::query()
            ->where('category_id', $category->id)
            ->where('status', 'scheduled')
            ->delete();

        $labels = $group->apparatusLabels();
        if ($labels === []) {
            return;
        }

        $expanded = [];
        foreach ($labels as $roundIndex => $base) {
            foreach ($chunk as $entry) {
                $expanded[] = [
                    'entry' => $entry,
                    'round' => $roundIndex,
                    'base' => (string) $base,
                    'start' => (int) ($entry->start_number ?? 0),
                ];
            }
        }

        usort($expanded, function ($a, $b) {
            $c = $a['round'] <=> $b['round'];

            return $c !== 0 ? $c : $a['start'] <=> $b['start'];
        });

        $occurrence = [];
        $orderIndex = 0;
        $sessionIdByApparatus = [];
        $sessions = $category->sessions()->get();
        foreach ($sessions as $session) {
            foreach ($session->apparatus ?? [] as $sessionApparatus) {
                $sessionIdByApparatus[PerformanceApparatus::sessionKey($sessionApparatus)] = $session->id;
            }
        }
        // Один день без списка видов («вид на выбор»): все выступления в нём.
        $onlySessionId = $sessions->count() === 1 && ($sessions->first()->apparatus ?? []) === [] ? $sessions->first()->id : null;
        foreach ($expanded as $it) {
            /** @var Entry $entry */
            $entry = $it['entry'];
            $base = $it['base'];
            $athleteId = (int) $entry->athlete_id;

            $occurrence[$athleteId][$base] = ($occurrence[$athleteId][$base] ?? 0) + 1;
            $occ = $occurrence[$athleteId][$base];
            $apparatus = $occ === 1 ? $base : $base.' · '.$occ;
            $apparatus = PerformanceApparatus::normalize($apparatus);

            $duplicate = Performance::query()
                ->where('category_id', $category->id)
                ->where('athlete_id', $athleteId)
                ->where('apparatus', $apparatus)
                ->exists();

            if ($duplicate) {
                continue;
            }

            Performance::query()->create([
                'category_id' => $category->id,
                'stream_session_id' => $sessionIdByApparatus[PerformanceApparatus::sessionKey($base)] ?? $onlySessionId,
                'athlete_id' => $athleteId,
                'start_number' => $entry->start_number,
                'order_index' => ++$orderIndex,
                'status' => 'scheduled',
                'apparatus' => $apparatus !== '' ? $apparatus : null,
            ]);
        }
    }

    /**
     * Удаляем лишние потоки группы (после уменьшения разбивки) — только если в них
     * нет начатых/завершённых выступлений (иначе оставляем, чтобы не потерять данные).
     *
     * @param  list<int>  $keptStreamNos
     */
    private function purgeStaleStreams(Group $group, array $keptStreamNos): void
    {
        $stale = Category::query()
            ->where('group_id', $group->id)
            ->when($keptStreamNos !== [], fn ($q) => $q->whereNotIn('stream_no', $keptStreamNos))
            ->get();

        foreach ($stale as $category) {
            $hasRealPerformances = Performance::query()
                ->where('category_id', $category->id)
                ->where('status', '!=', 'scheduled')
                ->exists();

            if ($hasRealPerformances) {
                continue;
            }

            Performance::query()->where('category_id', $category->id)->delete();
            $category->delete();
        }
    }

    /**
     * Музыка привязана к выступлению, а очередь пересобирается заново —
     * запоминаем, чей трек к какому виду относился.
     *
     * @return list<array{athlete_id:int,apparatus:?string,track_ids:list<int>}>
     */
    private function musicLinks(Group $group): array
    {
        $performances = Performance::query()
            ->whereIn('category_id', $group->categories()->select('id'))
            ->where('status', 'scheduled')
            ->get(['id', 'athlete_id', 'apparatus'])
            ->keyBy('id');
        if ($performances->isEmpty()) {
            return [];
        }

        return MusicTrack::query()
            ->whereIn('performance_id', $performances->keys())
            ->get(['id', 'performance_id'])
            ->groupBy('performance_id')
            ->map(fn (Collection $tracks, $performanceId) => [
                'athlete_id' => (int) $performances[$performanceId]->athlete_id,
                'apparatus' => $performances[$performanceId]->apparatus,
                'track_ids' => $tracks->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ])
            ->values()
            ->all();
    }

    /** @param list<array{athlete_id:int,apparatus:?string,track_ids:list<int>}> $links */
    private function relinkMusic(Group $group, array $links): void
    {
        foreach ($links as $link) {
            $performanceId = Performance::query()
                ->whereIn('category_id', $group->categories()->select('id'))
                ->where('athlete_id', $link['athlete_id'])
                ->where('apparatus', $link['apparatus'])
                ->orderByRaw("CASE WHEN status = 'scheduled' THEN 0 ELSE 1 END")
                ->value('id');
            if ($performanceId !== null) {
                MusicTrack::query()->whereIn('id', $link['track_ids'])->update(['performance_id' => $performanceId]);
            }
        }
    }

    private function apparatusSummary(Group $group): ?string
    {
        $labels = $group->apparatusLabels();

        if ($labels !== []) {
            return implode(', ', $labels);
        }

        return $group->hasPendingApparatusSelection()
            ? 'Вид на выбор ('.((int) $group->apparatus_count).')'
            : null;
    }
}
