<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Performance;
use Illuminate\Support\Facades\DB;

class StreamAdvanceService
{
    /**
     * Завершает текущее выступление (performing → done) и вызывает следующее из scheduled.
     *
     * @return bool true, если был переход на новую гимнастку (есть следующая в очереди)
     */
    public static function advanceToNextInCategory(Category $category, ?int $streamSessionId = null): bool
    {
        return DB::transaction(function () use ($category, $streamSessionId) {
            $performing = Performance::query()
                ->where('category_id', $category->id)
                ->when($streamSessionId, fn ($q) => $q->where('stream_session_id', $streamSessionId))
                ->where('status', 'performing')
                ->first();

            if ($performing) {
                $performing->status = 'done';
                $performing->loadMissing('category');
                $performing->recordFinishedAt();
                $performing->recalculateTotals();
                $performing->save();
            }

            $next = Performance::query()
                ->where('category_id', $category->id)
                ->when($streamSessionId, fn ($q) => $q->where('stream_session_id', $streamSessionId))
                ->where('status', 'scheduled')
                ->orderBy('order_index')
                ->orderBy('id')
                ->first();

            if ($next) {
                $next->status = 'performing';
                $next->called_at = now();
                $next->started_at = now();
                $next->save();

                return true;
            }

            return false;
        });
    }
}
