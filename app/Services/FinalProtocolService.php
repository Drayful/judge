<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Entry;
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
     * @return Collection<int, array{program:string, birth_year:?int, division:?string, group_sheet:?string, key:string, label:string, athletes:int}>
     */
    public function groups(Tournament $tournament): Collection
    {
        $categories = $tournament->categories()->get();

        $individual = $categories
            ->where('program', '!=', 'group')
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
                    'program' => 'individual',
                    'birth_year' => $year,
                    'division' => $division,
                    'group_sheet' => null,
                    'key' => 'individual|'.$this->key($year, $division),
                    'label' => $this->label($year, $division),
                    'athletes' => $athletes,
                ];
            });

        $groupEntries = Entry::query()
            ->with('athlete.members')
            ->where('tournament_id', $tournament->id)
            ->where('program', 'group')
            ->get();
        $completedTeamIds = Performance::query()
            ->whereIn('category_id', $categories->where('program', 'group')->pluck('id'))
            ->whereNotNull('total')
            ->where('is_counted', true)
            ->whereNull('withdrawn_at')
            ->pluck('athlete_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        $group = $groupEntries
            ->groupBy(function (Entry $entry): string {
                $sheet = $entry->importSheet();

                return $sheet !== null
                    ? 'sheet|'.$sheet
                    : 'fallback|'.$this->key($entry->birth_year, $entry->division);
            })
            ->map(function (Collection $entries, string $key) use ($completedTeamIds) {
                /** @var Entry $first */
                $first = $entries->first();
                $sheet = $first->importSheet();
                $years = $entries
                    ->flatMap(function (Entry $entry) {
                        $memberYears = $entry->athlete?->members
                            ?->map(fn ($member) => $member->birthdate?->year)
                            ->filter()
                            ->values() ?? collect();

                        return $memberYears->isNotEmpty() ? $memberYears : collect([$entry->birth_year])->filter();
                    })
                    ->map(fn ($year) => (int) $year)
                    ->unique()
                    ->sort()
                    ->values();
                $teamIds = $entries->pluck('athlete_id')->map(fn ($id) => (int) $id)->unique();

                return [
                    'program' => 'group',
                    'birth_year' => $sheet === null ? $first->birth_year : null,
                    'division' => $sheet === null ? $first->division : null,
                    'group_sheet' => $sheet,
                    'key' => 'group|'.$key,
                    'label' => $this->groupLabel($years, $sheet),
                    'athletes' => $teamIds->intersect($completedTeamIds)->count(),
                ];
            });

        return $individual
            ->concat($group)
            ->sortBy([
                fn ($a, $b) => ($a['program'] === 'individual' ? 0 : 1) <=> ($b['program'] === 'individual' ? 0 : 1),
                fn ($a, $b) => ($a['birth_year'] ?? 0) <=> ($b['birth_year'] ?? 0),
                fn ($a, $b) => (string) $a['label'] <=> (string) $b['label'],
            ])
            ->values();
    }

    /**
     * Групповой итоговый протокол: одна рейтинговая строка на команду и ростер
     * участниц под ней. Импортированный Excel-лист образует единый пул, даже если
     * команды были разнесены по нескольким системным группам и потокам.
     *
     * @return array{title:string, program:string, birth_year:?int, division:?string, group_sheet:?string, max_vidi:int, rows:list<array{athlete_id:int, place:?int, status:string, name:string, club:string, members:list<array{name:string, year:?int}>, vidi:list<?float>, total:float}>}
     */
    public function buildTeams(
        Tournament $tournament,
        ?int $birthYear,
        ?string $division,
        ?string $groupSheet = null,
    ): array {
        $division = $division !== null && trim($division) !== '' ? strtoupper(trim($division)) : null;
        $groupSheet = $groupSheet !== null && trim($groupSheet) !== '' ? trim($groupSheet) : null;

        $entries = Entry::query()
            ->with('athlete.members')
            ->where('tournament_id', $tournament->id)
            ->where('program', 'group')
            ->get()
            ->filter(function (Entry $entry) use ($birthYear, $division, $groupSheet): bool {
                if ($groupSheet !== null) {
                    return $entry->importSheet() === $groupSheet;
                }

                return $entry->birth_year === $birthYear
                    && $this->normalizedDivision($entry->division) === $division;
            })
            ->values();

        $teamIds = $entries->pluck('athlete_id')->map(fn ($id) => (int) $id)->unique()->values();
        $categories = $tournament->categories()->where('program', 'group')->get();
        if ($teamIds->isEmpty() && $groupSheet === null) {
            $categories = $categories->filter(
                fn (Category $category) => $category->resolvedBirthYear() === $birthYear
                    && $category->resolvedDivision() === $division
            );
            $teamIds = Performance::query()
                ->whereIn('category_id', $categories->pluck('id'))
                ->pluck('athlete_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
        }

        $performances = Performance::query()
            ->with('athlete.members')
            ->whereIn('category_id', $categories->pluck('id'))
            ->whereIn('athlete_id', $teamIds)
            ->whereNotNull('total')
            ->where('is_counted', true)
            ->whereNull('withdrawn_at')
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        $apparatus = $performances
            ->pluck('apparatus')
            ->map(fn ($label) => trim((string) $label))
            ->filter()
            ->unique()
            ->values();
        $entryByAthlete = $entries->keyBy('athlete_id');
        $rows = [];

        foreach ($performances->groupBy('athlete_id') as $athleteId => $perfs) {
            /** @var Performance $firstPerformance */
            $firstPerformance = $perfs->first();
            $team = $firstPerformance->athlete;
            if ($team === null) {
                continue;
            }

            $scoresByApparatus = $perfs->keyBy(fn (Performance $performance) => trim((string) $performance->apparatus));
            $vidi = $apparatus->map(function (string $label) use ($scoresByApparatus): ?float {
                $performance = $scoresByApparatus->get($label);

                return $performance !== null ? round((float) $performance->total, 3) : null;
            })->all();
            $entry = $entryByAthlete->get((int) $athleteId);
            $members = $team->members->map(fn ($member) => [
                'name' => trim(($member->last_name ?? '').' '.($member->first_name ?? '')),
                'year' => $member->birthdate?->year,
            ])->values()->all();

            $rows[] = [
                'athlete_id' => (int) $athleteId,
                'name' => $this->teamName($team->last_name, $team->first_name),
                'year' => null,
                'club' => trim((string) ($team->club ?: $entry?->club ?: '')),
                'members' => $members,
                'vidi' => $vidi,
                'total' => round(array_sum(array_filter($vidi, fn ($score) => $score !== null)), 3),
                '_e_tiebreak' => round($perfs->sum(fn (Performance $performance) => (float) ($performance->e_score ?? 0)), 3),
                '_a_tiebreak' => round($perfs->sum(fn (Performance $performance) => (float) ($performance->a_score ?? 0)), 3),
            ];
        }

        $this->sortAndAssignPlaces($rows, 'total');
        $years = collect($rows)
            ->flatMap(fn (array $row) => collect($row['members'])->pluck('year'))
            ->filter()
            ->map(fn ($year) => (int) $year)
            ->unique()
            ->sort()
            ->values();
        if ($years->isEmpty()) {
            $years = $entries->pluck('birth_year')->filter()->map(fn ($year) => (int) $year)->unique()->sort()->values();
        }

        return [
            'title' => $this->groupProtocolTitle($years),
            'program' => 'group',
            'birth_year' => $birthYear,
            'division' => $division,
            'group_sheet' => $groupSheet,
            'max_vidi' => max(1, $apparatus->count()),
            'rows' => $rows,
        ];
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
     * Итоговый протокол с явным разделением личной и групповой программы.
     *
     * @return array{title:string, birth_year:?int, division:?string, max_vidi:int, rows:list<array{athlete_id:int, place:int, name:string, year:?int, club:string, vidi:list<float>, total:float}>}
     */
    public function buildForProgram(Tournament $tournament, ?int $birthYear, ?string $division, string $program): array
    {
        return $this->buildGroup($tournament, $birthYear, $division, false, null, $program);
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
        return $this->poolAthletesById($category, true);
    }

    /**
     * Текущие места по полному Excel-пулу категории. Для Live и просмотра
     * потока учитываются все уже рассчитанные результаты, независимо от того,
     * успел ли оператор табло опубликовать гимнастку.
     *
     * @return array<int, array{athlete_id:int, place:int, name:string, club:string, total:float, vidi:list<float>}>
     */
    public function poolAthletesById(Category $category, bool $publishedOnly = false): array
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
            $publishedOnly,
            $pool['athlete_ids'],
            $category->program,
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
        ?string $program = null,
    ): array {
        $division = $division !== null && trim($division) !== '' ? strtoupper(trim($division)) : null;

        $categories = $tournament->categories()->get()->filter(
            fn (Category $c) => $c->resolvedBirthYear() === $birthYear
                && $c->resolvedDivision() === $division
                && ($program === null || $c->program === $program)
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
    public function buildByApparatus(
        Tournament $tournament,
        ?int $birthYear,
        ?string $division,
        bool $publishedOnly = false,
        ?string $program = null,
    ): array {
        $division = $division !== null && trim($division) !== '' ? strtoupper(trim($division)) : null;

        $categories = $tournament->categories()->get()->filter(
            fn (Category $c) => $c->resolvedBirthYear() === $birthYear
                && $c->resolvedDivision() === $division
                && ($program === null || $c->program === $program)
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

    private function normalizedDivision(?string $division): ?string
    {
        return $division !== null && trim($division) !== '' ? strtoupper(trim($division)) : null;
    }

    private function teamName(?string $lastName, ?string $firstName): string
    {
        $firstName = trim((string) $firstName);
        if ($firstName === '—' || $firstName === '-') {
            $firstName = '';
        }

        return trim(trim((string) $lastName).' '.$firstName);
    }

    /** @param  Collection<int, int>  $years */
    private function groupProtocolTitle(Collection $years): string
    {
        if ($years->isEmpty()) {
            return 'Групповые';
        }

        $from = (int) $years->min();
        $to = (int) $years->max();
        $range = $from === $to ? (string) $from : $from.'-'.$to;

        return $range.' г.р. Групповые';
    }

    /** @param  Collection<int, int>  $years */
    private function groupLabel(Collection $years, ?string $sheet): string
    {
        if ($years->isNotEmpty()) {
            return $this->groupProtocolTitle($years);
        }

        return $sheet !== null ? $sheet : 'Групповые';
    }
}
