<?php

namespace App\Http\Controllers;

use App\Events\ScoreUpdated;
use App\Models\Category;
use App\Models\JudgeScore;
use App\Models\JudgeScoreAction;
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

        $user = request()->user();
        $panel = $user?->judgePanel();
        $myScore = ($current && $panel)
            ? $this->findMyScore($current, $user, $this->effectiveJudgePanel($current, $panel))
            : null;

        return response()->json([
            'resolved' => true,
            'category_id' => $category->id,
            'performance_id' => $current?->id,
            'performance_status' => $current?->status,
            'stream_status' => SecretaryLiveUi::streamStatus($current),
            'score_submitted' => $myScore !== null && $myScore->submitted_at !== null,
            'average_submitted' => $myScore !== null
                && $myScore->average_submitted_at !== null
                && $myScore->average_score !== null,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Официальное время фиксирует только судья-хронометрист со своего планшета.
     * Время очереди секретаря и воспроизведение музыки на этот расчёт не влияют.
     */
    public function recordOfficialTimer(Tournament $tournament, Request $request): JsonResponse
    {
        $user = $request->user();
        $panel = $user->judgePanel();

        if (! $user->isAdmin() && (($panel['panel'] ?? null) !== 'penalty' || ($panel['penalty_type'] ?? null) !== 'time')) {
            abort(403);
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['start', 'stop'])],
        ]);

        $category = $this->resolveJudgeCategoryForTournament($tournament);
        if ($category === null) {
            return response()->json(['ok' => false, 'error' => 'Секретарь ещё не выбрал поток в Live.'], 422);
        }

        $performances = Performance::query()
            ->with('category')
            ->where('category_id', $category->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();
        $current = SecretaryLiveUi::currentPerformance(SecretaryLiveUi::orderedPerformances($performances));

        if ($current === null || $current->status !== 'performing') {
            return response()->json(['ok' => false, 'error' => 'Сейчас нет выступления, для которого можно зафиксировать время.'], 422);
        }

        if ($data['action'] === 'start') {
            if ($current->timer_ended_at !== null) {
                return response()->json(['ok' => false, 'error' => 'Время этого выступления уже зафиксировано.'], 422);
            }

            if ($current->timer_started_at === null) {
                $current->startOfficialTimer();
                $current->save();
                event(new ScoreUpdated($current->id, $current->category_id));
            }
        } else {
            if ($current->timer_ended_at === null && ! $current->stopOfficialTimer()) {
                return response()->json(['ok' => false, 'error' => 'Сначала нажмите «Старт».'], 422);
            }

            $current->recalculateTotals();
            $current->save();

            if (SecretaryLiveUi::readyToFinalize($current, $current->category)) {
                $current->finalized_at = now();
                $current->save();
                StreamAdvanceService::advanceToNextInCategory($current->category, $current->stream_session_id);
            }

            event(new ScoreUpdated($current->id, $current->category_id));
        }

        return response()->json([
            'ok' => true,
            'action' => $data['action'],
            'timer_started_at' => $current->timer_started_at?->toIso8601String(),
            'timer_ended_at' => $current->timer_ended_at?->toIso8601String(),
            'duration_seconds' => $current->actual_duration_seconds,
            'time_penalty' => (float) ($current->time_penalty ?? 0),
        ]);
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
     * Второй этап для DB1/DA1: ручной ввод согласованной средней подпанели.
     */
    public function submitAverageAjax(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tournament_id' => ['required', 'integer'],
            'average_score' => ['required', 'numeric', 'min:0', 'max:99.999'],
            'slot' => ['nullable', Rule::in(SecretaryLiveUi::MANUAL_AVERAGE_SLOTS)],
        ]);

        $tournament = Tournament::query()->findOrFail($data['tournament_id']);
        $category = $this->resolveJudgeCategoryForTournament($tournament);
        if ($category === null) {
            return response()->json(['ok' => false, 'error' => 'Поток не выбран секретарём.'], 422);
        }

        $current = SecretaryLiveUi::currentPerformance(
            SecretaryLiveUi::orderedPerformances(
                Performance::query()
                    ->where('category_id', $category->id)
                    ->orderBy('order_index')
                    ->orderBy('id')
                    ->get()
            )
        );
        if ($current === null) {
            return response()->json(['ok' => false, 'error' => 'Нет активного выступления.'], 422);
        }

        $user = $request->user();
        $panel = $user->judgePanel();
        $slot = strtoupper((string) ($user->isAdmin() ? ($data['slot'] ?? '') : ($user->slot ?? '')));
        if (! in_array($slot, SecretaryLiveUi::MANUAL_AVERAGE_SLOTS, true)) {
            abort(403, 'Ручную среднюю могут выставлять только DB1 и DA1.');
        }
        if (! $user->isAdmin() && (($panel['panel'] ?? null) !== 'd')) {
            abort(403);
        }

        if ($user->isAdmin()) {
            $panel = [
                'panel' => 'd',
                'subpanel' => $slot === 'DB1' ? 'db' : 'da',
                'penalty_type' => null,
                'slot' => $slot,
            ];
        } else {
            $panel = $this->effectiveJudgePanel($current, $panel);
        }

        $score = $this->findMyScore($current, $user, $panel);
        if ($score === null || $score->submitted_at === null) {
            return response()->json(['ok' => false, 'error' => 'Сначала отправьте основную оценку.'], 422);
        }

        $score->average_score = round((float) $data['average_score'], 3);
        $score->average_submitted_at = now();
        $score->save();

        $current->refresh();
        $current->load(['judgeScores.judge', 'category']);
        $current->recalculateTotals();
        $current->save();
        event(new ScoreUpdated($current->id, $current->category_id));

        $moved = $this->finalizeAndAdvanceIfReady($current);
        $message = 'Ручная средняя '.$slot.' сохранена.';
        if ($moved !== null) {
            $message .= $moved
                ? ' Автопереход: вызвана следующая гимнастка.'
                : ' Автопереход: очередь завершена.';
        }

        return response()->json([
            'ok' => true,
            'message' => $message,
            'average_score' => (float) $score->average_score,
            'redirect_url' => route('judge.tournament.tablet', $tournament),
        ]);
    }

    /**
     * Промежуточное действие на планшете судьи. Это отдельный неизменяемый журнал:
     * он не создаёт финальную оценку и не влияет на подсчёт до нажатия «Отправить».
     */
    public function recordLiveAction(Performance $performance, Request $request): JsonResponse
    {
        $user = $request->user();
        $panel = $user->judgePanel();
        if (! $panel && ! $user->isAdmin()) {
            abort(403);
        }

        $performance->loadMissing('category.tournament');
        $category = $performance->category;
        if ($category === null || $category->tournament === null) {
            abort(404);
        }

        $activeCategory = $this->resolveJudgeCategoryForTournament($category->tournament);
        if ($activeCategory === null || $activeCategory->id !== $category->id) {
            abort(422, 'Этот поток сейчас не открыт для судей.');
        }

        $data = $request->validate([
            'action' => ['required', 'string', 'max:120'],
            'draft_score' => ['nullable', 'numeric', 'min:0', 'max:99.999'],
            'entries' => ['nullable', 'array', 'max:60'],
            'age_group' => ['nullable', Rule::in(['junior', 'senior'])],
        ]);

        $effectivePanel = $user->isAdmin()
            ? ['panel' => 'd', 'subpanel' => null, 'penalty_type' => null, 'slot' => $user->slot]
            : $this->effectiveJudgePanel($performance, $panel);

        JudgeScoreAction::query()->create([
            'performance_id' => $performance->id,
            'judge_id' => $user->id,
            'slot' => $effectivePanel['slot'] ?? $user->slot,
            'panel' => $effectivePanel['panel'],
            'subpanel' => $effectivePanel['subpanel'] ?? null,
            'penalty_type' => $effectivePanel['penalty_type'] ?? null,
            'action' => $data['action'],
            'draft_score' => $data['draft_score'] ?? null,
            'entries' => isset($data['entries']) ? array_slice($data['entries'], 0, 60) : null,
            'age_group' => $data['age_group'] ?? null,
        ]);

        return response()->json(['ok' => true]);
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
            $data = $request->validate([
                'score' => ['required', 'numeric', 'min:0', 'max:99.999'],
                'penalty_type' => ['nullable', 'string', 'max:32'],
            ]);
            $panel = $this->effectiveJudgePanel($performance, $panel);
            $panelKey = $panel['panel'];
            $subpanel = $panel['subpanel'] ?? null;
            $penaltyType = $panel['penalty_type'] ?? null;
            $score = (float) $request->input('score');
        }

        // История нажатий с планшета (для просмотра секретарём / главным судьёй).
        $entries = null;
        $rawEntries = $request->input('entries');
        if (is_string($rawEntries) && $rawEntries !== '') {
            $decoded = json_decode($rawEntries, true);
            if (is_array($decoded)) {
                $entries = array_slice($decoded, 0, 60);
            }
        }
        $ageGroup = $request->input('age_group');
        $ageGroup = in_array($ageGroup, ['junior', 'senior'], true) ? $ageGroup : null;

        if ($panelKey === 'penalty' && $penaltyType === 'line') {
            // Старые версии планшета сохраняли два типа отдельными строками.
            // После отправки общей суммы оставляем их в истории, но исключаем из расчёта.
            JudgeScore::query()
                ->where('performance_id', $performance->id)
                ->where('judge_id', $user->id)
                ->where('panel', 'penalty')
                ->whereIn('penalty_type', ['line_gymnast', 'line_ball'])
                ->update([
                    'submitted_at' => null,
                ]);
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
                'average_score' => null,
                'entries' => $entries,
                'age_group' => $ageGroup,
                'submitted_at' => now(),
                'average_submitted_at' => null,
            ],
        );

        if ($panelKey === 'd' && in_array($subpanel, ['db', 'da'], true)) {
            $performance->unsetRelation('judgeScores');
            $performance->loadMissing(['judgeScores.judge', 'category']);
            $rows = SecretaryLiveUi::scoreRowsBySlot($performance, $performance->category);
            $leader = $rows[strtoupper($subpanel).'1'] ?? null;
            if ($leader !== null) {
                $leader->update(['average_score' => null, 'average_submitted_at' => null]);
            }
        }

        $performance->refresh();
        $performance->load(['judgeScores', 'category']);
        // DB1/DA1 и последующие оценки сразу пересчитывают и сохраняют средние.
        // На табло этот промежуточный результат не попадёт: он ждёт двух подтверждений.
        $performance->recalculateTotals();
        $performance->save();

        event(new ScoreUpdated($performance->id, $performance->category_id));

        $status = $panelKey === 'penalty' && $penaltyType === 'line'
            ? 'Сумма линейной сбавки сохранена.'
            : 'Оценка сохранена.';
        $category = $performance->category;

        if ($category && SecretaryLiveUi::requiredScoresSubmitted($performance, $category)) {
            if (SecretaryLiveUi::hasPanelSpreadViolation($performance, $category)) {
                $report = SecretaryLiveUi::panelSpreadReport($performance, $category);
                $labels = collect($report['violations'])->pluck('label')->implode(', ');
                $status .= ' Разброс > '.$report['max_spread'].' ('.$labels.'). Оценка принята — итог подтверждает секретарь или главный судья.';
            }
        }

        $moved = $this->finalizeAndAdvanceIfReady($performance);
        if ($moved !== null) {
            $status .= $moved
                ? ' Автопереход: вызвана следующая гимнастка.'
                : ' Автопереход: очередь завершена (следующих нет).';
        }

        return $status;
    }

    /**
     * null — выступление ещё не готово; bool — готово и попытка перехода выполнена.
     */
    private function finalizeAndAdvanceIfReady(Performance $performance): ?bool
    {
        $performance->loadMissing(['judgeScores.judge', 'category']);
        if ($performance->status !== 'performing'
            || ! SecretaryLiveUi::readyToFinalize($performance, $performance->category)) {
            return null;
        }

        $moved = false;
        $finalized = false;
        DB::transaction(function () use ($performance, &$moved, &$finalized) {
            $performance->refresh();
            $performance->load(['judgeScores.judge', 'category']);

            if (! SecretaryLiveUi::readyToFinalize($performance, $performance->category)) {
                return;
            }

            $performance->recalculateTotals();
            $performance->finalized_at = now();
            $performance->save();
            $finalized = true;

            if ($performance->category) {
                $moved = StreamAdvanceService::advanceToNextInCategory(
                    $performance->category,
                    $performance->stream_session_id,
                );
            }
        });

        return $finalized ? $moved : null;
    }

    public function finalize(Performance $performance): RedirectResponse
    {
        $performance->load(['judgeScores.judge', 'category']);

        if (! SecretaryLiveUi::requiredScoresSubmitted($performance, $performance->category)) {
            return back()->withErrors([
                'finalize' => 'Не все обязательные судейские оценки выставлены.',
            ]);
        }

        if (! SecretaryLiveUi::requiredManualAveragesSubmitted($performance, $performance->category)) {
            return back()->withErrors([
                'finalize' => 'DB1 и DA1 ещё не отправили отдельные ручные средние.',
            ]);
        }

        if (SecretaryLiveUi::hasPanelSpreadViolation($performance, $performance->category)) {
            $report = SecretaryLiveUi::panelSpreadReport($performance, $performance->category);
            $labels = collect($report['violations'])->pluck('label')->implode(', ');

            return back()->withErrors([
                'finalize' => 'Нельзя зафиксировать итог: разброс оценок > '.$report['max_spread'].' ('.$labels.'). Нужна конференция судей.',
            ]);
        }

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

        if ($current) {
            $panel = $this->effectiveJudgePanel($current, $panel);
        }

        $myScore = $current ? $this->findMyScore($current, $user, $panel) : null;

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

    /**
     * БП (без предмета): DA-судьи выставляют оценку на планшете DB (subpanel=db).
     */
    private function effectiveJudgePanel(Performance $performance, array $panel): array
    {
        if (($panel['panel'] ?? null) === 'd'
            && ($panel['subpanel'] ?? null) === 'da'
            && $performance->isBodyOnlyApparatus()) {
            return array_merge($panel, ['subpanel' => 'db']);
        }

        return $panel;
    }

    /**
     * Оценка текущего судьи для выступления (включая черновик после возврата на доработку).
     */
    private function findMyScore(Performance $performance, $user, array $panel): ?JudgeScore
    {
        $performance->loadMissing(['judgeScores' => fn ($q) => $q->where('judge_id', $user->id)]);

        return $performance->judgeScores->first(function ($s) use ($user, $panel) {
            if ($s->judge_id !== $user->id || $s->panel !== $panel['panel']) {
                return false;
            }
            if (($s->subpanel ?? null) !== ($panel['subpanel'] ?? null)) {
                return false;
            }

            return ($s->penalty_type ?? null) === ($panel['penalty_type'] ?? null);
        });
    }
}
