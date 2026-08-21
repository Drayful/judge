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

        $current = $this->resolveJudgePerformance(
            $category,
            $request->user(),
            $this->activeSessionId($tournament, $category),
        );

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

        $user = request()->user();
        $current = $this->resolveJudgePerformance(
            $category,
            $user,
            $this->activeSessionId($tournament, $category),
        );
        $panel = $user?->judgePanel();
        $myScore = ($current && $panel)
            ? $this->findMyScore($current, $user, $this->effectiveJudgePanel($current, $panel))
            : null;

        return response()->json([
            'resolved' => true,
            'rev' => $this->judgeTabletRevision($tournament, $category, $current, $myScore, $user),
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
        // Фиксируем момент команды до проверок, блокировок и записи в БД,
        // чтобы их длительность не добавлялась к официальному времени.
        $timerActionAt = now();
        $user = $request->user();
        $panel = $user->judgePanel();

        if (! $user->isAdmin() && (($panel['panel'] ?? null) !== 'penalty' || ($panel['penalty_type'] ?? null) !== 'time')) {
            abort(403);
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['start', 'stop'])],
            'performance_id' => ['required', 'integer', 'min:1'],
        ]);

        $category = $this->resolveJudgeCategoryForTournament($tournament);
        if ($category === null) {
            return response()->json(['ok' => false, 'error' => 'Секретарь ещё не выбрал поток в Live.'], 422);
        }
        if (! $user->isAdmin() && SecretaryLiveUi::isSlotInactive($category, 'TIME')) {
            return response()->json(['ok' => false, 'error' => 'Слот TIME отключён секретарём для этого потока.'], 422);
        }

        $sessionId = $this->activeSessionId($tournament, $category);
        $current = $this->resolveJudgePerformance($category, $user, $sessionId);

        if ($current === null
            || ($current->status !== 'performing' && $current->timer_revision_requested_at === null)) {
            return response()->json(['ok' => false, 'error' => 'Сейчас нет выступления, для которого можно зафиксировать время.'], 422);
        }
        if ((int) $data['performance_id'] !== (int) $current->id) {
            return response()->json([
                'ok' => false,
                'error' => 'Выступление уже сменилось. Обновите планшет и повторите действие для текущей гимнастки.',
            ], 409);
        }

        DB::transaction(function () use (&$current, $data, $timerActionAt, $tournament, $user) {
            $lockedTournament = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            $current = Performance::query()->lockForUpdate()->findOrFail($current->id);
            $category = Category::query()->lockForUpdate()->findOrFail($current->category_id);
            $isRevision = $current->timer_revision_requested_at !== null;

            if ((int) ($lockedTournament->active_category_id ?? 0) !== (int) $current->category_id
                || (! $isRevision
                    && $this->normalizedSessionId($lockedTournament->active_stream_session_id)
                        !== $this->normalizedSessionId($current->stream_session_id))) {
                abort(409, 'Активный поток или сессия уже изменились. Обновите планшет.');
            }
            if (! $user->isAdmin() && SecretaryLiveUi::isSlotInactive($category, 'TIME')) {
                abort(422, 'Слот TIME отключён секретарём для этого потока.');
            }
            if ($current->status !== 'performing' && ! $isRevision) {
                abort(422, 'Это выступление больше не доступно хронометристу.');
            }

            if ($data['action'] === 'start') {
                if ($current->timer_ended_at !== null) {
                    abort(422, 'Время этого выступления уже зафиксировано.');
                }

                if ($current->timer_started_at === null) {
                    $current->startOfficialTimer($timerActionAt);
                    $current->save();
                }

                return;
            }

            if ($current->timer_ended_at === null && ! $current->stopOfficialTimer($timerActionAt)) {
                abort(422, 'Сначала нажмите «Старт».');
            }

            $current->timer_revision_requested_at = null;
            $current->load(['judgeScores.judge', 'category']);
            $current->recalculateTotals();
            if ($isRevision && SecretaryLiveUi::readyToFinalize($current, $current->category)) {
                $current->finalized_at = now();
            }
            $current->save();

            if (! $isRevision) {
                $this->finalizeAndAdvanceIfReady($current);
            }
        });

        event(new ScoreUpdated($current->id, $current->category_id));

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
        $performance->loadMissing('category.tournament');
        $category = $performance->category;
        $tournament = $category?->tournament;
        abort_unless($category !== null && $tournament !== null, 404);

        $activeCategory = $this->resolveJudgeCategoryForTournament($tournament);
        $activeSessionId = $activeCategory ? $this->activeSessionId($tournament, $activeCategory) : null;
        if ($activeCategory?->id !== $category->id
            || $this->normalizedSessionId($performance->stream_session_id) !== $this->normalizedSessionId($activeSessionId)) {
            abort(422, 'Это выступление не относится к активному потоку и сессии секретаря.');
        }

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

        $current = $this->resolveJudgePerformance(
            $category,
            $request->user(),
            $this->activeSessionId($tournament, $category),
        );

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

        $current = $this->resolveJudgePerformance(
            $category,
            $request->user(),
            $this->activeSessionId($tournament, $category),
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
        if (! $user->isAdmin() && SecretaryLiveUi::isSlotInactive($category, $slot)) {
            abort(422, 'Ваш судейский слот отключён секретарём для этого потока.');
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

        $score = null;
        $moved = null;
        DB::transaction(function () use (&$current, &$score, &$moved, $user, $panel, $data) {
            $current = Performance::query()->lockForUpdate()->findOrFail($current->id);
            $score = $this->findMyScore($current, $user, $panel);
            if ($score === null || $score->submitted_at === null) {
                abort(422, 'Сначала отправьте основную оценку.');
            }
            if ($current->finalized_at !== null || $current->approved_at !== null || $current->published_at !== null) {
                abort(422, 'Зафиксированный результат нельзя изменять без возврата на доработку.');
            }

            $score->average_score = round((float) $data['average_score'], 3);
            $score->average_submitted_at = now();
            $score->save();

            $current->unsetRelation('judgeScores');
            $current->load(['judgeScores.judge', 'category']);
            $current->recalculateTotals();
            $current->save();
            $moved = $this->finalizeAndAdvanceIfReady($current);
        });

        event(new ScoreUpdated($current->id, $current->category_id));

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

        $current = $this->resolveJudgePerformance(
            $activeCategory,
            $user,
            $this->activeSessionId($category->tournament, $activeCategory),
        );
        if ($current?->id !== $performance->id) {
            abort(422, 'Это выступление больше не открыто на планшете судьи.');
        }

        $slot = strtoupper((string) ($panel['slot'] ?? $user->slot ?? ''));
        if (! $user->isAdmin() && $slot !== '' && SecretaryLiveUi::isSlotInactive($category, $slot)) {
            abort(422, 'Ваш судейский слот отключён секретарём для этого потока.');
        }

        $rawEntries = $request->input('entries');
        if (is_string($rawEntries) && $rawEntries !== '') {
            $decodedEntries = json_decode($rawEntries, true);
            if (is_array($decodedEntries)) {
                $request->merge(['entries' => $decodedEntries]);
            }
        }

        $data = $request->validate([
            'action' => ['required', 'string', 'max:120'],
            'draft_score' => ['nullable', 'numeric', 'min:0', 'max:99.999'],
            'entries' => ['nullable', 'array', 'max:120'],
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
            'entries' => isset($data['entries']) ? array_slice($data['entries'], 0, 120) : null,
            'age_group' => $data['age_group'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Сохранение оценки судьи (десктоп / планшет).
     */
    private function saveJudgeScore(Performance $performance, Request $request): string
    {
        return DB::transaction(function () use ($performance, $request) {
            $locked = Performance::query()
                ->lockForUpdate()
                ->findOrFail($performance->id);

            return $this->saveJudgeScoreLocked($locked, $request);
        });
    }

    private function saveJudgeScoreLocked(Performance $performance, Request $request): string
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

        if (in_array($panelKey, ['a', 'e'], true) && $score > 10.0) {
            abort(422, 'Оценка бригад A и E должна быть в диапазоне от 0 до 10 баллов.');
        }
        $performance->loadMissing('category.tournament');
        $slot = strtoupper((string) ($panel['slot'] ?? $user->slot ?? ''));
        if (! $user->isAdmin()
            && $slot !== ''
            && SecretaryLiveUi::isSlotInactive($performance->category, $slot)) {
            abort(422, 'Ваш судейский слот отключён секретарём для этого потока.');
        }

        $existingScore = JudgeScore::query()
            ->where('performance_id', $performance->id)
            ->where('judge_id', $user->id)
            ->where('panel', $panelKey)
            ->where('subpanel', $subpanel)
            ->where('penalty_type', $penaltyType)
            ->first();
        $isReturnedForRevision = $existingScore !== null && $existingScore->submitted_at === null;

        $category = $performance->category;
        $tournament = $category?->tournament;
        if (! $isReturnedForRevision
            && ($tournament === null
                || (int) ($tournament->active_category_id ?? 0) !== (int) $performance->category_id
                || $this->normalizedSessionId($tournament->active_stream_session_id)
                    !== $this->normalizedSessionId($performance->stream_session_id))) {
            abort(422, 'Это выступление больше не относится к активному потоку и сессии секретаря.');
        }

        if ($performance->status !== 'performing' && ! $isReturnedForRevision) {
            abort(422, 'Оценку можно отправить только для текущего выступления или после возврата на доработку.');
        }

        if (($performance->approved_at !== null || $performance->published_at !== null) && ! $isReturnedForRevision) {
            abort(422, 'Утверждённый или опубликованный результат нельзя изменять без возврата на доработку.');
        }

        // История нажатий с планшета (для просмотра секретарём / главным судьёй).
        $entries = null;
        $rawEntries = $request->input('entries');
        if (is_array($rawEntries)) {
            $entries = array_slice($rawEntries, 0, 120);
        } elseif (is_string($rawEntries) && $rawEntries !== '') {
            $decoded = json_decode($rawEntries, true);
            if (is_array($decoded)) {
                $entries = array_slice($decoded, 0, 120);
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
                $status .= ' Разброс > '.$report['max_spread'].' ('.$labels.'). Автопереход не блокируется; предупреждение сохранено для секретаря и главного судьи.';
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
        $moved = false;
        $finalized = false;
        DB::transaction(function () use ($performance, &$moved, &$finalized) {
            $locked = Performance::query()->lockForUpdate()->findOrFail($performance->id);
            $locked->load(['judgeScores.judge', 'category']);
            $locked->recalculateTotals();

            if ($locked->status !== 'performing'
                || ! SecretaryLiveUi::readyToFinalize($locked, $locked->category)) {
                return;
            }

            $locked->finalized_at = now();
            $locked->save();
            $finalized = true;

            if ($locked->category) {
                $moved = StreamAdvanceService::advanceToNextInCategory(
                    $locked->category,
                    $locked->stream_session_id,
                );
            }
        });

        return $finalized ? $moved : null;
    }

    public function finalize(Performance $performance): RedirectResponse
    {
        $performance->load(['judgeScores.judge', 'category']);
        $performance->recalculateTotals();

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

        if (! SecretaryLiveUi::requiredPenaltyInputsSubmitted($performance, $performance->category)) {
            return back()->withErrors([
                'finalize' => 'Не все активные штрафные позиции (LINE/TIME/RESP) завершили работу.',
            ]);
        }

        if ($performance->total === null) {
            return back()->withErrors(['finalize' => 'Итог не рассчитан: проверьте состав активных панелей и оценки.']);
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

        return null;
    }

    /**
     * Возвращённая конкретному судье оценка имеет приоритет над текущим выходом.
     * Для DB1/DA1 доработка остаётся активной и на втором шаге ручной средней.
     */
    private function resolveJudgePerformance(Category $category, $user, ?int $sessionId): ?Performance
    {
        if ($user !== null && ! $user->isAdmin()) {
            $slot = strtoupper((string) ($user->slot ?? ''));
            $needsManualAverage = in_array($slot, SecretaryLiveUi::MANUAL_AVERAGE_SLOTS, true);

            if ($slot === 'TIME') {
                $timerRevision = Performance::query()
                    ->where('category_id', $category->id)
                    ->whereNotNull('timer_revision_requested_at')
                    ->orderByDesc('timer_revision_requested_at')
                    ->orderByDesc('id')
                    ->first();

                if ($timerRevision !== null) {
                    return $timerRevision;
                }
            }

            $revision = Performance::query()
                ->where('category_id', $category->id)
                ->whereNull('finalized_at')
                ->whereHas('judgeScores', function ($query) use ($user, $needsManualAverage) {
                    $query->where('judge_id', $user->id)
                        ->where(function ($state) use ($needsManualAverage) {
                            $state->whereNull('submitted_at');
                            if ($needsManualAverage) {
                                $state->orWhere(function ($average) {
                                    $average->whereNotNull('submitted_at')
                                        ->whereNull('average_submitted_at');
                                });
                            }
                        });
                })
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();

            if ($revision !== null) {
                return $revision;
            }
        }

        $performances = Performance::query()
            ->where('category_id', $category->id)
            ->when(
                $sessionId !== null,
                fn ($query) => $query->where('stream_session_id', $sessionId),
                fn ($query) => $query->whereNull('stream_session_id'),
            )
            ->where('status', 'performing')
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        return SecretaryLiveUi::currentPerformance(SecretaryLiveUi::orderedPerformances($performances));
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
        $current = $this->resolveJudgePerformance(
            $category,
            $user,
            $this->activeSessionId($tournament, $category),
        );
        $streamStatus = SecretaryLiveUi::streamStatus($current);

        if ($current) {
            $panel = $this->effectiveJudgePanel($current, $panel);
        }

        $myScore = $current ? $this->findMyScore($current, $user, $panel) : null;
        $slotInactive = ! $user->isAdmin()
            && ! empty($panel['slot'])
            && SecretaryLiveUi::isSlotInactive($category, (string) $panel['slot']);

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
            'slotInactive' => $slotInactive,
            'tabletRev' => $this->judgeTabletRevision($tournament, $category, $current, $myScore, $user),
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

    private function activeSessionId(Tournament $tournament, Category $category): ?int
    {
        $sessionId = $tournament->active_stream_session_id;
        if ($sessionId === null) {
            return null;
        }

        return $category->sessions()->whereKey((int) $sessionId)->exists()
            ? (int) $sessionId
            : null;
    }

    private function normalizedSessionId(mixed $sessionId): ?int
    {
        return $sessionId === null ? null : (int) $sessionId;
    }

    private function judgeTabletRevision(
        Tournament $tournament,
        Category $category,
        ?Performance $performance,
        ?JudgeScore $score,
        $user,
    ): string {
        $slot = strtoupper((string) ($user?->slot ?? ''));

        return md5(json_encode([
            'category' => $category->id,
            'session' => $tournament->active_stream_session_id,
            'inactive_slots' => $category->inactiveJudgeSlotList(),
            'slot_inactive' => $slot !== '' && SecretaryLiveUi::isSlotInactive($category, $slot),
            'performance' => $performance?->only([
                'id',
                'status',
                'finalized_at',
                'approved_at',
                'published_at',
                'timer_started_at',
                'timer_ended_at',
                'timer_revision_requested_at',
                'actual_duration_seconds',
                'time_penalty',
            ]),
            'score' => $score?->only([
                'id',
                'score',
                'average_score',
                'submitted_at',
                'average_submitted_at',
                'updated_at',
            ]),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
