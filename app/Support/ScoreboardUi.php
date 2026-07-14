<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Performance;
use App\Models\Tournament;
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
            'free' => 'БП',
            'бп' => 'БП',
            'б.п.' => 'БП',
            'bp' => 'БП',
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
     * Категории одной группы (год + буква) — предварительное место считаем по группе,
     * а не по одному потоку, чтобы оно совпадало с итоговой таблицей.
     *
     * @return list<int>
     */
    private static function groupCategoryIds(Category $category): array
    {
        $category->loadMissing('tournament');
        $tournament = $category->tournament;
        if ($tournament === null) {
            return [$category->id];
        }

        $year = $category->resolvedBirthYear();
        $division = $category->resolvedDivision();

        return $tournament->categories()->get()
            ->filter(fn (Category $c) => $c->resolvedBirthYear() === $year && $c->resolvedDivision() === $division)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Предварительное место гимнастки по группе (год + буква): считаем лучших
     * соперниц (по опубликованному итогу) с итогом выше текущего.
     */
    public static function provisionalPlace(Category $category, Performance $perf): ?int
    {
        $currentTotal = $perf->total;
        if ($currentTotal === null) {
            return null;
        }

        $higher = Performance::query()
            ->whereIn('category_id', self::groupCategoryIds($category))
            ->where('is_counted', true)
            ->whereNotNull('total')
            ->whereNotNull('published_at')
            ->whereNull('withdrawn_at')
            ->where('athlete_id', '!=', $perf->athlete_id)
            ->get(['athlete_id', 'total'])
            ->groupBy('athlete_id')
            ->map(fn ($g) => (float) $g->max('total'))
            ->filter(fn (float $t) => $t > (float) $currentTotal + 0.0004)
            ->count();

        return $higher + 1;
    }

    /**
     * Сколько всего участниц в группе с опубликованным результатом (+ текущая).
     */
    public static function provisionalPlaceOf(Category $category, Performance $perf): int
    {
        $distinct = Performance::query()
            ->whereIn('category_id', self::groupCategoryIds($category))
            ->where('is_counted', true)
            ->whereNotNull('total')
            ->whereNotNull('published_at')
            ->whereNull('withdrawn_at')
            ->where('athlete_id', '!=', $perf->athlete_id)
            ->distinct('athlete_id')
            ->count('athlete_id');

        return $distinct + 1;
    }

    /**
     * Пересчитывать итог «на лету» нужно только для выступающей гимнастки без
     * зафиксированного/ручного итога — иначе берём сохранённые оценки.
     */
    private static function needsLiveRecalc(Performance $perf): bool
    {
        return $perf->status === 'performing'
            && $perf->finalized_at === null
            && ! $perf->scores_overridden;
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
                'rev' => 'empty',
                'updated_at' => now()->toIso8601String(),
            ];
        }

        if (self::needsLiveRecalc($perf)) {
            $perf->recalculateTotals();
        }

        $phase = self::performancePhase($perf);
        $inq = $perf->inquiries->sortByDesc('id')->first();
        $judgeSlots = SecretaryLiveUi::judgeSlots($perf, $category);
        $mainSlots = collect($judgeSlots)->filter(
            fn ($s) => in_array($s['label'], SecretaryLiveUi::AUTO_ADVANCE_REQUIRED_LABELS, true) && ! $s['inactive']
        );
        $submittedMain = $mainSlots->where('ok', true)->count();
        $requiredMain = $mainSlots->count();
        $place = self::provisionalPlace($category, $perf);
        $placeOf = self::provisionalPlaceOf($category, $perf);

        $rev = md5(implode('|', [
            $perf->id, $perf->status, $phase,
            $perf->d_score, $perf->a_score, $perf->e_score, $perf->penalty, $perf->total,
            $place, $placeOf, $submittedMain, $requiredMain,
            $perf->finalized_at?->getTimestamp(), $perf->published_at?->getTimestamp(),
            $inq?->status,
        ]));

        return [
            'rev' => $rev,
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
                'place_of' => $placeOf,
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
     * @return Collection<int, Tournament>
     */
    public static function publishedTournaments(): Collection
    {
        return Tournament::query()
            ->where('is_published', true)
            ->whereHas('categories', fn ($q) => $q->where('is_published', true))
            ->with(['categories' => fn ($q) => $q->where('is_published', true)->orderBy('id')])
            ->orderByDesc('id')
            ->get();
    }
}
