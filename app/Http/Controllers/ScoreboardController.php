<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Performance;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ScoreboardController extends Controller
{
    /**
     * Публичная «точка входа» — подбирает разумную категорию для табло
     * (последний опубликованный турнир и его первая опубликованная категория).
     * Используется в welcome / dashboard / sidebar, чтобы не было битых
     * ссылок на category=1.
     */
    public function index(): View|RedirectResponse
    {
        $category = $this->defaultPublicCategory();

        if ($category !== null) {
            return redirect()->route('scoreboard.category', $category);
        }

        return view('scoreboard.empty');
    }

    public function category(Category $category): View
    {
        $this->ensurePublic($category);

        $rows = Performance::query()
            ->with(['athlete', 'inquiries' => function ($q) {
                $q->orderByDesc('id');
            }])
            ->where('category_id', $category->id)
            ->whereNotNull('total')
            ->whereNotNull('published_at')
            ->where('is_counted', true)
            ->orderByDesc('total')
            ->orderBy('id')
            ->get();

        return view('scoreboard.category', [
            'category' => $category,
            'rows' => $rows,
        ]);
    }

    public function categoryLive(Category $category): JsonResponse
    {
        $this->ensurePublic($category);

        $rows = Performance::query()
            ->with(['athlete', 'inquiries' => function ($q) {
                $q->orderByDesc('id');
            }])
            ->where('category_id', $category->id)
            ->whereNotNull('total')
            ->whereNotNull('published_at')
            ->where('is_counted', true)
            ->orderByDesc('total')
            ->orderBy('id')
            ->get()
            ->values()
            ->map(function (Performance $p, int $idx): array {
                $inq = $p->inquiries->first();
                return [
                    'place' => $idx + 1,
                    'start_number' => $p->start_number,
                    'athlete' => trim(($p->athlete?->last_name ?? '').' '.($p->athlete?->first_name ?? '')),
                    'club' => $p->athlete?->club,
                    'apparatus' => $p->apparatus,
                    'inquiry_status' => $inq?->status,
                    'd' => $p->d_score,
                    'a' => $p->a_score,
                    'e' => $p->e_score,
                    'penalty' => $p->penalty,
                    'total' => $p->total,
                ];
            });

        return response()->json([
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
            ],
            'updated_at' => now()->toIso8601String(),
            'rows' => $rows,
        ]);
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
}
