<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Performance;
use Illuminate\Support\Collection;

class SecretaryLiveUi
{
    /** Слоты, после заполнения которых считаем «все основные судьи выставили» (LINE/RESP не обязательны). */
    public const AUTO_ADVANCE_REQUIRED_LABELS = ['DB1', 'DB2', 'DA1', 'DA2', 'A1', 'A2', 'A3', 'A4', 'E1', 'E2', 'E3', 'E4'];

    /** Полный список слотов бригады в порядке отображения. */
    public const ALL_JUDGE_SLOTS = ['DB1', 'DB2', 'DA1', 'DA2', 'A1', 'A2', 'A3', 'A4', 'E1', 'E2', 'E3', 'E4', 'LINE1', 'LINE2', 'TIME', 'RESP'];

    /**
     * Список неактивных слотов для категории (или пустой массив).
     *
     * @return array<int, string>
     */
    public static function inactiveSlots(?Category $category): array
    {
        return $category?->inactiveJudgeSlotList() ?? [];
    }

    public static function isSlotInactive(?Category $category, string $slot): bool
    {
        return in_array(strtoupper($slot), self::inactiveSlots($category), true);
    }

    /**
     * @return Collection<int, Performance>
     */
    public static function orderedPerformances(Collection $performances): Collection
    {
        return $performances->sortBy([
            ['order_index', 'asc'],
            ['id', 'asc'],
        ])->values();
    }

    public static function currentPerformance(Collection $ordered): ?Performance
    {
        return $ordered->firstWhere('status', 'performing')
            ?? $ordered->firstWhere('status', 'on_deck')
            ?? $ordered->firstWhere('status', 'scheduled');
    }

    public static function nextAfter(Collection $ordered, ?Performance $current): ?Performance
    {
        if (! $current) {
            return null;
        }
        $idx = $ordered->search(fn ($p) => $p->id === $current->id);
        if ($idx === false) {
            return null;
        }

        return $ordered->get((int) $idx + 1);
    }

    public static function streamStatus(?Performance $current): string
    {
        if (! $current) {
            return 'empty';
        }

        return match ($current->status) {
            'performing' => $current->finalized_at ? 'finalized' : 'waiting_scores',
            'on_deck' => 'on_deck',
            'scheduled' => 'scheduled',
            'done' => 'done',
            default => $current->status,
        };
    }

    /**
     * Слоты судей как на табло: зелёная точка = оценка отправлена (есть submitted_at).
     *
     * Сопоставление по user.slot, а не по порядку записей (DB2 может отправить раньше DB1).
     *
     * @return array<int, array{label: string, ok: bool, inactive: bool}>
     */
    public static function judgeSlots(?Performance $perf, ?Category $category = null): array
    {
        $labels = self::ALL_JUDGE_SLOTS;
        $category = $category ?? $perf?->category;
        $inactive = self::inactiveSlots($category);

        if (! $perf) {
            return array_map(fn ($l) => [
                'label' => $l,
                'ok' => false,
                'inactive' => in_array($l, $inactive, true),
            ], $labels);
        }

        $perf->loadMissing('judgeScores.judge');
        $scores = $perf->judgeScores ?? collect();

        $slotMap = [];
        foreach ($scores as $s) {
            $slot = $s->judge?->slot;
            if ($slot && $s->submitted_at !== null) {
                $slotMap[$slot] = true;
            }
        }

        // Fallback по позиции: если slot у пользователя не задан, считаем порядком пришедших оценок.
        $aSorted = $scores->where('panel', 'a')->sortBy('id')->values();
        $eSorted = $scores->where('panel', 'e')->sortBy('id')->values();
        $dbSorted = $scores->where('panel', 'd')->where('subpanel', 'db')->sortBy('id')->values();
        $daSorted = $scores->where('panel', 'd')->where('subpanel', 'da')->sortBy('id')->values();
        $lineSorted = $scores->where('panel', 'penalty')->where('penalty_type', 'line')->sortBy('id')->values();

        $byPosition = function ($coll, int $i): bool {
            $row = $coll->get($i);

            return $row !== null && $row->submitted_at !== null;
        };

        $out = [];
        foreach ($labels as $label) {
            $isInactive = in_array($label, $inactive, true);

            if (isset($slotMap[$label])) {
                $out[] = ['label' => $label, 'ok' => true, 'inactive' => $isInactive];
                continue;
            }
            $ok = match ($label) {
                'DB1' => $byPosition($dbSorted, 0),
                'DB2' => $byPosition($dbSorted, 1),
                'DA1' => $byPosition($daSorted, 0),
                'DA2' => $byPosition($daSorted, 1),
                'A1' => $byPosition($aSorted, 0),
                'A2' => $byPosition($aSorted, 1),
                'A3' => $byPosition($aSorted, 2),
                'A4' => $byPosition($aSorted, 3),
                'E1' => $byPosition($eSorted, 0),
                'E2' => $byPosition($eSorted, 1),
                'E3' => $byPosition($eSorted, 2),
                'E4' => $byPosition($eSorted, 3),
                'LINE1' => $byPosition($lineSorted, 0),
                'LINE2' => $byPosition($lineSorted, 1),
                'TIME' => $scores->contains(fn ($s) => $s->panel === 'penalty' && $s->penalty_type === 'time' && $s->submitted_at !== null),
                'RESP' => $scores->contains(fn ($s) => $s->panel === 'penalty' && $s->penalty_type === 'music' && $s->submitted_at !== null),
                default => false,
            };
            $out[] = ['label' => $label, 'ok' => $ok, 'inactive' => $isInactive];
        }

        return $out;
    }

    /**
     * Готовность к автопереходу: все активные основные слоты (D-DB/DA, A×4, E×4) получили submitted_at.
     * Неактивные слоты пропускаются — бригаду можно собрать неполным составом.
     */
    public static function scoresCompleteForAutoAdvance(?Performance $perf, ?Category $category = null): bool
    {
        if (! $perf) {
            return false;
        }

        $category = $category ?? $perf->category;
        $inactive = self::inactiveSlots($category);
        $slots = self::judgeSlots($perf, $category);

        $hasAtLeastOneActive = false;
        foreach (self::AUTO_ADVANCE_REQUIRED_LABELS as $label) {
            if (in_array($label, $inactive, true)) {
                continue;
            }
            $hasAtLeastOneActive = true;
            $row = collect($slots)->firstWhere('label', $label);
            if (! $row || ! $row['ok']) {
                return false;
            }
        }

        // Если ВСЕ обязательные слоты выключены — поведение автоперехода теряет смысл, считаем «не готово».
        return $hasAtLeastOneActive;
    }

    /**
     * Фиксированные колонки как на макете + значения из judge_scores.
     *
     * @return array{columns: array<int, string>, values: array<string, string>, penalty: array<string, bool>, inactive: array<string, bool>}
     */
    public static function fixedScoreMatrix(?Performance $perf, ?Category $category = null): array
    {
        $columns = self::ALL_JUDGE_SLOTS;
        $values = array_fill_keys($columns, '—');
        $penalty = array_fill_keys($columns, false);
        $category = $category ?? $perf?->category;
        $inactiveList = self::inactiveSlots($category);
        $inactive = array_fill_keys($columns, false);
        foreach ($inactiveList as $slot) {
            if (array_key_exists($slot, $inactive)) {
                $inactive[$slot] = true;
                $values[$slot] = 'off';
            }
        }

        if (! $perf) {
            return compact('columns', 'values', 'penalty', 'inactive');
        }

        $perf->loadMissing('judgeScores.judge');
        $scores = $perf->judgeScores;

        $fmt = static fn ($v) => $v !== null && $v !== '' ? number_format((float) $v, 3, '.', '') : '—';

        // 1) Сначала пробуем по слоту судьи (надёжно). Неактивные слоты не перезаписываются.
        foreach ($scores as $s) {
            $slot = $s->judge?->slot;
            if ($slot && array_key_exists($slot, $values) && ! $inactive[$slot] && $s->score !== null) {
                $values[$slot] = $fmt($s->score);
                if ($s->panel === 'penalty') {
                    $penalty[$slot] = true;
                }
            }
        }

        // 2) Резерв: незаполненные слоты — добиваем по порядку id (как раньше),
        // пропуская неактивные позиции — оценки сдвигаются на следующие активные слоты.
        $fillByOrder = function (string $prefix, $coll, int $count) use (&$values, $fmt, $inactive) {
            $i = 0;
            foreach ($coll as $row) {
                if ($row->score === null) {
                    continue;
                }
                while ($i < $count) {
                    $key = $prefix.($i + 1);
                    $i++;
                    if (! ($inactive[$key] ?? false) && $values[$key] === '—') {
                        $values[$key] = $fmt($row->score);
                        break;
                    }
                }
                if ($i >= $count) {
                    break;
                }
            }
        };

        $fillByOrder('DB', $scores->where('panel', 'd')->where('subpanel', 'db')->sortBy('id')->values(), 2);
        $fillByOrder('DA', $scores->where('panel', 'd')->where('subpanel', 'da')->sortBy('id')->values(), 2);
        $fillByOrder('A', $scores->where('panel', 'a')->sortBy('id')->values(), 4);
        $fillByOrder('E', $scores->where('panel', 'e')->sortBy('id')->values(), 4);

        $lineList = $scores->where('panel', 'penalty')->where('penalty_type', 'line')->sortBy('id')->values();
        foreach ([0 => 'LINE1', 1 => 'LINE2'] as $i => $key) {
            if (isset($lineList[$i]) && ! $inactive[$key] && $values[$key] === '—') {
                $values[$key] = $fmt($lineList[$i]->score);
                $penalty[$key] = true;
            }
        }

        $time = $scores->first(fn ($s) => $s->panel === 'penalty' && $s->penalty_type === 'time');
        if ($time && ! $inactive['TIME'] && $values['TIME'] === '—') {
            $values['TIME'] = $fmt($time->score);
            $penalty['TIME'] = true;
        }

        $resp = $scores->first(fn ($s) => $s->panel === 'penalty' && $s->penalty_type === 'music');
        if ($resp && ! $inactive['RESP'] && $values['RESP'] === '—') {
            $values['RESP'] = $fmt($resp->score);
            $penalty['RESP'] = true;
        }

        return compact('columns', 'values', 'penalty', 'inactive');
    }

    public static function formatScore(?float $v): string
    {
        if ($v === null) {
            return '—';
        }

        return number_format($v, 3, '.', '');
    }
}
