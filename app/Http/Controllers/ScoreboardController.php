<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Performance;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ScoreboardController extends Controller
{
    public function category(Category $category): View
    {
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
}
