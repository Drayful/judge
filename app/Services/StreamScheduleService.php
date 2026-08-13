<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Performance;
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
