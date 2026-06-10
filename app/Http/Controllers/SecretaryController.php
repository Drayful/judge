<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\JudgeScore;
use App\Models\MusicTrack;
use App\Models\Performance;
use App\Models\Tournament;
use App\Services\FinalProtocolExporter;
use App\Services\FinalProtocolService;
use App\Services\MusicTrackUploadService;
use App\Services\StartProtocolImportService;
use App\Services\StreamAdvanceService;
use App\Support\SecretaryLiveUi;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecretaryController extends Controller
{
    public function tournaments(): View
    {
        $tournaments = Tournament::query()
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('secretary.tournaments', [
            'tournaments' => $tournaments,
        ]);
    }

    public function storeTournament(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $tournament = Tournament::query()->create([
            'name' => $data['name'],
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
            'timezone' => $data['timezone'] ?: 'Asia/Almaty',
            'is_published' => (bool) ($data['is_published'] ?? false),
        ]);

        return redirect()->route('secretary.tournament', $tournament)
            ->with('status', 'Турнир создан.');
    }

    public function tournament(Tournament $tournament, FinalProtocolService $protocols): View
    {
        $tournament->load(['categories' => function ($q) {
            $q->orderByDesc('id');
        }]);

        $athletes = Athlete::query()
            ->select('athletes.*')
            ->join('performances', 'performances.athlete_id', '=', 'athletes.id')
            ->join('categories', 'categories.id', '=', 'performances.category_id')
            ->where('categories.tournament_id', $tournament->id)
            ->distinct()
            ->orderBy('athletes.last_name')
            ->orderBy('athletes.first_name')
            ->limit(500)
            ->get();

        return view('secretary.tournament', [
            'tournament' => $tournament,
            'athletes' => $athletes,
            'protocolGroups' => $protocols->groups($tournament),
        ]);
    }

    /**
     * Скачать итоговый протокол одной группы (год рождения + категория) в XLSX.
     */
    public function downloadProtocol(
        Request $request,
        Tournament $tournament,
        FinalProtocolService $protocols,
        FinalProtocolExporter $exporter,
    ): StreamedResponse {
        $data = $request->validate([
            'birth_year' => ['nullable', 'integer'],
            'division' => ['nullable', 'string', 'max:16'],
        ]);

        $birthYear = isset($data['birth_year']) ? (int) $data['birth_year'] : null;
        $division = $data['division'] ?? null;

        $built = $protocols->build($tournament, $birthYear, $division);

        if ($built['rows'] === []) {
            abort(404, 'Нет завершённых результатов для этой категории.');
        }

        $spreadsheet = $exporter->build($tournament, $built);

        $fileName = 'protocol_'.$tournament->id.'_'
            .($birthYear ?? 'na').'_'
            .($division !== null && $division !== '' ? strtoupper($division) : 'na')
            .'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new XlsxWriter($spreadsheet);
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function importStartProtocol(Request $request, Tournament $tournament, StartProtocolImportService $importer): RedirectResponse
    {
        $request->validate([
            'protocol' => ['required', Rule::file()->max(15360)->extensions(['xls', 'xlsx'])],
        ], [
            'protocol.required' => 'Выберите файл стартового протокола.',
            'protocol.extensions' => 'Допустимые расширения: .xls, .xlsx.',
        ]);

        $path = $request->file('protocol')->getRealPath();
        if ($path === false) {
            return back()->withErrors(['protocol' => 'Не удалось прочитать загруженный файл.']);
        }

        try {
            $stats = $importer->importFromPath($tournament, $path);
        } catch (\Throwable $e) {
            return back()->withErrors(['protocol' => $e->getMessage()]);
        }

        $message = sprintf(
            'Импорт завершён: категорий создано %d, переиспользовано %d; участниц добавлено в базу %d; выходов в очередях %d; строк пропущено %d.',
            $stats['categories_created'],
            $stats['categories_reused'],
            $stats['athletes_created'],
            $stats['performances_created'],
            $stats['rows_skipped'],
        );

        return back()->with('status', $message);
    }

    public function storeCategory(Request $request, Tournament $tournament): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'program' => ['required', 'string', 'in:individual,group'],
            'apparatus' => ['nullable', 'string', 'max:64'],
            'age_min' => ['nullable', 'integer', 'min:0', 'max:150'],
            'age_max' => ['nullable', 'integer', 'min:0', 'max:150'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $category = Category::query()->create([
            'tournament_id' => $tournament->id,
            'name' => $data['name'],
            'program' => $data['program'],
            'apparatus' => $data['apparatus'] ?? null,
            'age_min' => $data['age_min'] ?? null,
            'age_max' => $data['age_max'] ?? null,
            'is_published' => (bool) ($data['is_published'] ?? false),
        ]);

        return redirect()
            ->to(route('secretary.tournament.live', $tournament) . '?category=' . $category->id)
            ->with('status', 'Категория создана. Можно добавлять атлетов в очередь.');
    }

    /**
     * Удалить одну категорию (поток) внутри турнира. Cascade удалит выступления,
     * оценки судей и запросы; музыкальные файлы и записи music_tracks чистим вручную
     * (FK у этой таблицы намеренно отсутствует — см. миграцию music_tracks).
     */
    public function destroyCategory(Tournament $tournament, Category $category): RedirectResponse
    {
        if ((int) $category->tournament_id !== (int) $tournament->id) {
            abort(404);
        }

        $name = $category->name;

        DB::transaction(function () use ($tournament, $category) {
            $this->purgeCategoryMusic($category);

            if ((int) ($tournament->active_category_id ?? 0) === (int) $category->id) {
                $tournament->update(['active_category_id' => null]);
            }

            $category->delete();
        });

        return redirect()->route('secretary.tournament', $tournament)
            ->with('status', "Поток «{$name}» удалён вместе с выступлениями и оценками.");
    }

    /**
     * Очистить турнир от всех потоков (категорий) разом.
     */
    public function clearTournamentCategories(Tournament $tournament): RedirectResponse
    {
        $deleted = 0;

        DB::transaction(function () use ($tournament, &$deleted) {
            $categories = Category::query()
                ->where('tournament_id', $tournament->id)
                ->get();

            foreach ($categories as $cat) {
                $this->purgeCategoryMusic($cat);
                $cat->delete();
                $deleted++;
            }

            $tournament->update(['active_category_id' => null]);
        });

        $msg = $deleted > 0
            ? "Турнир очищен: удалено потоков — {$deleted}."
            : 'В турнире не было потоков для удаления.';

        return redirect()->route('secretary.tournament', $tournament)->with('status', $msg);
    }

    /**
     * Снести все music_tracks, относящиеся к performances данной категории,
     * включая попытку удалить файлы из хранилища (best-effort, ошибки игнорируем —
     * запись всё равно уйдёт, орфанной не останется).
     */
    private function purgeCategoryMusic(Category $category): void
    {
        $performanceIds = Performance::query()
            ->where('category_id', $category->id)
            ->pluck('id');

        if ($performanceIds->isEmpty()) {
            return;
        }

        MusicTrack::query()
            ->whereIn('performance_id', $performanceIds)
            ->get(['id', 'disk', 'path'])
            ->each(function (MusicTrack $track) {
                if ($track->disk && $track->path) {
                    try {
                        Storage::disk($track->disk)->delete($track->path);
                    } catch (\Throwable $e) {
                        // best-effort
                    }
                }
                $track->delete();
            });
    }

    public function athletes(): View
    {
        $athletes = Athlete::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(200)
            ->get();

        return view('secretary.athletes', [
            'athletes' => $athletes,
        ]);
    }

    public function storeAthlete(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birthdate' => ['nullable', 'date'],
            'club' => ['nullable', 'string', 'max:255'],
            'coach' => ['nullable', 'string', 'max:255'],
        ]);

        Athlete::query()->create($data);

        return back()->with('status', 'Атлет добавлен.');
    }

    public function categories(): View
    {
        $categories = Category::query()
            ->with('tournament')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('secretary.categories', [
            'categories' => $categories,
        ]);
    }

    public function tournamentLive(Request $request, Tournament $tournament): View
    {
        $categories = Category::query()
            ->where('tournament_id', $tournament->id)
            ->orderBy('id')
            ->get();

        if ($categories->isEmpty()) {
            return view('secretary.tournament-live-empty', [
                'tournament' => $tournament,
            ]);
        }

        $defaultId = $categories->first()->id;
        $categoryId = (int) $request->query('category', $defaultId);
        $category = $categories->firstWhere('id', $categoryId);

        if ($category === null) {
            abort(404);
        }

        $tournament->update(['active_category_id' => $category->id]);

        return view('secretary.queue', $this->queueViewData($category));
    }

    public function queue(Category $category): View
    {
        $category->loadMissing('tournament');
        $category->tournament?->update(['active_category_id' => $category->id]);

        return view('secretary.queue', $this->queueViewData($category));
    }

    /**
     * Лёгкий опрос для автообновления Live/очереди (оценки судей без WebSocket).
     */
    public function queuePing(Category $category): JsonResponse
    {
        $performances = Performance::query()
            ->where('category_id', $category->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get(['id', 'status', 'order_index', 'updated_at', 'finalized_at', 'd_score', 'a_score', 'e_score', 'penalty', 'total']);

        $ordered = SecretaryLiveUi::orderedPerformances($performances);
        $current = SecretaryLiveUi::currentPerformance($ordered);

        $perfSig = $performances->map(fn (Performance $p) => implode(':', [
            (string) $p->id,
            $p->status,
            (string) ($p->updated_at?->getTimestamp() ?? 0),
            (string) ($p->finalized_at?->getTimestamp() ?? 0),
            (string) ($p->d_score ?? ''),
            (string) ($p->a_score ?? ''),
            (string) ($p->e_score ?? ''),
            (string) ($p->penalty ?? ''),
            (string) ($p->total ?? ''),
        ]))->implode('|');

        $pids = $performances->pluck('id');
        $scoresDigest = '';
        if ($pids->isNotEmpty()) {
            $scoresDigest = JudgeScore::query()
                ->whereIn('performance_id', $pids)
                ->orderBy('performance_id')
                ->orderBy('id')
                ->get(['id', 'performance_id', 'judge_id', 'panel', 'subpanel', 'penalty_type', 'score', 'submitted_at', 'updated_at'])
                ->map(fn (JudgeScore $s) => implode(':', [
                    (string) $s->performance_id,
                    (string) $s->id,
                    (string) $s->judge_id,
                    $s->panel,
                    (string) ($s->subpanel ?? ''),
                    (string) ($s->penalty_type ?? ''),
                    (string) $s->score,
                    (string) ($s->submitted_at?->getTimestamp() ?? 0),
                    (string) ($s->updated_at?->getTimestamp() ?? 0),
                ]))
                ->implode(';');
        }

        $catSig = $category->id.':'.$category->updated_at?->getTimestamp().':'.implode(',', $category->inactiveJudgeSlotList()).':'.($category->auto_advance ? '1' : '0');

        $rev = md5($perfSig."\n".$scoresDigest."\n".$catSig);

        return response()->json([
            'rev' => $rev,
            'current_performance_id' => $current?->id,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * @return array<string, mixed>
     */
    private function queueViewData(Category $category): array
    {
        $performances = Performance::query()
            ->with([
                'category.tournament',
                'athlete',
                'judgeScores',
                'track',
                'trackBackup',
                'inquiries' => function ($q) {
                    $q->orderByDesc('id');
                },
            ])
            ->where('category_id', $category->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        $athletes = Athlete::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(200)
            ->get();

        $ordered = SecretaryLiveUi::orderedPerformances($performances);
        $currentPerformance = SecretaryLiveUi::currentPerformance($ordered);
        $nextPerformance = SecretaryLiveUi::nextAfter($ordered, $currentPerformance);
        $streamStatus = SecretaryLiveUi::streamStatus($currentPerformance);
        $judgeSlots = SecretaryLiveUi::judgeSlots($currentPerformance, $category);
        $scoreMatrix = SecretaryLiveUi::fixedScoreMatrix($currentPerformance, $category);
        $panelSpread = SecretaryLiveUi::panelSpreadReport($currentPerformance, $category);
        $waitingJudges = collect($judgeSlots)->filter(fn ($s) => ! $s['ok'] && ! ($s['inactive'] ?? false))->count();
        $activeJudgeSlots = collect($judgeSlots)->filter(fn ($s) => ! ($s['inactive'] ?? false))->count();
        $totalJudgeSlots = count($judgeSlots);

        $category->loadMissing('tournament');

        $tournament = $category->tournament;
        $tournamentCategories = $tournament
            ? Category::query()
                ->where('tournament_id', $tournament->id)
                ->orderBy('id')
                ->get()
            : collect();

        $protocolGroups = $tournament
            ? app(FinalProtocolService::class)->groups($tournament)
            : collect();

        // История выставления оценок по слотам (для модалки по клику на оценку).
        $scoreHistory = [];
        foreach (SecretaryLiveUi::scoreRowsBySlot($currentPerformance, $category) as $slot => $row) {
            if ($row === null) {
                continue;
            }
            $scoreHistory[$slot] = [
                'slot' => $slot,
                'judge' => $row->judge?->name ?? '—',
                'score' => $row->score !== null ? number_format((float) $row->score, 3, '.', '') : '—',
                'age_group' => $row->age_group,
                'submitted_at' => $row->submitted_at?->format('H:i:s'),
                'entries' => $row->entries ?? [],
            ];
        }

        return [
            'category' => $category,
            'tournamentCategories' => $tournamentCategories,
            'protocolGroups' => $protocolGroups,
            'performances' => $performances,
            'orderedPerformances' => $ordered,
            'currentPerformance' => $currentPerformance,
            'nextPerformance' => $nextPerformance,
            'streamStatus' => $streamStatus,
            'judgeSlots' => $judgeSlots,
            'scoreMatrix' => $scoreMatrix,
            'panelSpread' => $panelSpread,
            'waitingJudges' => $waitingJudges,
            'totalJudgeSlots' => $totalJudgeSlots,
            'activeJudgeSlots' => $activeJudgeSlots,
            'athletes' => $athletes,
            'scoreHistory' => $scoreHistory,
        ];
    }

    /**
     * Подтвердить итог несмотря на расхождение оценок (секретарь / главный судья).
     */
    public function confirmScore(Performance $performance): RedirectResponse
    {
        $performance->load(['judgeScores', 'category']);
        $category = $performance->category;

        if (! SecretaryLiveUi::requiredScoresSubmitted($performance, $category)) {
            return back()->withErrors(['confirm' => 'Не все обязательные оценки выставлены — подтверждать пока нечего.']);
        }

        $moved = false;
        DB::transaction(function () use ($performance, $category, &$moved) {
            $performance->recalculateTotals();
            $performance->finalized_at = now();
            $performance->save();

            if ($category?->auto_advance) {
                $moved = StreamAdvanceService::advanceToNextInCategory($category);
            }
        });

        $msg = 'Итог подтверждён и зафиксирован.';
        if ($moved) {
            $msg .= ' Вызвана следующая гимнастка.';
        }

        return back()->with('status', $msg);
    }

    /**
     * Вернуть оценку на доработку: один слот, панель (db/da/a/e/penalty) или все сразу.
     */
    public function returnScores(Performance $performance, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'panel' => ['nullable', Rule::in(['db', 'da', 'a', 'e', 'penalty', 'all'])],
            'slot' => ['nullable', 'string', Rule::in(SecretaryLiveUi::ALL_JUDGE_SLOTS)],
        ]);

        if (empty($data['panel']) && empty($data['slot'])) {
            return back()->withErrors(['return' => 'Укажите слот или панель для возврата на доработку.']);
        }

        $deleted = 0;
        $label = '';

        if (! empty($data['slot'])) {
            $performance->load(['judgeScores.judge', 'category']);
            $rows = SecretaryLiveUi::scoreRowsBySlot($performance, $performance->category);
            $row = $rows[$data['slot']] ?? null;

            if ($row === null) {
                return back()->withErrors(['return' => 'Для слота '.$data['slot'].' нет оценки — возвращать нечего.']);
            }

            $row->delete();
            $deleted = 1;
            $label = $data['slot'];
        } else {
            $key = $data['panel'];
            $query = JudgeScore::query()->where('performance_id', $performance->id);

            if (in_array($key, ['db', 'da'], true)) {
                $query->where('panel', 'd')->where('subpanel', $key);
                $label = strtoupper($key);
            } elseif ($key === 'penalty') {
                $query->where('panel', 'penalty');
                $label = 'штрафы (LINE/TIME/RESP)';
            } elseif ($key === 'all') {
                $label = 'все оценки';
            } else {
                $query->where('panel', $key);
                $label = strtoupper($key);
            }

            $deleted = $query->delete();
        }

        $performance->refresh();
        $performance->load(['judgeScores', 'category']);
        $performance->recalculateTotals();
        $performance->finalized_at = null;
        $performance->save();

        return back()->with('status', 'На доработку возвращено: '.$label.' ('.$deleted.' шт.). Судьи увидят планшет ввода снова.');
    }

    /**
     * Исправить оценку конкретного судьи (секретарь / главный судья).
     */
    public function updateJudgeScore(Performance $performance, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slot' => ['required', 'string', Rule::in(SecretaryLiveUi::ALL_JUDGE_SLOTS)],
            'score' => ['required', 'numeric', 'min:0', 'max:99.999'],
        ]);

        $performance->load(['judgeScores.judge', 'category']);
        $rows = SecretaryLiveUi::scoreRowsBySlot($performance, $performance->category);
        $row = $rows[$data['slot']] ?? null;

        if ($row === null) {
            return back()->withErrors(['edit' => 'Для слота '.$data['slot'].' нет оценки — исправлять нечего.']);
        }

        $row->score = (float) $data['score'];
        $row->save();

        $performance->refresh();
        $performance->load(['judgeScores', 'category']);
        $performance->recalculateTotals();
        $performance->save();

        return back()->with('status', 'Оценка '.$data['slot'].' исправлена на '.number_format((float) $data['score'], 3, '.', '').'.');
    }

    public function addToQueue(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'athlete_id' => ['required', 'integer', 'exists:athletes,id'],
            'apparatus' => ['nullable', 'string', 'max:64'],
            'start_number' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'position' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        $maxOrder = (int) (Performance::query()
            ->where('category_id', $category->id)
            ->max('order_index') ?? 0);

        $orderIndex = isset($data['position']) && $data['position']
            ? (int) $data['position']
            : ($maxOrder + 1);

        // Make room if inserting into the middle.
        Performance::query()
            ->where('category_id', $category->id)
            ->where('order_index', '>=', $orderIndex)
            ->increment('order_index');

        Performance::query()->create([
            'category_id' => $category->id,
            'athlete_id' => (int) $data['athlete_id'],
            'apparatus' => $data['apparatus'] ?? null,
            'start_number' => $data['start_number'] ?? null,
            'order_index' => $orderIndex,
            'status' => 'scheduled',
        ]);

        return back()->with('status', 'Добавлено в очередь.');
    }

    public function reorderQueue(Request $request, Category $category)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ]);

        $ids = array_values(array_map('intval', $data['ids']));
        $ids = array_values(array_unique($ids));

        $existing = Performance::query()
            ->where('category_id', $category->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        sort($existing);
        $check = $ids;
        sort($check);

        if ($existing !== $check) {
            abort(422, 'Некорректный список выходов для этой категории.');
        }

        DB::transaction(function () use ($ids, $category) {
            $i = 1;
            foreach ($ids as $id) {
                Performance::query()
                    ->where('category_id', $category->id)
                    ->where('id', $id)
                    ->update(['order_index' => $i]);
                $i++;
            }
        });

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('status', 'Очередь обновлена.');
    }

    public function removeFromQueue(Performance $performance): RedirectResponse
    {
        $categoryId = $performance->category_id;
        $removedOrder = (int) $performance->order_index;
        $performance->delete();

        Performance::query()
            ->where('category_id', $categoryId)
            ->where('order_index', '>', $removedOrder)
            ->decrement('order_index');

        return back()->with('status', 'Удалено из очереди.');
    }

    public function moveQueue(Performance $performance, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'dir' => ['required', 'string', 'in:up,down'],
        ]);

        $dir = $data['dir'];
        $categoryId = $performance->category_id;

        $neighbor = Performance::query()
            ->where('category_id', $categoryId)
            ->where('id', '!=', $performance->id)
            ->when($dir === 'up', function ($q) use ($performance) {
                $q->where('order_index', '<', $performance->order_index)->orderByDesc('order_index')->orderByDesc('id');
            })
            ->when($dir === 'down', function ($q) use ($performance) {
                $q->where('order_index', '>', $performance->order_index)->orderBy('order_index')->orderBy('id');
            })
            ->first();

        if (! $neighbor) {
            return back();
        }

        $a = (int) $performance->order_index;
        $b = (int) $neighbor->order_index;

        $performance->order_index = $b;
        $neighbor->order_index = $a;
        $performance->save();
        $neighbor->save();

        return back();
    }

    public function callNext(Category $category): RedirectResponse
    {
        StreamAdvanceService::advanceToNextInCategory($category);

        return back();
    }

    public function setAutoAdvance(Request $request, Category $category): RedirectResponse
    {
        $request->validate([
            'enabled' => ['required', 'in:0,1'],
        ]);

        $category->auto_advance = (int) $request->input('enabled') === 1;
        $category->save();

        return back()->with('status', $category->auto_advance
            ? 'Автопереход включён: после всех основных оценок поток перейдёт к следующей гимнастке.'
            : 'Автопереход выключен.');
    }

    /**
     * Включает/выключает слот судьи (на случай неполного состава бригады).
     */
    public function toggleJudgeSlot(Request $request, Category $category)
    {
        $data = $request->validate([
            'slot' => ['required', 'string', Rule::in(\App\Support\SecretaryLiveUi::ALL_JUDGE_SLOTS)],
            'active' => ['required', 'in:0,1'],
        ]);

        $slot = strtoupper((string) $data['slot']);
        $shouldBeActive = (int) $data['active'] === 1;

        $current = $category->inactiveJudgeSlotList();

        if ($shouldBeActive) {
            $current = array_values(array_filter($current, static fn ($s) => $s !== $slot));
        } elseif (! in_array($slot, $current, true)) {
            $current[] = $slot;
        }

        $category->inactive_judge_slots = $current;
        $category->save();

        $message = $shouldBeActive
            ? "Слот {$slot} включён."
            : "Слот {$slot} отключён — оценки этой позиции не требуются.";

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'slot' => $slot,
                'active' => $shouldBeActive,
                'inactive_slots' => $current,
                'message' => $message,
            ]);
        }

        return back()->with('status', $message);
    }

    public function start(Performance $performance): RedirectResponse
    {
        $performance->status = 'performing';
        $performance->started_at = now();
        $performance->save();

        return back();
    }

    public function finish(Performance $performance): RedirectResponse
    {
        $performance->status = 'done';
        $performance->ended_at = now();
        $performance->save();

        return back();
    }

    /**
     * Загрузка музыки для выхода без аккаунта гимнастки (файл привязывается к performance / athlete_id).
     */
    public function uploadPerformanceMusic(Request $request, Category $category, MusicTrackUploadService $uploader): RedirectResponse
    {
        $data = $request->validate([
            'performance_id' => ['required', 'integer'],
            'type' => ['nullable', 'string', 'in:primary,backup'],
            'music' => ['required', 'file', 'mimetypes:audio/mpeg,audio/mp4,audio/x-m4a,audio/wav', 'max:30720'],
        ]);

        $performance = Performance::query()
            ->where('category_id', $category->id)
            ->where('id', (int) $data['performance_id'])
            ->firstOrFail();

        $type = (string) ($request->input('type') ?: 'primary');
        $file = $request->file('music');

        try {
            $uploader->store($performance, $file, $request->user(), $type);
        } catch (DomainException $e) {
            return back()->withErrors(['music' => $e->getMessage()]);
        }

        return back()->with('status', 'Музыка загружена для выхода №'.($performance->start_number ?? $performance->id).'.');
    }
}
