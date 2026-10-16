<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Group;
use App\Models\Performance;
use App\Models\TournamentAward;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StreamScheduleService
{
    /**
     * Пересчитать поток и все следующие потоки той же автоматически созданной цепочки.
     */
    public function recalculate(Category $category): void
    {
        $category->refresh();

        if ($category->group?->schedule_mode === 'sessions') {
            $this->recalculateGroupSessions($category->group);

            return;
        }

        if ($category->schedule_chain !== null && $category->schedule_chain !== '') {
            $categories = Category::query()
                ->where('tournament_id', $category->tournament_id)
                ->where('schedule_chain', $category->schedule_chain)
                ->orderBy('schedule_sequence')
                ->orderBy('id')
                ->get();

            $this->recalculateSequence($categories);

            return;
        }

        $this->recalculateOne($category);
    }

    /** @param Collection<int, Category> $categories */
    public function recalculateSequence(Collection $categories): void
    {
        if ($categories->isEmpty()) {
            return;
        }

        $first = $categories->first();
        if ($first->starts_at_label === null || $first->starts_at_label === '') {
            return;
        }

        $cursor = Carbon::createFromFormat('H:i', substr($first->starts_at_label, 0, 5))->startOfMinute();

        DB::transaction(function () use ($categories, &$cursor) {
            foreach ($categories as $category) {
                $category->starts_at_label = $cursor->format('H:i');
                $cursor = $this->applyPerformanceTimes($category, $cursor);
            }
        });
    }

    public function recalculateOne(Category $category): void
    {
        if ($category->starts_at_label === null || $category->starts_at_label === '') {
            return;
        }

        $start = Carbon::createFromFormat('H:i', substr($category->starts_at_label, 0, 5))->startOfMinute();
        DB::transaction(fn () => $this->applyPerformanceTimes($category, $start));
    }

    /**
     * Время по дням группы: в каждой сессии (дне) потоки идут друг за другом
     * от времени начала первого потока; снятые время не занимают.
     * Выход, попавший на награждение этого дня, переносится на его окончание.
     */
    public function recalculateGroupSessions(Group $group): void
    {
        $categories = $group->categories()->with('sessions')->orderBy('stream_no')->orderBy('id')->get();
        $sessionNos = $categories->flatMap(fn (Category $category) => $category->sessions->pluck('session_no'))->unique()->sort()->values();

        DB::transaction(function () use ($categories, $sessionNos) {
            $awards = [];
            foreach ($sessionNos as $sessionNo) {
                $cursor = null;
                foreach ($categories as $category) {
                    $session = $category->sessions->firstWhere('session_no', $sessionNo);
                    if ($session === null) {
                        continue;
                    }
                    if ($cursor === null) {
                        if ($session->starts_at === null || $session->starts_at === '') {
                            break;
                        }
                        $cursor = Carbon::createFromFormat('H:i', substr((string) $session->starts_at, 0, 5))->startOfMinute();
                    }
                    $date = $session->scheduled_on?->format('Y-m-d');
                    $dayAwards = $date !== null
                        ? ($awards[$date] ??= $this->awardIntervals((int) $category->tournament_id, $date))
                        : [];
                    $minutes = max(1, (int) ($category->minutes_per_athlete ?? 2));
                    $this->skipAwards($cursor, $dayAwards);
                    $session->starts_at = $cursor->format('H:i');

                    Performance::query()->where('stream_session_id', $session->id)->update(['scheduled_at_label' => null]);
                    $performances = Performance::query()
                        ->where('stream_session_id', $session->id)
                        ->whereNull('withdrawn_at')
                        ->where('status', '!=', 'withdrawn')
                        ->orderBy('order_index')
                        ->orderBy('id')
                        ->get(['id']);
                    foreach ($performances as $performance) {
                        $this->skipAwards($cursor, $dayAwards);
                        Performance::query()->whereKey($performance->id)->update(['scheduled_at_label' => $cursor->format('H:i')]);
                        $cursor->addMinutes($minutes);
                    }
                    $session->ends_at = $cursor->format('H:i');
                    $session->save();
                }
            }

            foreach ($categories as $category) {
                $first = $category->sessions->sortBy('session_no')->first();
                if ($first === null || $first->starts_at === null) {
                    continue;
                }
                $category->starts_at_label = substr((string) $first->starts_at, 0, 5);
                $category->ends_at_label = substr((string) $first->ends_at, 0, 5);
                $this->updateCategoryName($category);
                $category->save();
            }
        });
    }

    /**
     * Интервалы награждений дня (минуты от полуночи), по возрастанию начала.
     *
     * @return list<array{0:int,1:int}>
     */
    public function awardIntervals(int $tournamentId, string $date): array
    {
        $minutes = fn ($time) => ((int) substr((string) $time, 0, 2)) * 60 + (int) substr((string) $time, 3, 2);

        return TournamentAward::query()
            ->where('tournament_id', $tournamentId)
            ->whereDate('scheduled_on', $date)
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->get(['starts_at', 'ends_at'])
            ->map(fn (TournamentAward $award) => [$minutes($award->starts_at), $minutes($award->ends_at)])
            ->filter(fn (array $interval) => $interval[1] > $interval[0])
            ->sortBy(fn (array $interval) => $interval[0])
            ->values()
            ->all();
    }

    /**
     * Если момент выхода попадает на награждение — переносим его на окончание
     * (с учётом награждений, идущих подряд).
     *
     * @param  list<array{0:int,1:int}>  $awards
     */
    private function skipAwards(Carbon $cursor, array $awards): void
    {
        foreach ($awards as [$start, $end]) {
            $now = $cursor->hour * 60 + $cursor->minute;
            if ($now >= $start && $now < $end) {
                $cursor->setTime(intdiv($end, 60), $end % 60);
            }
        }
    }

    private function applyPerformanceTimes(Category $category, Carbon $start): Carbon
    {
        $minutes = max(1, (int) ($category->minutes_per_athlete ?? 2));
        $performances = Performance::query()
            ->where('category_id', $category->id)
            ->whereNull('withdrawn_at')
            ->where('status', '!=', 'withdrawn')
            ->orderBy('order_index')
            ->orderBy('id')
            ->get(['id']);

        $cursor = $start->copy();

        Performance::query()
            ->where('category_id', $category->id)
            ->update(['scheduled_at_label' => null]);

        foreach ($performances as $performance) {
            Performance::query()->whereKey($performance->id)->update([
                'scheduled_at_label' => $cursor->format('H:i'),
            ]);
            $cursor->addMinutes($minutes);
        }

        $category->ends_at_label = $cursor->format('H:i');
        $this->updateCategoryName($category);
        $category->save();

        return $cursor;
    }

    private function updateCategoryName(Category $category): void
    {
        $category->loadMissing('group');
        if ($category->group === null || $category->stream_no === null) {
            return;
        }

        $category->name = $category->group->name.' — Поток '.$category->stream_no
            .' ('.$category->starts_at_label.'–'.$category->ends_at_label.')';
    }
}
