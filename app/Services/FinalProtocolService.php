<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Performance;
use App\Models\Tournament;
use App\Support\CompetitionPool;
use App\Support\PerformanceApparatus;
use Illuminate\Support\Collection;

/**
 * Сборка данных итогового протокола по (год рождения + категория).
 *
 * Логика подсчёта мест — как в образце results_*.xlsx:
 *   - у гимнастки несколько «видов» (по числу её выступлений в группе),
 *     каждый вид = Performance::total (D + A + E − штрафы);
 *   - Итог = сумма видов;
 *   - сортировка по убыванию Итога, затем суммы E, затем суммы A;
 *   - места «плотным» рангом (dense rank): одинаковые Итог, E и A — одно место,
 *     следующее сочетание получает следующее целое без пропусков.
 */
class FinalProtocolService
{
    /** Точность сравнения сумм для определения равенства мест. */
    private const PLACE_PRECISION = 3;

    /**
     * Доступные группы протоколов в турнире: по (год, категория).
     *
     * @return Collection<int, array{birth_year:?int, division:?string, key:string, label:string, athletes:int}>
     */
    public function groups(Tournament $tournament): Collection
    {
        $categories = $tournament->categories()->get();

        return $categories
            ->groupBy(fn (Category $c) => $this->key($c->resolvedBirthYear(), $c->resolvedDivision()))
            ->map(function (Collection $cats) {
                /** @var Category $first */
                $first = $cats->first();
                $year = $first->resolvedBirthYear();
                $division = $first->resolvedDivision();

                $athletes = Performance::query()
                    ->whereIn('category_id', $cats->pluck('id'))
                    ->whereNotNull('total')
                    ->where('is_counted', true)
                    ->whereNull('withdrawn_at')
                    ->distinct('athlete_id')
                    ->count('athlete_id');

                return [
                    'birth_year' => $year,
                    'division' => $division,
                    'key' => $this->key($year, $division),
                    'label' => $this->label($year, $division),
                    'athletes' => $athletes,
                ];
            })
            ->sortBy([
                fn ($a, $b) => ($a['birth_year'] ?? 0) <=> ($b['birth_year'] ?? 0),
                fn ($a, $b) => (string) ($a['division'] ?? '') <=> (string) ($b['division'] ?? ''),
            ])
            ->values();
    }

    /**
     * Строки итогового протокола одной группы.
     *
     * @return array{title:string, birth_year:?int, division:?string, max_vidi:int, rows:list<array{athlete_id:int, place:int, name:string, year:?int, club:string, vidi:list<float>, total:float}>}
     */
    public function build(Tournament $tournament, ?int $birthYear, ?string $division, bool $publishedOnly = false): array
    {
        return $this->buildGroup($tournament, $birthYear, $division, $publishedOnly);
    }

    /**
     * Итоговый протокол только по опубликованным результатам (публичное табло).
     *
     * @return array{title:string, birth_year:?int, division:?string, max_vidi:int, rows:list<array{athlete_id:int, place:int, name:string, year:?int, club:string, vidi:list<float>, total:float}>}
     */
    public function buildPublished(Tournament $tournament, ?int $birthYear, ?string $division): array
    {
        return $this->buildGroup($tournament, $birthYear, $division, true);
    }

    /**
     * @return array<int, array{athlete_id:int, place:int, name:string, club:string, total:float, vidi:list<float>}>
     */
    public function publishedAthletesById(Category $category): array
    {
        $category->loadMissing('tournament');
        $tournament = $category->tournament;
        if ($tournament === null) {
            return [];
        }

        $pool = CompetitionPool::resolve($category);
        $data = $this->buildGroup(
            $tournament,
            $category->resolvedBirthYear(),
            $category->resolvedDivision(),
            true,
            $pool['athlete_ids'],
        );

        $map = [];
        foreach ($data['rows'] as $row) {
            $map[$row['athlete_id']] = $row;
        }

        return $map;
    }

    /**
     * @return array{title:string, birth_year:?int, division:?string, max_vidi:int, rows:list<array{athlete_id:int, place:int, name:string, year:?int, club:string, vidi:list<float>, total:float}>}
     */
    private function buildGroup(
        Tournament $tournament,
        ?int $birthYear,
        ?string $division,
        bool $publishedOnly,
        ?array $athleteIds = null,
    ): array {
        $division = $division !== null && trim($division) !== '' ? strtoupper(trim($division)) : null;

        $categories = $tournament->categories()->get()->filter(
            fn (Category $c) => $c->resolvedBirthYear() === $birthYear
                && $c->resolvedDivision() === $division
        );

        $performances = Performance::query()
            ->with('athlete')
            ->whereIn('category_id', $categories->pluck('id'))
            ->whereNotNull('total')
            ->where('is_counted', true)
            ->whereNull('withdrawn_at')
            ->when($athleteIds !== null, fn ($query) => $query->whereIn('athlete_id', $athleteIds))
            ->when($publishedOnly, fn ($q) => $q->whereNotNull('published_at'))
            ->orderBy('athlete_id')
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        $byAthlete = $performances->groupBy('athlete_id');

        $rows = [];
        $maxVidi = 0;

        foreach ($byAthlete as $perfs) {
            /** @var Performance $firstPerf */
            $firstPerf = $perfs->first();
            $athlete = $firstPerf->athlete;
            if ($athlete === null) {
                continue;
            }

            $vidi = $perfs->map(fn (Performance $p) => round((float) $p->total, 3))->values()->all();
            $maxVidi = max($maxVidi, count($vidi));
            $total = round(array_sum($vidi), 3);
            $eTieBreak = round($perfs->sum(fn (Performance $p) => (float) ($p->e_score ?? 0)), 3);
            $aTieBreak = round($perfs->sum(fn (Performance $p) => (float) ($p->a_score ?? 0)), 3);

            $rows[] = [
                'athlete_id' => $athlete->id,
                'name' => trim(($athlete->last_name ?? '').' '.($athlete->first_name ?? '')),
                'year' => $athlete->birthdate?->year ?? $birthYear,
                'club' => trim((string) ($athlete->club ?? '')),
                'vidi' => $vidi,
                'total' => $total,
                '_e_tiebreak' => $eTieBreak,
                '_a_tiebreak' => $aTieBreak,
            ];
        }

        $this->sortAndAssignPlaces($rows, 'total');

        return [
            'title' => $this->label($birthYear, $division),
            'birth_year' => $birthYear,
            'division' => $division,
            'max_vidi' => max(1, $maxVidi),
            'rows' => $rows,
        ];
    }

    /**
     * Протоколы ПО ВИДАМ (по предметам) для группы (год + категория): для каждого
     * предмета — отдельный ранжированный список по оценке этого вида.
     *
     * @return array{title:string, birth_year:?int, division:?string, apparatus:list<array{label:string, rows:list<array{athlete_id:int, place:int, name:string, year:?int, club:string, score:float}>}>}
     */
    public function buildByApparatus(Tournament $tournament, ?int $birthYear, ?string $division, bool $publishedOnly = false): array
    {
        $division = $division !== null && trim($division) !== '' ? strtoupper(trim($division)) : null;

        $categories = $tournament->categories()->get()->filter(
            fn (Category $c) => $c->resolvedBirthYear() === $birthYear
                && $c->resolvedDivision() === $division
        );

        $performances = Performance::query()
            ->with('athlete')
            ->whereIn('category_id', $categories->pluck('id'))
            ->whereNotNull('total')
            ->where('is_counted', true)
            ->whereNull('withdrawn_at')
            ->when($publishedOnly, fn ($q) => $q->whereNotNull('published_at'))
            ->get();

        $byApparatus = $performances->groupBy(fn (Performance $p) => trim((string) ($p->apparatus ?? '—')) ?: '—');

        $apparatus = [];
        foreach ($byApparatus as $label => $perfs) {
            $rows = [];
            foreach ($perfs as $perf) {
                $athlete = $perf->athlete;
                if ($athlete === null) {
                    continue;
                }
                // На одну гимнастку по одному предмету — одна строка (на всякий случай — лучший результат).
                $score = round((float) $perf->total, 3);
                $eTieBreak = round((float) ($perf->e_score ?? 0), 3);
                $aTieBreak = round((float) ($perf->a_score ?? 0), 3);
                $candidate = [
                    'athlete_id' => $athlete->id,
                    'name' => trim(($athlete->last_name ?? '').' '.($athlete->first_name ?? '')),
                    'year' => $athlete->birthdate?->year ?? $birthYear,
                    'club' => trim((string) ($athlete->club ?? '')),
                    'score' => $score,
                    '_e_tiebreak' => $eTieBreak,
                    '_a_tiebreak' => $aTieBreak,
                ];
                if (isset($rows[$athlete->id])
                    && $this->compareRankedRows($rows[$athlete->id], $candidate, 'score') <= 0) {
                    continue;
                }
                $rows[$athlete->id] = $candidate;
            }

            $rows = array_values($rows);
            $this->sortAndAssignPlaces($rows, 'score');

            $apparatus[] = ['label' => (string) $label, 'rows' => $rows];
        }

        // Порядок предметов: как в справочнике РГ, затем прочие по алфавиту.
        $order = array_flip(PerformanceApparatus::RG_APPARATUS);
        $order[PerformanceApparatus::BODY_ONLY_LABEL] = -1; // БП вперёд
        usort($apparatus, function ($a, $b) use ($order) {
            $ra = $order[$a['label']] ?? 999;
            $rb = $order[$b['label']] ?? 999;

            return $ra !== $rb ? $ra <=> $rb : strcmp($a['label'], $b['label']);
        });

        return [
            'title' => $this->label($birthYear, $division),
            'birth_year' => $birthYear,
            'division' => $division,
            'apparatus' => $apparatus,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sortAndAssignPlaces(array &$rows, string $scoreKey): void
    {
        usort($rows, fn (array $a, array $b): int => $this->compareRankedRows($a, $b, $scoreKey));

        $place = 0;
        $previousKey = null;
        foreach ($rows as &$row) {
            if (abs((float) $row[$scoreKey]) < 0.0005) {
                $row['place'] = null;
                $row['status'] = 'not_performed';
                unset($row['_e_tiebreak'], $row['_a_tiebreak']);

                continue;
            }

            $rankingKey = $this->rankingKey($row, $scoreKey);
            if ($previousKey === null || $rankingKey !== $previousKey) {
                $place++;
                $previousKey = $rankingKey;
            }
            $row['place'] = $place;
            $row['status'] = 'ranked';
            unset($row['_e_tiebreak'], $row['_a_tiebreak']);
        }
        unset($row);
    }

    private function compareRankedRows(array $a, array $b, string $scoreKey): int
    {
        $aNotPerformed = abs((float) $a[$scoreKey]) < 0.0005;
        $bNotPerformed = abs((float) $b[$scoreKey]) < 0.0005;
        if ($aNotPerformed !== $bNotPerformed) {
            return $aNotPerformed ? 1 : -1;
        }

        foreach ([$scoreKey, '_e_tiebreak', '_a_tiebreak'] as $key) {
            $comparison = round((float) ($b[$key] ?? 0), self::PLACE_PRECISION)
                <=> round((float) ($a[$key] ?? 0), self::PLACE_PRECISION);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return ((int) ($a['athlete_id'] ?? 0)) <=> ((int) ($b['athlete_id'] ?? 0));
    }

    private function rankingKey(array $row, string $scoreKey): string
    {
        return implode('|', array_map(
            fn (string $key) => number_format(
                round((float) ($row[$key] ?? 0), self::PLACE_PRECISION),
                self::PLACE_PRECISION,
                '.',
                '',
            ),
            [$scoreKey, '_e_tiebreak', '_a_tiebreak'],
        ));
    }

    private function key(?int $year, ?string $division): string
    {
        return ($year ?? '0').'|'.($division ?? '');
    }

    private function label(?int $year, ?string $division): string
    {
        $yearPart = $year ? $year.' г.р.' : 'Без года';
        $divPart = $division ? ' — категория '.$division : '';

        return $yearPart.$divPart;
    }
}
