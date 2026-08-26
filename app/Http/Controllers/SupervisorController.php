<?php

namespace App\Http\Controllers;

use App\Models\Performance;
use App\Support\SecretaryLiveUi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupervisorController extends Controller
{
    public function approve(Performance $performance): RedirectResponse
    {
        $error = null;
        DB::transaction(function () use ($performance, &$error) {
            $locked = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            $locked->load(['judgeScores.judge', 'category']);
            $locked->recalculateTotals();

            if (! $locked->scores_overridden) {
                if (! SecretaryLiveUi::requiredScoresSubmitted($locked, $locked->category)) {
                    $error = 'Не все обязательные судейские оценки выставлены.';

                    return;
                }
                if (! SecretaryLiveUi::requiredManualAveragesSubmitted($locked, $locked->category)) {
                    $error = 'Планшеты средней DB и DA ещё не отправили официальные значения.';

                    return;
                }
                if (! SecretaryLiveUi::requiredPenaltyInputsSubmitted($locked, $locked->category)) {
                    $error = 'Не все активные штрафные позиции (LINE/TIME/RESP) завершили работу.';

                    return;
                }
                if ($locked->timer_started_at !== null && $locked->timer_ended_at === null) {
                    $error = 'Официальный таймер ещё не остановлен.';

                    return;
                }
                if (SecretaryLiveUi::hasPanelSpreadViolation($locked, $locked->category)) {
                    $error = 'Есть превышение допустимого разброса оценок; сначала требуется подтверждение секретаря или главного судьи.';

                    return;
                }
            }

            if ($locked->finalized_at === null || $locked->total === null) {
                $error = 'Результат ещё не финализирован или итог не рассчитан.';

                return;
            }

            $locked->approved_at = now();
            $locked->save();
        });

        if ($error !== null) {
            return back()->withErrors(['approve' => $error]);
        }

        return back()->with('status', 'Выступление утверждено.');
    }

    public function publish(Performance $performance, Request $request): RedirectResponse
    {
        $published = DB::transaction(function () use ($performance, $request) {
            $locked = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            if ($locked->approved_at === null || $locked->total === null || $locked->isWithdrawn()) {
                return false;
            }

            $acceptedAt = now();
            $locked->published_at = $acceptedAt;
            $locked->scoreboard_accepted_at = $acceptedAt;
            $locked->scoreboard_accepted_by = $request->user()?->id;
            $locked->status = 'published';
            $locked->save();

            return true;
        });

        if (! $published) {
            return back()->with('status', 'Сначала нужно утвердить корректный результат.');
        }

        return back()->with('status', 'Опубликовано на табло.');
    }
}
