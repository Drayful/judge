<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Performance;
use App\Models\StreamSession;
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
                ->when(
                    $streamSessionId !== null,
                    fn ($q) => $q->where('stream_session_id', $streamSessionId),
                    fn ($q) => $q->whereNull('stream_session_id'),
                )
                ->where('status', 'performing')
                ->lockForUpdate()
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
                ->when(
                    $streamSessionId !== null,
                    fn ($q) => $q->where('stream_session_id', $streamSessionId),
                    fn ($q) => $q->whereNull('stream_session_id'),
                )
                ->where('status', 'scheduled')
                ->orderBy('order_index')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($next) {
                $next->status = 'performing';
                $next->called_at = now();
                $next->started_at = now();
                $next->save();

                return true;
            }

            $category->loadMissing(['group', 'tournament']);
            $combinedCategoryIds = $category->tournament?->combinedLiveCategoryIds() ?? [];
            if (! $category->tournament?->hasCombinedLiveQueue()
                || ! in_array($category->id, $combinedCategoryIds, true)) {
                return false;
            }

            $currentSession = $streamSessionId !== null
                ? StreamSession::query()->find($streamSessionId)
                : null;
            $currentPosition = array_search($category->id, $combinedCategoryIds, true);
            $remainingCategoryIds = $currentPosition === false
                ? []
                : array_slice($combinedCategoryIds, $currentPosition + 1);
            $siblings = Category::query()
                ->where('tournament_id', $category->tournament_id)
                ->whereIn('id', $remainingCategoryIds)
                ->get()
                ->keyBy('id');

            foreach ($remainingCategoryIds as $siblingId) {
                $sibling = $siblings->get($siblingId);
                if ($sibling === null) {
                    continue;
                }
                $targetSessionId = null;
                if ($currentSession !== null) {
                    $targetSessionId = $sibling->sessions()
                        ->where('session_no', $currentSession->session_no)
                        ->value('id');
                    if ($targetSessionId === null) {
                        continue;
                    }
                }

                $combinedNext = Performance::query()
                    ->where('category_id', $sibling->id)
                    ->when(
                        $targetSessionId !== null,
                        fn ($query) => $query->where('stream_session_id', $targetSessionId),
                        fn ($query) => $query->whereNull('stream_session_id'),
                    )
                    ->where('status', 'scheduled')
                    ->orderBy('order_index')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($combinedNext === null) {
                    continue;
                }

                $combinedNext->status = 'performing';
                $combinedNext->called_at = now();
                $combinedNext->started_at = now();
                $combinedNext->save();

                $category->tournament?->update([
                    'active_category_id' => $sibling->id,
                    'active_stream_session_id' => $targetSessionId,
                ]);

                return true;
            }

            return false;
        });
    }
}
