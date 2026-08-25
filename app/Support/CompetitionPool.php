<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Entry;
use App\Models\Performance;
use Illuminate\Support\Collection;

class CompetitionPool
{
    /**
     * Пул участниц для Live-места и общей таблицы:
     * один Excel-лист, год рождения и категория независимо от потока.
     *
     * @return array{athlete_ids:?list<int>,label:?string}
     */
    public static function resolve(Category $category, ?Performance $performance = null): array
    {
        $category->loadMissing('tournament');
        $tournament = $category->tournament;
        if ($tournament === null) {
            return ['athlete_ids' => null, 'label' => null];
        }

        $anchorEntries = collect();
        if ($category->group_id !== null) {
            $anchorEntries = Entry::query()
                ->where('tournament_id', $tournament->id)
                ->where('group_id', $category->group_id)
                ->get();
        }

        if ($performance !== null) {
            $performanceEntries = Entry::query()
                ->where('tournament_id', $tournament->id)
                ->where('athlete_id', $performance->athlete_id)
                ->get();

            if ($category->group_id !== null) {
                $sameGroup = $performanceEntries->where('group_id', $category->group_id);
                if ($sameGroup->isNotEmpty()) {
                    $performanceEntries = $sameGroup;
                }
            }

            $anchorEntries = $performanceEntries->isNotEmpty()
                ? $performanceEntries
                : $anchorEntries;
        }

        if ($anchorEntries->isEmpty()) {
            $categoryAthleteIds = $category->performances()
                ->pluck('athlete_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($categoryAthleteIds->isNotEmpty()) {
                $anchorEntries = Entry::query()
                    ->where('tournament_id', $tournament->id)
                    ->whereIn('athlete_id', $categoryAthleteIds)
                    ->get();
            }
        }

        $sheets = $anchorEntries
            ->map(fn (Entry $entry) => $entry->importSheet())
            ->filter()
            ->unique()
            ->values();

        if ($sheets->isNotEmpty()) {
            $sheetEntries = Entry::query()
                ->where('tournament_id', $tournament->id)
                ->get(['athlete_id', 'birth_year', 'division', 'meta'])
                ->filter(fn (Entry $entry) => $sheets->contains($entry->importSheet()));

            $classifiedEntries = self::classifiedEntries($sheetEntries, $category);
            // Совместимость со старыми импортами без заполненных birth_year/division.
            $poolEntries = $classifiedEntries->isNotEmpty() ? $classifiedEntries : $sheetEntries;

            return [
                'athlete_ids' => self::athleteIds($poolEntries),
                'label' => $sheets->implode(', '),
            ];
        }

        if ($category->group_id !== null && $anchorEntries->isNotEmpty()) {
            return [
                'athlete_ids' => self::athleteIds($anchorEntries),
                'label' => $category->group?->name,
            ];
        }

        return ['athlete_ids' => null, 'label' => null];
    }

    /** @param Collection<int, Entry> $entries */
    private static function classifiedEntries(Collection $entries, Category $category): Collection
    {
        $birthYear = $category->resolvedBirthYear();
        $division = $category->resolvedDivision();

        return $entries->filter(function (Entry $entry) use ($birthYear, $division): bool {
            if ($birthYear !== null && (int) $entry->birth_year !== $birthYear) {
                return false;
            }

            return $division === null
                || strtoupper(trim((string) $entry->division)) === $division;
        });
    }

    /**
     * @param  Collection<int, Entry>  $entries
     * @return list<int>
     */
    private static function athleteIds(Collection $entries): array
    {
        return $entries
            ->pluck('athlete_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
