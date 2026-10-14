<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\Group;
use App\Models\Tournament;

/** Группа из пула: непривязанные записи одной программы, года и категории. */
class GroupPoolService
{
    /**
     * @param  list<string>  $apparatus
     */
    public function createFromPool(Tournament $tournament, string $program, ?int $birthYear, ?string $division, array $apparatus, string $numberMode, string $apparatusMode = 'fixed', ?int $apparatusCount = null): Group
    {
        // метка пула («2020 и мл» и т.п.), если есть у entries.
        $label = $this->poolQuery($tournament, $program, $birthYear, $division)->value('meta->label');

        $group = Group::query()->create([
            'tournament_id' => $tournament->id,
            'program' => $program,
            'birth_year' => $birthYear,
            'birth_year_label' => is_string($label) ? $label : null,
            'division' => $division,
            'name' => $this->groupName($birthYear, $division, is_string($label) ? $label : null),
            'apparatus' => $apparatus,
            'apparatus_selection_mode' => $apparatusMode,
            'apparatus_count' => $apparatusCount,
            'number_mode' => $numberMode,
            'order_index' => (int) (Group::query()->where('tournament_id', $tournament->id)->max('order_index') ?? 0) + 1,
        ]);

        $this->poolQuery($tournament, $program, $birthYear, $division)->update(['group_id' => $group->id]);

        return $group;
    }

    public function groupName(?int $birthYear, ?string $division, ?string $label): string
    {
        $yearPart = is_string($label) && trim($label) !== ''
            ? trim($label)
            : ($birthYear ? $birthYear.' г.р.' : 'Без года');

        return $division ? $yearPart.', '.$division : $yearPart;
    }

    private function poolQuery(Tournament $tournament, string $program, ?int $birthYear, ?string $division)
    {
        return Entry::query()
            ->where('tournament_id', $tournament->id)
            ->whereNull('group_id')
            ->where('program', $program)
            ->when($birthYear !== null, fn ($q) => $q->where('birth_year', $birthYear), fn ($q) => $q->whereNull('birth_year'))
            ->when($division !== null, fn ($q) => $q->where('division', $division), fn ($q) => $q->whereNull('division'));
    }
}
