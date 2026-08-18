<?php

namespace App\Http\Controllers;

use App\Models\Performance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ScoreboardJudgeController extends Controller
{
    public function index(): View
    {
        $performances = Performance::query()
            ->with(['athlete.members', 'category.tournament'])
            ->whereNotNull('approved_at')
            ->whereNotNull('total')
            ->whereNull('published_at')
            ->whereNull('withdrawn_at')
            ->orderByDesc('approved_at')
            ->orderBy('id')
            ->get();

        return view('scoreboard-judge.index', compact('performances'));
    }

    public function accept(Request $request, Performance $performance): RedirectResponse
    {
        DB::transaction(function () use ($request, $performance) {
            $locked = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            if ($locked->approved_at === null || $locked->total === null || $locked->isWithdrawn()) {
                abort(422, 'На табло можно принять только подтверждённый главным судьёй результат.');
            }

            if ($locked->published_at === null) {
                $acceptedAt = now();
                $locked->update([
                    'published_at' => $acceptedAt,
                    'scoreboard_accepted_at' => $acceptedAt,
                    'scoreboard_accepted_by' => $request->user()?->id,
                    'status' => 'published',
                ]);
            }
        });

        return back()->with('status', 'Результат принят: табло обновлено, время принятия сохранено.');
    }
}
