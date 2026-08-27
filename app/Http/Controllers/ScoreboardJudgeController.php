<?php

namespace App\Http\Controllers;

use App\Models\Performance;
use App\Models\Tournament;
use App\Support\ScoreboardUi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ScoreboardJudgeController extends Controller
{
    public function index(Request $request): View
    {
        $tournaments = Tournament::query()
            ->whereHas('categories')
            ->orderByDesc('id')
            ->get(['id', 'name']);
        $selectedTournament = $this->selectedTournament($request, $tournaments);
        [$pendingPerformances, $shownPerformances] = $this->performancesFor($selectedTournament);
        $queueRevision = $this->queueRevision($pendingPerformances, $shownPerformances);

        return view('scoreboard-judge.index', compact(
            'tournaments',
            'selectedTournament',
            'pendingPerformances',
            'shownPerformances',
            'queueRevision',
        ));
    }

    public function live(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tournament' => ['required', 'integer', 'exists:tournaments,id'],
        ]);
        $tournament = Tournament::query()->findOrFail($data['tournament']);
        [$pendingPerformances, $shownPerformances] = $this->performancesFor($tournament);

        return response()->json([
            'rev' => $this->queueRevision($pendingPerformances, $shownPerformances),
            'html' => view('scoreboard-judge.partials.queues', compact(
                'tournament',
                'pendingPerformances',
                'shownPerformances',
            ))->render(),
            'pending_count' => $pendingPerformances->count(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function accept(Request $request, Performance $performance): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'tournament_id' => ['nullable', 'integer', 'exists:tournaments,id'],
        ]);

        DB::transaction(function () use ($request, $performance, $data) {
            $locked = Performance::query()
                ->with('category:id,tournament_id')
                ->lockForUpdate()
                ->findOrFail($performance->id);
            if (isset($data['tournament_id'])
                && (int) $locked->category?->tournament_id !== (int) $data['tournament_id']) {
                abort(422, 'Эта оценка относится к другому турниру.');
            }
            if ($locked->approved_at === null || $locked->total === null || $locked->isWithdrawn()) {
                abort(422, 'На табло можно принять только подтверждённый главным судьёй результат.');
            }

            $acceptedAt = now();
            $locked->update([
                'published_at' => $locked->published_at ?? $acceptedAt,
                'scoreboard_accepted_at' => $acceptedAt,
                'scoreboard_accepted_by' => $request->user()?->id,
                'status' => 'published',
            ]);
        });

        $message = 'Результат показан на табло на '.ScoreboardUi::RESULT_HOLD_SECONDS.' секунд.';
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'performance_id' => $performance->id,
            ]);
        }

        return back()->with('status', $message);
    }

    /**
     * @return array{0: Collection<int, Performance>, 1: Collection<int, Performance>}
     */
    private function performancesFor(?Tournament $tournament): array
    {
        if ($tournament === null) {
            return [collect(), collect()];
        }

        $withinTournament = fn ($query) => $query->where('tournament_id', $tournament->id);
        $pendingPerformances = Performance::query()
            ->with(['athlete.members', 'category.tournament'])
            ->whereHas('category', $withinTournament)
            ->whereNotNull('approved_at')
            ->whereNotNull('total')
            ->whereNull('published_at')
            ->whereNull('withdrawn_at')
            ->orderBy('approved_at')
            ->orderBy('id')
            ->get();

        $shownPerformances = Performance::query()
            ->with(['athlete.members', 'category.tournament'])
            ->whereHas('category', $withinTournament)
            ->whereNotNull('approved_at')
            ->whereNotNull('published_at')
            ->whereNotNull('scoreboard_accepted_at')
            ->whereNotNull('total')
            ->whereNull('withdrawn_at')
            ->latest('scoreboard_accepted_at')
            ->latest('id')
            ->limit(30)
            ->get();

        return [$pendingPerformances, $shownPerformances];
    }

    /** @param Collection<int, Tournament> $tournaments */
    private function selectedTournament(Request $request, Collection $tournaments): ?Tournament
    {
        $requestedId = $request->integer('tournament');
        if ($requestedId > 0 && $tournaments->contains('id', $requestedId)) {
            $request->session()->put('scoreboard_judge_tournament_id', $requestedId);

            return $tournaments->firstWhere('id', $requestedId);
        }

        $rememberedId = (int) $request->session()->get('scoreboard_judge_tournament_id', 0);

        return $tournaments->firstWhere('id', $rememberedId) ?? $tournaments->first();
    }

    /**
     * @param  Collection<int, Performance>  $pendingPerformances
     * @param  Collection<int, Performance>  $shownPerformances
     */
    private function queueRevision(Collection $pendingPerformances, Collection $shownPerformances): string
    {
        $pending = $pendingPerformances->map(fn (Performance $performance) => implode(':', [
            $performance->id,
            $performance->approved_at?->getTimestamp(),
            $performance->total,
        ]));
        $shown = $shownPerformances->map(fn (Performance $performance) => implode(':', [
            $performance->id,
            $performance->scoreboard_accepted_at?->getTimestamp(),
            $performance->total,
        ]));

        return md5($pending->concat($shown)->implode('|'));
    }
}
