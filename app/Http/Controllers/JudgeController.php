<?php

namespace App\Http\Controllers;

use App\Events\ScoreUpdated;
use App\Models\Category;
use App\Models\JudgeScore;
use App\Models\Performance;
use App\Models\Tournament;
use App\Services\StreamAdvanceService;
use App\Support\SecretaryLiveUi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class JudgeController extends Controller
{
    /**
     * Судья выбирает только турнир; поток задаёт секретарь (active_category_id).
     */
    public function tournaments(): View
    {
        $tournaments = Tournament::query()
            ->with('activeCategory')
            ->withCount('categories')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('judge.tournaments', [
            'tournaments' => $tournaments,
        ]);
    }

    public function category(Category $category, Request $request): View
    {
        $panel = $request->user()->judgePanel();

        $performances = Performance::query()
            ->with(['athlete', 'inquiries' => function ($q) {
                $q->orderByDesc('id');
            }])
            ->where('category_id', $category->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        $myScores = JudgeScore::query()
            ->where('judge_id', $request->user()->id)
            ->whereIn('performance_id', $performances->pluck('id'))
            ->get()
            ->groupBy(fn ($s) => $s->performance_id);

        return view('judge.category', [
            'category' => $category,
            'performances' => $performances,
            'myScores' => $myScores,
            'panel' => $panel,
        ]);
    }

    /**
     * Планшет по турниру: текущий поток = тот, что ведёт секретарь в Live.
     */
    public function tournamentTablet(Tournament $tournament, Request $request): View
    {
        $category = $this->resolveJudgeCategoryForTournament($tournament);
        if ($category === null) {
            return view('judge.tablet-wait', ['tournament' => $tournament]);
        }

        return $this->renderTabletForCategory($tournament, $category, $request);
    }

    public function tournamentTabletSubmit(Tournament $tournament, Request $request): RedirectResponse
    {
        $category = $this->resolveJudgeCategoryForTournament($tournament);
        if ($category === null) {
            return redirect()->route('judge.tournament.tablet', $tournament)
                ->withErrors(['tablet' => 'Секретарь ещё не выбрал поток в Live для этого турнира.']);
        }

        $performances = Performance::query()
            ->where('category_id', $category->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();
        $ordered = SecretaryLiveUi::orderedPerformances($performances);
        $current = SecretaryLiveUi::currentPerformance($ordered);

        if (! $current) {
            return redirect()->route('judge.tournament.tablet', $tournament)
                ->withErrors(['tablet' => 'Нет активного выступления (scheduled / on_deck / performing).']);
        }

        $message = $this->saveJudgeScore($current, $request);

        return redirect()->route('judge.tournament.tablet', $tournament)->with('status', $message);
    }

    /**
     * Редирект со старой ссылки /judge/categories/{id}/tablet.
     */
    public function redirectCategoryTabletToTournament(Category $category): RedirectResponse
    {
        $category->loadMissing('tournament');
        if ($category->tournament === null) {
            abort(404);
        }

        return redirect()->route('judge.tournament.tablet', $category->tournament);
    }

    /**
     * Опрос для планшета: смена текущей гимнастки или потока секретарём (без WebSocket).
     */
    public function tournamentTabletPing(Tournament $tournament): JsonResponse
    {
        $category = $this->resolveJudgeCategoryForTournament($tournament);

        if ($category === null) {
            return response()->json([
                'resolved' => false,
                'category_id' => null,
                'performance_id' => null,
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        }

        $performances = Performance::query()
            ->where('category_id', $category->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();
        $ordered = SecretaryLiveUi::orderedPerformances($performances);
        $current = SecretaryLiveUi::currentPerformance($ordered);

        return response()->json([
            'resolved' => true,
            'category_id' => $category->id,
            'performance_id' => $current?->id,
            'performance_status' => $current?->status,
            'stream_status' => SecretaryLiveUi::streamStatus($current),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function submitScore(Performance $performance, Request $request): RedirectResponse
    {
        $message = $this->saveJudgeScore($performance, $request);

        return back()->with('status', $message);
    }

    /**
     * AJAX-сабмит со страницы планшета (route name: judge.submit-score).
     * Body: { tournament_id, panel?, subpanel?, penalty_type?, score }
     * Response: { ok, message?, score?, slot?, error?, redirect_url? }
     */
    public function submitScoreAjax(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tournament_id' => ['required', 'integer'],
            'score' => ['required', 'numeric', 'min:0', 'max:99.999'],
            'panel' => ['nullable', Rule::in(['d', 'a', 'e', 'penalty'])],
            'subpanel' => ['nullable', 'string', 'max:32'],
            'penalty_type' => ['nullable', 'string', 'max:32'],
        ]);

        $tournament = Tournament::query()->findOrFail($data['tournament_id']);
        $category = $this->resolveJudgeCategoryForTournament($tournament);
        if ($category === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Поток не выбран секретарём.',
            ], 422);
        }

        $performances = Performance::query()
            ->where('category_id', $category->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();
        $ordered = SecretaryLiveUi::orderedPerformances($performances);
        $current = SecretaryLiveUi::currentPerformance($ordered);

        if (! $current) {
            return response()->json([
                'ok' => false,
                'error' => 'Нет активного выступления.',
            ], 422);
        }

        $message = $this->saveJudgeScore($current, $request);

        $user = $request->user();

        return response()->json([
            'ok' => true,
            'message' => $message,
            'slot' => $user->slot,
            'score' => (float) $data['score'],
            'redirect_url' => route('judge.tournament.tablet', $tournament),
        ]);
    }

    /**
     * Сохранение оценки судьи (десктоп / планшет).
     */
    private function saveJudgeScore(Performance $performance, Request $request): string
    {
        $user = $request->user();
        $panel = $user->judgePanel();

        if (! $panel && ! $user->isAdmin()) {
            abort(403);
        }

        if ($user->isAdmin()) {
            $data = $request->validate([
                'panel' => ['required', Rule::in(['d', 'a', 'e', 'penalty'])],
                'score' => ['required', 'numeric', 'min:0', 'max:99.999'],
                'subpanel' => ['nullable', 'string', 'max:32'],
                'penalty_type' => ['nullable', 'string', 'max:32'],
            ]);
            $panelKey = $data['panel'];
            $subpanel = $data['subpanel'] ?: null;
            $penaltyType = $data['penalty_type'] ?: null;
            $score = (float) $data['score'];
        } else {
            $request->validate([
                'score' => ['required', 'numeric', 'min:0', 'max:99.999'],
            ]);
            $panelKey = $panel['panel'];
            $subpanel = $panel['subpanel'] ?? null;
            $penaltyType = $panel['penalty_type'] ?? null;
            $score = (float) $request->input('score');
        }

        JudgeScore::query()->updateOrCreate(
            [
                'performance_id' => $performance->id,
                'judge_id' => $user->id,
                'panel' => $panelKey,
                'subpanel' => $subpanel,
                'penalty_type' => $penaltyType,
            ],
            [
                'score' => $score,
                'submitted_at' => now(),
            ],
        );

        event(new ScoreUpdated($performance->id, $performance->category_id));

        $performance->refresh();
        $performance->load(['judgeScores', 'category']);

        $status = 'Оценка сохранена.';
        $category = $performance->category;

        if (
            $category?->auto_advance
            && $performance->status === 'performing'
            && SecretaryLiveUi::scoresCompleteForAutoAdvance($performance, $category)
        ) {
            $moved = false;
            DB::transaction(function () use ($performance, &$moved) {
                $performance->refresh();
                $performance->load(['judgeScores', 'category']);

                if (! SecretaryLiveUi::scoresCompleteForAutoAdvance($performance, $performance->category)) {
                    return;
                }

                $performance->recalculateTotals();
                $performance->finalized_at = now();
                $performance->save();

                $cat = $performance->category;
                if ($cat) {
                    $moved = StreamAdvanceService::advanceToNextInCategory($cat);
                }
            });

            $status .= $moved
                ? ' Автопереход: вызвана следующая гимнастка.'
                : ' Автопереход: очередь завершена (следующих нет).';
        }

        return $status;
    }

    public function finalize(Performance $performance): RedirectResponse
    {
        $performance->load('judgeScores');
        $performance->recalculateTotals();
        $performance->finalized_at = now();
        $performance->save();

        return back()->with('status', 'Итог посчитан.');
    }

    private function resolveJudgeCategoryForTournament(Tournament $tournament): ?Category
    {
        $tournament->loadMissing('activeCategory');

        if ($tournament->active_category_id) {
            $cat = Category::query()
                ->where('tournament_id', $tournament->id)
                ->where('id', $tournament->active_category_id)
                ->first();
            if ($cat) {
                return $cat;
            }
        }

        return Category::query()
            ->where('tournament_id', $tournament->id)
            ->whereHas('performances', fn ($q) => $q->whereIn('status', ['performing', 'on_deck']))
            ->orderBy('id')
            ->first();
    }

    private function renderTabletForCategory(Tournament $tournament, Category $category, Request $request): View
    {
        $user = $request->user();
        $panel = $user->judgePanel();

        if ($user->isAdmin()) {
            $panel = $panel ?? [
                'panel' => $request->query('panel', 'a'),
                'subpanel' => $request->query('subpanel') ?: null,
                'penalty_type' => $request->query('penalty_type') ?: null,
                'slot' => $request->query('slot') ?: null,
            ];
            if (($panel['panel'] ?? '') === 'd' && empty($panel['subpanel'])) {
                $panel['subpanel'] = $request->query('subpanel', 'db');
            }
            if (($panel['panel'] ?? '') === 'penalty' && empty($panel['penalty_type'])) {
                $panel['penalty_type'] = $request->query('penalty_type', 'line');
            }
            if (empty($panel['slot'])) {
                $panel['slot'] = match ($panel['panel'] ?? null) {
                    'd' => strtoupper(($panel['subpanel'] ?? 'db')).'1',
                    'a' => 'A1',
                    'e' => 'E1',
                    'penalty' => match ($panel['penalty_type'] ?? null) {
                        'time' => 'TIME',
                        'music' => 'RESP',
                        default => 'LINE1',
                    },
                    default => null,
                };
            }
        }

        if (! $panel) {
            abort(403, 'Планшет доступен ролям бригад (judge_d, judge_a, judge_e, judge_d_db, …).');
        }

        $category->loadMissing('tournament');

        $performances = Performance::query()
            ->with(['athlete', 'judgeScores' => function ($q) use ($user) {
                $q->where('judge_id', $user->id);
            }])
            ->where('category_id', $category->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        $ordered = SecretaryLiveUi::orderedPerformances($performances);
        $current = SecretaryLiveUi::currentPerformance($ordered);
        $streamStatus = SecretaryLiveUi::streamStatus($current);

        $myScore = null;
        if ($current) {
            $myScore = $current->judgeScores->first(function ($s) use ($user, $panel) {
                if ($s->judge_id !== $user->id || $s->panel !== $panel['panel']) {
                    return false;
                }
                if (($s->subpanel ?? null) !== ($panel['subpanel'] ?? null)) {
                    return false;
                }

                return ($s->penalty_type ?? null) === ($panel['penalty_type'] ?? null);
            });
        }

        $rules = $category->scoring_rules ?? [];
        $aBase = (float) ($rules['a_base'] ?? 10.0);
        $eBase = (float) ($rules['e_base'] ?? 10.0);

        return view('judge.tablet', [
            'tournament' => $tournament,
            'category' => $category,
            'current' => $current,
            'ordered' => $ordered,
            'streamStatus' => $streamStatus,
            'panel' => $panel,
            'myScore' => $myScore,
            'aBase' => $aBase,
            'eBase' => $eBase,
        ]);
    }
}
