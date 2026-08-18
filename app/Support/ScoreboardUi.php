<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Entry;
use App\Models\Performance;
use App\Models\Tournament;
use Illuminate\Support\Collection;

class ScoreboardUi
{
    private const PLACE_PRECISION = 3;

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
                ->with(['athlete.members', 'judgeScores.judge', 'inquiries'])
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
     * Пул рейтинга: прежде всего все записи одного Excel-листа. Если запись была
     * добавлена вручную, используем состав системной группы, из которой собраны потоки.
     *
     * @return array{athlete_ids:?list<int>,label:?string}
     */
    private static function rankingPool(Category $category, Performance $performance): array
    {
        $category->loadMissing('tournament');
        $tournament = $category->tournament;
        if ($tournament === null) {
            return ['athlete_ids' => null, 'label' => null];
        }

        $entry = null;
        if ($category->group_id !== null) {
            $entry = Entry::query()
                ->where('tournament_id', $tournament->id)
                ->where('athlete_id', $performance->athlete_id)
                ->where('group_id', $category->group_id)
                ->first();
        }

        $entry ??= Entry::query()
            ->where('tournament_id', $tournament->id)
            ->where('athlete_id', $performance->athlete_id)
            ->get()
            ->first(fn (Entry $candidate) => $candidate->importSheet() !== null);

        $sheet = $entry?->importSheet();
        if ($sheet !== null) {
            $athleteIds = Entry::query()
                ->where('tournament_id', $tournament->id)
                ->get(['athlete_id', 'meta'])
                ->filter(fn (Entry $candidate) => $candidate->importSheet() === $sheet)
                ->pluck('athlete_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            return ['athlete_ids' => $athleteIds, 'label' => $sheet];
        }

        if ($category->group_id !== null) {
            $athleteIds = Entry::query()
                ->where('tournament_id', $tournament->id)
                ->where('group_id', $category->group_id)
                ->pluck('athlete_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($athleteIds !== []) {
                return ['athlete_ids' => $athleteIds, 'label' => $category->group?->name];
            }
        }

        return ['athlete_ids' => null, 'label' => null];
    }

    /**
     * Текущий рейтинг складывает все уже принятые виды каждой гимнастки из пула.
     *
     * @return array{place:?int,place_of:int,overall_total:?float,pool_label:?string}
     */
    private static function rankingSnapshot(Category $category, Performance $performance): array
    {
        $category->loadMissing('tournament');
        $pool = self::rankingPool($category, $performance);
        $poolAthleteIds = $pool['athlete_ids'];
        $categoryIds = $poolAthleteIds !== null
            ? $category->tournament?->categories()->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [$category->id]
            : self::groupCategoryIds($category);

        $published = Performance::query()
            ->whereIn('category_id', $categoryIds)
            ->when($poolAthleteIds !== null, fn ($query) => $query->whereIn('athlete_id', $poolAthleteIds))
            ->where('is_counted', true)
            ->whereNotNull('total')
            ->whereNotNull('published_at')
            ->whereNull('withdrawn_at')
            ->get(['id', 'athlete_id', 'total', 'e_score', 'a_score']);

        $rankings = [];
        foreach ($published as $publishedPerformance) {
            if ((int) $publishedPerformance->id === (int) $performance->id) {
                continue;
            }

            $athleteId = (int) $publishedPerformance->athlete_id;
            $rankings[$athleteId] ??= ['total' => 0.0, 'e' => 0.0, 'a' => 0.0];
            $rankings[$athleteId]['total'] += (float) $publishedPerformance->total;
            $rankings[$athleteId]['e'] += (float) ($publishedPerformance->e_score ?? 0);
            $rankings[$athleteId]['a'] += (float) ($publishedPerformance->a_score ?? 0);
        }

        if ($performance->published_at !== null
            && $performance->total !== null
            && $performance->is_counted
            && ! $performance->isWithdrawn()) {
            $athleteId = (int) $performance->athlete_id;
            $rankings[$athleteId] ??= ['total' => 0.0, 'e' => 0.0, 'a' => 0.0];
            $rankings[$athleteId]['total'] += (float) $performance->total;
            $rankings[$athleteId]['e'] += (float) ($performance->e_score ?? 0);
            $rankings[$athleteId]['a'] += (float) ($performance->a_score ?? 0);
        }

        $currentRanking = $rankings[(int) $performance->athlete_id] ?? null;
        $currentTotal = $currentRanking['total'] ?? null;
        $place = null;
        if ($currentTotal !== null && $currentTotal > 0.0004) {
            $higher = collect($rankings)
                ->except([(int) $performance->athlete_id])
                ->filter(fn (array $ranking) => self::rankingIsHigher($ranking, $currentRanking))
                ->map(fn (array $ranking) => self::rankingKey($ranking))
                ->unique()
                ->count();
            $place = $higher + 1;
        }

        $placeOf = $poolAthleteIds !== null
            ? count($poolAthleteIds)
            : collect($rankings)
                ->except([(int) $performance->athlete_id])
                ->filter(fn (array $ranking) => $ranking['total'] > 0.0004)
                ->count() + 1;

        return [
            'place' => $place,
            'place_of' => $placeOf,
            'overall_total' => $currentTotal !== null ? round($currentTotal, 3) : null,
            'pool_label' => $pool['label'],
        ];
    }

    /** @param array{total:float,e:float,a:float} $candidate
     * @param  array{total:float,e:float,a:float}  $current
     */
    private static function rankingIsHigher(array $candidate, array $current): bool
    {
        foreach (['total', 'e', 'a'] as $key) {
            $candidateValue = round($candidate[$key], self::PLACE_PRECISION);
            $currentValue = round($current[$key], self::PLACE_PRECISION);
            if ($candidateValue !== $currentValue) {
                return $candidateValue > $currentValue;
            }
        }

        return false;
    }

    /** @param array{total:float,e:float,a:float} $ranking */
    private static function rankingKey(array $ranking): string
    {
        return implode('|', array_map(
            fn (string $key) => number_format(
                round($ranking[$key], self::PLACE_PRECISION),
                self::PLACE_PRECISION,
                '.',
                '',
            ),
            ['total', 'e', 'a'],
        ));
    }

    /**
     * Предварительное место гимнастки по группе (год + буква): считаем лучших
     * соперниц (по опубликованному итогу) с итогом выше текущего.
     */
    public static function provisionalPlace(Category $category, Performance $perf): ?int
    {
        return self::rankingSnapshot($category, $perf)['place'];
    }

    /**
     * Сколько всего участниц в группе с опубликованным результатом (+ текущая).
     */
    public static function provisionalPlaceOf(Category $category, Performance $perf): int
    {
        return self::rankingSnapshot($category, $perf)['place_of'];
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
        $isVisibleOnBoard = $perf->published_at !== null;
        $inq = $perf->inquiries->sortByDesc('id')->first();
        $judgeSlots = SecretaryLiveUi::judgeSlots($perf, $category);
        $mainSlots = collect($judgeSlots)->filter(
            fn ($s) => in_array($s['label'], SecretaryLiveUi::AUTO_ADVANCE_REQUIRED_LABELS, true) && ! $s['inactive']
        );
        $submittedMain = $mainSlots->where('ok', true)->count();
        $requiredMain = $mainSlots->count();
        $ranking = $isVisibleOnBoard ? self::rankingSnapshot($category, $perf) : null;
        $place = $ranking['place'] ?? null;
        $placeOf = $ranking['place_of'] ?? null;
        $overallTotal = $ranking['overall_total'] ?? null;
        $notPerformed = $isVisibleOnBoard && $overallTotal !== null && abs($overallTotal) < 0.0005;

        $rev = md5(implode('|', [
            $perf->id, $perf->status, $phase,
            $perf->d_score, $perf->a_score, $perf->e_score, $perf->penalty, $perf->total, $overallTotal,
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
                'is_group' => $category->program === 'group' || (bool) $perf->athlete?->is_team,
                'members' => (bool) $perf->athlete?->is_team
                    ? $perf->athlete->members->map(fn ($m) => trim(($m->last_name ?? '').' '.($m->first_name ?? '')))->values()->all()
                    : [],
                'status' => $perf->status,
                'd' => $isVisibleOnBoard ? $perf->d_score : null,
                'a' => $isVisibleOnBoard ? $perf->a_score : null,
                'e' => $isVisibleOnBoard ? $perf->e_score : null,
                'penalty' => $isVisibleOnBoard ? $perf->penalty : null,
                'apparatus_score' => $isVisibleOnBoard && $perf->total !== null ? (float) $perf->total : null,
                'total' => $isVisibleOnBoard ? $overallTotal : null,
                'place' => $place,
                'place_of' => $placeOf,
                'pool_label' => $ranking['pool_label'] ?? null,
                'score_visible' => $isVisibleOnBoard,
                'not_performed' => $notPerformed,
                'actual_duration_seconds' => $perf->actual_duration_seconds,
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
            ->with(['categories' => fn ($q) => $q->where('is_published', true)->orderedByPerformanceTime()])
            ->orderByDesc('id')
            ->get();
    }
}
