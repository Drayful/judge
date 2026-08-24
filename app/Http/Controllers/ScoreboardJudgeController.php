<?php

namespace App\Http\Controllers;

use App\Models\Performance;
use App\Support\ScoreboardUi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ScoreboardJudgeController extends Controller
{
    public function index(): View
    {
        $pendingPerformances = Performance::query()
            ->with(['athlete.members', 'category.tournament'])
            ->whereNotNull('approved_at')
            ->whereNotNull('total')
            ->whereNull('published_at')
            ->whereNull('withdrawn_at')
            ->orderBy('approved_at')
            ->orderBy('id')
            ->get();

        $shownPerformances = Performance::query()
            ->with(['athlete.members', 'category.tournament'])
            ->whereNotNull('approved_at')
            ->whereNotNull('published_at')
            ->whereNotNull('scoreboard_accepted_at')
            ->whereNotNull('total')
            ->whereNull('withdrawn_at')
            ->latest('scoreboard_accepted_at')
            ->latest('id')
            ->limit(30)
            ->get();

        return view('scoreboard-judge.index', compact('pendingPerformances', 'shownPerformances'));
    }

    public function accept(Request $request, Performance $performance): RedirectResponse
    {
        DB::transaction(function () use ($request, $performance) {
            $locked = Performance::query()->lockForUpdate()->findOrFail($performance->id);
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

        return back()->with('status', 'Результат показан на табло на '.ScoreboardUi::RESULT_HOLD_SECONDS.' секунд.');
    }
}
