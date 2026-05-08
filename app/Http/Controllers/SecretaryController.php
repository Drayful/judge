<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\JudgeScore;
use App\Models\Performance;
use App\Models\Tournament;
use App\Services\MusicTrackUploadService;
use App\Services\StartProtocolImportService;
use App\Services\StreamAdvanceService;
use App\Support\SecretaryLiveUi;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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

    public function tournament(Tournament $tournament): View
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

        $rev = md5($perfSig."\n".$scoresDigest);

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
        $judgeSlots = SecretaryLiveUi::judgeSlots($currentPerformance);
        $scoreMatrix = SecretaryLiveUi::fixedScoreMatrix($currentPerformance);
        $waitingJudges = collect($judgeSlots)->where('ok', false)->count();
        $totalJudgeSlots = count($judgeSlots);

        $category->loadMissing('tournament');

        $tournament = $category->tournament;
        $tournamentCategories = $tournament
            ? Category::query()
                ->where('tournament_id', $tournament->id)
                ->orderBy('id')
                ->get()
            : collect();

        return [
            'category' => $category,
            'tournamentCategories' => $tournamentCategories,
            'performances' => $performances,
            'orderedPerformances' => $ordered,
            'currentPerformance' => $currentPerformance,
            'nextPerformance' => $nextPerformance,
            'streamStatus' => $streamStatus,
            'judgeSlots' => $judgeSlots,
            'scoreMatrix' => $scoreMatrix,
            'waitingJudges' => $waitingJudges,
            'totalJudgeSlots' => $totalJudgeSlots,
            'athletes' => $athletes,
        ];
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
