<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Performance;
use App\Models\Tournament;
use App\Services\FinalProtocolService;
use App\Support\ScoreboardUi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ScoreboardController extends Controller
{
    public function __construct(
        private readonly FinalProtocolService $finalProtocol,
    ) {}

    public function index(): View
    {
        $tournaments = ScoreboardUi::publishedTournaments();
        $selected = $this->resolveSelectedCategory(request()->integer('category'));

        return view('scoreboard.index', [
            'tournaments' => $tournaments,
            'selected' => $selected,
        ]);
    }

    /** @deprecated Используйте scoreboard.table — ссылка для родителей. */
    public function category(Category $category): RedirectResponse
    {
        $this->ensurePublic($category);

        return redirect()->route('scoreboard.table', $category);
    }

    public function table(Category $category): View
    {
        $this->ensurePublic($category);
        $category->loadMissing('tournament');

        return view('scoreboard.table', [
            'category' => $category,
            'rows' => $this->publishedRows($category),
        ]);
    }

    public function categoryLive(Category $category): JsonResponse
    {
        $this->ensurePublic($category);

        return response()->json([
            'category' => ['id' => $category->id, 'name' => $category->name],
            'updated_at' => now()->toIso8601String(),
            'rows' => $this->publishedRowPayloads($category),
        ]);
    }

    public function performance(Category $category): View
    {
        $this->ensurePublic($category);
        $category->loadMissing('tournament');
        $livePerformance = ScoreboardUi::livePerformance($category);

        return view('scoreboard.performance', [
            'category' => $category,
            'initialPayload' => ScoreboardUi::performancePayload($category, $livePerformance),
        ]);
    }

    public function performanceLive(Category $category): JsonResponse
    {
        $this->ensurePublic($category);

        return response()->json(
            ScoreboardUi::performancePayload($category, ScoreboardUi::livePerformance($category))
        );
    }

    private function resolveSelectedCategory(int $categoryId): ?Category
    {
        if ($categoryId > 0) {
            $category = Category::query()->with('tournament')->find($categoryId);
            if ($category && $category->is_published && $category->tournament?->is_published) {
                return $category;
            }
        }

        return $this->defaultPublicCategory();
    }

    private function defaultPublicCategory(): ?Category
    {
        $tournament = Tournament::query()
            ->where('is_published', true)
            ->whereHas('categories', fn ($q) => $q->where('is_published', true))
            ->orderByDesc('id')
            ->first();

        if ($tournament === null) {
            return null;
        }

        return Category::query()
            ->where('tournament_id', $tournament->id)
            ->where('is_published', true)
            ->orderBy('id')
            ->first();
    }

    private function ensurePublic(Category $category): void
    {
        $category->loadMissing('tournament');

        if (! $category->is_published || ! $category->tournament?->is_published) {
            abort(404);
        }
    }

    /**
     * Опубликованные участницы потока с местами по группе (год + категория), как в итоговом протоколе.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function publishedRows(Category $category)
    {
        $groupRanks = $this->finalProtocol->publishedAthletesById($category);

        $streamPerformances = Performance::query()
            ->with(['athlete', 'inquiries' => fn ($q) => $q->orderByDesc('id')])
            ->where('category_id', $category->id)
            ->whereNotNull('total')
            ->whereNotNull('published_at')
            ->where('is_counted', true)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get()
            ->groupBy('athlete_id');

        $rows = $streamPerformances
            ->map(function ($performances, $athleteId) use ($groupRanks) {
                $rank = $groupRanks[(int) $athleteId] ?? null;
                if ($rank === null) {
                    return null;
                }

                /** @var Performance $latest */
                $latest = $performances->last();

                return (object) [
                    'id' => (int) $athleteId,
                    'place' => $rank['place'],
                    'start_number' => $latest->start_number,
                    'athlete' => $latest->athlete,
                    'inquiries' => $latest->inquiries,
                    'd_score' => $latest->d_score,
                    'a_score' => $latest->a_score,
                    'e_score' => $latest->e_score,
                    'penalty' => $latest->penalty,
                    'total' => $rank['total'],
                ];
            })
            ->filter()
            ->sortByDesc('total')
            ->values();

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publishedRowPayloads(Category $category): array
    {
        return $this->publishedRows($category)
            ->map(function (object $row): array {
                $inq = $row->inquiries->first();

                return [
                    'id' => $row->id,
                    'place' => $row->place,
                    'start_number' => $row->start_number,
                    'athlete' => trim(($row->athlete?->last_name ?? '').' '.($row->athlete?->first_name ?? '')),
                    'club' => $row->athlete?->club,
                    'inquiry_status' => $inq?->status,
                    'd' => $row->d_score,
                    'a' => $row->a_score,
                    'e' => $row->e_score,
                    'penalty' => $row->penalty,
                    'total' => $row->total,
                ];
            })
            ->values()
            ->all();
    }
}
