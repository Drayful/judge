<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Performance;
use App\Models\Tournament;
use App\Services\FinalProtocolService;
use App\Support\CompetitionPool;
use App\Support\ScoreboardUi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ScoreboardController extends Controller
{
    public function __construct(
        private readonly FinalProtocolService $finalProtocol,
    ) {}

    public function index(): View
    {
        $tournaments = ScoreboardUi::scoreboardTournaments();
        $selected = $this->resolveSelectedCategory(
            request()->integer('category'),
            request()->integer('tournament'),
        );
        $selectedTournament = $selected?->tournament
            ?? $tournaments->firstWhere('id', request()->integer('tournament'));

        return view('scoreboard.index', [
            'tournaments' => $tournaments,
            'selected' => $selected,
            'selectedTournament' => $selectedTournament,
        ]);
    }

    /** @deprecated Используйте scoreboard.table — ссылка для родителей. */
    public function category(Category $category): RedirectResponse
    {
        $this->ensureAvailable($category);

        return redirect()->route('scoreboard.table', $category);
    }

    public function table(Category $category): View
    {
        $this->ensureAvailable($category);
        $category->loadMissing('tournament');

        return view('scoreboard.table', [
            'category' => $category,
            'rows' => $this->publishedRows($category),
            'competitionPool' => CompetitionPool::resolve($category),
        ]);
    }

    public function categoryLive(Category $category): JsonResponse
    {
        $this->ensureAvailable($category);

        $rows = $this->publishedRowPayloads($category);
        $rev = md5(collect($rows)->map(fn ($r) => $r['id'].':'.$r['place'].':'.$r['total'].':'.($r['inquiry_status'] ?? ''))->implode('|'));

        return response()->json([
            'category' => ['id' => $category->id, 'name' => $category->name],
            'rev' => $rev,
            'updated_at' => now()->toIso8601String(),
            'rows' => $rows,
        ]);
    }

    public function performance(Category $category): View
    {
        $this->ensureAvailable($category);
        $category->loadMissing('tournament');
        $livePerformance = ScoreboardUi::boardPerformance($category);
        $payloadCategory = $livePerformance?->category ?? $category;

        return view('scoreboard.performance', [
            'category' => $payloadCategory,
            'pollCategory' => $category,
            'initialPayload' => ScoreboardUi::performancePayload($payloadCategory, $livePerformance),
        ]);
    }

    public function performanceLive(Category $category): JsonResponse
    {
        $this->ensureAvailable($category);

        $performance = ScoreboardUi::boardPerformance($category);
        $payloadCategory = $performance?->category ?? $category;

        return response()->json(ScoreboardUi::performancePayload($payloadCategory, $performance));
    }

    private function resolveSelectedCategory(int $categoryId, int $tournamentId): ?Category
    {
        if ($categoryId > 0) {
            $category = Category::query()->with('tournament')->find($categoryId);
            if ($category?->tournament !== null) {
                return $category;
            }
        }

        if ($tournamentId > 0) {
            $tournament = Tournament::query()->find($tournamentId);

            return $tournament ? $this->currentCategoryForTournament($tournament) : null;
        }

        return $this->defaultScoreboardCategory();
    }

    private function defaultScoreboardCategory(): ?Category
    {
        $tournaments = Tournament::query()
            ->whereHas('categories')
            ->orderByDesc('id')
            ->get();

        $tournament = $tournaments->first(fn (Tournament $item) => $item->active_category_id !== null)
            ?? $tournaments->first();

        if ($tournament === null) {
            return null;
        }

        return $this->currentCategoryForTournament($tournament);
    }

    private function currentCategoryForTournament(Tournament $tournament): ?Category
    {
        if ($tournament->active_category_id !== null) {
            $active = Category::query()
                ->with('tournament')
                ->where('tournament_id', $tournament->id)
                ->find($tournament->active_category_id);

            if ($active !== null) {
                return $active;
            }
        }

        $live = Category::query()
            ->with('tournament')
            ->where('tournament_id', $tournament->id)
            ->whereHas('performances', fn ($query) => $query->whereIn('status', ['performing', 'on_deck']))
            ->orderedByPerformanceTime()
            ->first();

        return $live ?? Category::query()
            ->with('tournament')
            ->where('tournament_id', $tournament->id)
            ->orderedByPerformanceTime()
            ->first();
    }

    private function ensureAvailable(Category $category): void
    {
        $category->loadMissing('tournament');

        if ($category->tournament === null) {
            abort(404);
        }
    }

    /**
     * Опубликованные участницы Excel-пула из всех потоков нужного года и категории.
     *
     * @return Collection<int, object>
     */
    private function publishedRows(Category $category)
    {
        $groupRanks = $this->finalProtocol->publishedAthletesById($category);
        if ($groupRanks === []) {
            return collect();
        }

        $category->loadMissing('tournament');
        $categoryIds = $category->tournament?->categories()->get()
            ->filter(fn (Category $candidate) => $candidate->resolvedBirthYear() === $category->resolvedBirthYear()
                && $candidate->resolvedDivision() === $category->resolvedDivision())
            ->pluck('id')
            ->all() ?? [$category->id];

        $streamPerformances = Performance::query()
            ->with(['athlete', 'inquiries' => fn ($q) => $q->orderByDesc('id')])
            ->whereIn('category_id', $categoryIds)
            ->whereIn('athlete_id', array_keys($groupRanks))
            ->whereNotNull('total')
            ->whereNotNull('published_at')
            ->where('is_counted', true)
            ->whereNull('withdrawn_at')
            ->orderBy('published_at')
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

                $vidi = array_map(fn ($v) => round((float) $v, 3), $rank['vidi'] ?? []);

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
                    'apparatus' => $latest->apparatus,
                    'apparatus_score' => $latest->total,
                    'scoreboard_accepted_at' => $latest->scoreboard_accepted_at,
                    'vidi' => $vidi,
                    'total' => $rank['total'],
                    'status' => $rank['status'] ?? 'ranked',
                ];
            })
            ->filter()
            ->sort(function (object $left, object $right): int {
                $leftPlace = $left->place ?? PHP_INT_MAX;
                $rightPlace = $right->place ?? PHP_INT_MAX;

                return $leftPlace <=> $rightPlace
                    ?: $left->id <=> $right->id;
            })
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
                    'apparatus' => $row->apparatus,
                    'apparatus_score' => $row->apparatus_score,
                    'vidi' => $row->vidi,
                    'total' => $row->total,
                    'status' => $row->status,
                    'accepted_at' => $row->scoreboard_accepted_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
