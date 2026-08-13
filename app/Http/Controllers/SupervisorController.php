<?php

namespace App\Http\Controllers;

use App\Models\Performance;
use App\Support\SecretaryLiveUi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupervisorController extends Controller
{
    public function approve(Performance $performance): RedirectResponse
    {
        $performance->load(['judgeScores.judge', 'category']);
        if (! SecretaryLiveUi::requiredScoresSubmitted($performance, $performance->category)) {
            return back()->withErrors(['approve' => 'Не все обязательные судейские оценки выставлены.']);
        }
        if (! SecretaryLiveUi::requiredManualAveragesSubmitted($performance, $performance->category)) {
            return back()->withErrors(['approve' => 'DB1 и DA1 ещё не отправили отдельные ручные средние.']);
        }

        $performance->approved_at = now();
        $performance->save();

        return back()->with('status', 'Выступление утверждено.');
    }

    public function publish(Performance $performance, Request $request): RedirectResponse
    {
        if (! $performance->approved_at) {
            return back()->with('status', 'Сначала нужно утвердить.');
        }

        $acceptedAt = now();
        $performance->published_at = $acceptedAt;
        $performance->scoreboard_accepted_at = $acceptedAt;
        $performance->scoreboard_accepted_by = $request->user()?->id;
        $performance->status = 'published';
        $performance->save();

        return back()->with('status', 'Опубликовано на табло.');
    }
}
