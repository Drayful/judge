<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Entry;
use App\Models\Group;
use App\Models\Performance;
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
    ) {}

    /**
     * Авто-разбивка пула группы на потоки по размеру + генерация очередей.
     *
     * @param  list<array{start:?string,end:?string,minutes_per_athlete?:int,schedule_chain?:string,schedule_sequence?:int}>  $times  метки времени по индексу потока (0-based)
     */
    public function generateStreams(Group $group, int $streamSize, array $times = [], string $numberMode = 'per_stream'): void
    {
        $streamSize = max(1, $streamSize);

        DB::transaction(function () use ($group, $streamSize, $times, $numberMode) {
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
        foreach ($category->sessions()->get() as $session) {
            foreach ($session->apparatus ?? [] as $sessionApparatus) {
                $sessionIdByApparatus[PerformanceApparatus::sessionKey($sessionApparatus)] = $session->id;
            }
        }
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
                'stream_session_id' => $sessionIdByApparatus[PerformanceApparatus::sessionKey($base)] ?? null,
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
