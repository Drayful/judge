<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Entry;
use App\Models\Group;
use App\Models\Performance;
use App\Support\PerformanceApparatus;
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
    /**
     * Авто-разбивка пула группы на потоки по размеру + генерация очередей.
     *
     * @param  list<array{start:?string,end:?string}>  $times  метки времени по индексу потока (0-based)
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
        });
    }

    /**
     * @param  array{start:?string,end:?string}  $times
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
                $sessionIdByApparatus[(string) $sessionApparatus] = $session->id;
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
                'stream_session_id' => $sessionIdByApparatus[$base] ?? null,
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
