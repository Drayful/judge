<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Performance;
use Illuminate\Support\Collection;

class ScoreboardUi
{
  /**
   * @return array<string, string>
   */
    public static function apparatusLabels(): array
    {
        return [
            'hoop' => 'Обруч',
            'ball' => 'Мяч',
            'clubs' => 'Булавы',
            'ribbon' => 'Лента',
            'rope' => 'Скакалка',
            'free' => 'Б/П',
            'бп' => 'Б/П',
            'б.п.' => 'Б/П',
            'bp' => 'Б/П',
        ];
    }

    public static function apparatusLabel(?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        return self::apparatusLabels()[strtolower($code)] ?? $code;
    }

    /**
     * Текущее выступление для индивидуального табло:
     * на ковре → вызвана → последняя завершённая, ещё не опубликованная.
     */
    public static function livePerformance(Category $category): ?Performance
    {
        $ordered = SecretaryLiveUi::orderedPerformances(
            $category->performances()
                ->with(['athlete', 'judgeScores.judge', 'inquiries'])
                ->get()
        );

        $current = $ordered->firstWhere('status', 'performing')
            ?? $ordered->firstWhere('status', 'on_deck');

        if ($current !== null) {
            return $current;
        }

        return $ordered
            ->filter(fn (Performance $p) => $p->status === 'done' && $p->published_at === null)
            ->last();
    }

    public static function performancePhase(?Performance $perf): string
    {
        if ($perf === null) {
            return 'empty';
        }

        return match ($perf->status) {
            'performing' => $perf->finalized_at ? 'finalized' : 'performing',
            'on_deck' => 'on_deck',
            'done' => $perf->published_at ? 'published' : ($perf->finalized_at ? 'finalized' : 'scoring'),
            default => $perf->status,
        };
    }

    public static function phaseLabel(string $phase): string
    {
        return match ($phase) {
            'performing' => 'На ковре',
            'on_deck' => 'Готовится к выходу',
            'scoring' => 'Подсчёт оценок',
            'finalized' => 'Итог подсчитан',
            'published' => 'Результат опубликован',
            'empty' => 'Ожидание участницы',
            default => '—',
        };
    }

    /**
     * Предварительное место гимнастки среди опубликованных результатов потока + её текущий итог.
     */
    public static function provisionalPlace(Category $category, Performance $perf): ?int
    {
        $perf->recalculateTotals();
        $currentTotal = $perf->total;
        if ($currentTotal === null) {
            return null;
        }

        $higher = Performance::query()
            ->where('category_id', $category->id)
            ->where('is_counted', true)
            ->whereNotNull('total')
            ->where('id', '!=', $perf->id)
            ->whereNotNull('published_at')
            ->get()
            ->filter(function (Performance $p) use ($currentTotal) {
                return (float) $p->total > (float) $currentTotal + 0.0004;
            })
            ->count();

        return $higher + 1;
    }

    public static function provisionalPlaceOf(Category $category, Performance $perf): int
    {
        $published = Performance::query()
            ->where('category_id', $category->id)
            ->where('is_counted', true)
            ->whereNotNull('total')
            ->whereNotNull('published_at')
            ->count();

        return max($published, 0) + 1;
    }

    /**
     * @return array<string, mixed>
     */
    public static function performancePayload(Category $category, ?Performance $perf): array
    {
        if ($perf === null) {
            return [
                'performance' => null,
                'phase' => 'empty',
                'phase_label' => self::phaseLabel('empty'),
                'updated_at' => now()->toIso8601String(),
            ];
        }

        $perf->recalculateTotals();

        $phase = self::performancePhase($perf);
        $inq = $perf->inquiries->sortByDesc('id')->first();
        $judgeSlots = SecretaryLiveUi::judgeSlots($perf, $category);
        $mainSlots = collect($judgeSlots)->filter(
            fn ($s) => in_array($s['label'], SecretaryLiveUi::AUTO_ADVANCE_REQUIRED_LABELS, true) && ! $s['inactive']
        );
        $submittedMain = $mainSlots->where('ok', true)->count();
        $requiredMain = $mainSlots->count();
        $place = self::provisionalPlace($category, $perf);

        return [
            'performance' => [
                'id' => $perf->id,
                'start_number' => $perf->start_number,
                'athlete' => trim(($perf->athlete?->last_name ?? '').' '.($perf->athlete?->first_name ?? '')),
                'club' => $perf->athlete?->club,
                'apparatus' => $perf->apparatus,
                'apparatus_label' => self::apparatusLabel($perf->apparatus),
                'status' => $perf->status,
                'd' => $perf->d_score,
                'a' => $perf->a_score,
                'e' => $perf->e_score,
                'penalty' => $perf->penalty,
                'total' => $perf->total,
                'place' => $place,
                'place_of' => self::provisionalPlaceOf($category, $perf),
                'finalized_at' => $perf->finalized_at?->toIso8601String(),
                'published_at' => $perf->published_at?->toIso8601String(),
                'inquiry_status' => $inq?->status,
            ],
            'phase' => $phase,
            'phase_label' => self::phaseLabel($phase),
            'judges' => [
                'submitted' => $submittedMain,
                'required' => $requiredMain,
                'slots' => $judgeSlots,
            ],
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return Collection<int, \App\Models\Tournament>
     */
    public static function publishedTournaments(): Collection
    {
        return \App\Models\Tournament::query()
            ->where('is_published', true)
            ->whereHas('categories', fn ($q) => $q->where('is_published', true))
            ->with(['categories' => fn ($q) => $q->where('is_published', true)->orderBy('id')])
            ->orderByDesc('id')
            ->get();
    }
}
