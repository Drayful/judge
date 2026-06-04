<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Performance;
use App\Models\Tournament;
use Illuminate\Support\Collection;

/**
 * Сборка данных итогового протокола по (год рождения + категория).
 *
 * Логика подсчёта мест — как в образце results_*.xlsx:
 *   - у гимнастки несколько «видов» (по числу её выступлений в группе),
 *     каждый вид = Performance::total (D + A + E − штрафы);
 *   - Итог = сумма видов;
 *   - сортировка по убыванию Итога;
 *   - места «плотным» рангом (dense rank): равные суммы — одно место,
 *     следующее значение получает следующее целое без пропусков.
 */
class FinalProtocolService
{
    /** Точность сравнения сумм для определения равенства мест. */
    private const PLACE_PRECISION = 2;

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
            ->map(function (Collection $cats) use ($tournament) {
                /** @var Category $first */
                $first = $cats->first();
                $year = $first->resolvedBirthYear();
                $division = $first->resolvedDivision();

                $athletes = Performance::query()
                    ->whereIn('category_id', $cats->pluck('id'))
                    ->whereNotNull('total')
                    ->where('is_counted', true)
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
     * @return array{title:string, birth_year:?int, division:?string, max_vidi:int, rows:list<array{place:int, name:string, year:?int, club:string, vidi:list<float>, total:float}>}
     */
    public function build(Tournament $tournament, ?int $birthYear, ?string $division): array
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

            $rows[] = [
                'name' => trim(($athlete->last_name ?? '').' '.($athlete->first_name ?? '')),
                'year' => $athlete->birthdate?->year ?? $birthYear,
                'club' => trim((string) ($athlete->club ?? '')),
                'vidi' => $vidi,
                'total' => $total,
            ];
        }

        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        // Плотный ранг (dense rank) с округлением для сравнения.
        $place = 0;
        $prevRounded = null;
        foreach ($rows as &$row) {
            $rounded = round($row['total'], self::PLACE_PRECISION);
            if ($prevRounded === null || $rounded !== $prevRounded) {
                $place++;
                $prevRounded = $rounded;
            }
            $row['place'] = $place;
        }
        unset($row);

        return [
            'title' => $this->label($birthYear, $division),
            'birth_year' => $birthYear,
            'division' => $division,
            'max_vidi' => max(1, $maxVidi),
            'rows' => $rows,
        ];
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
