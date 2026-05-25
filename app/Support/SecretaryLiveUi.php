<?php

namespace App\Support;

use App\Models\Performance;
use Illuminate\Support\Collection;

class SecretaryLiveUi
{
    /** Слоты, после заполнения которых считаем «все основные судьи выставили» (LINE/RESP не обязательны). */
    public const AUTO_ADVANCE_REQUIRED_LABELS = ['DB1', 'DB2', 'DA1', 'DA2', 'A1', 'A2', 'A3', 'A4', 'E1', 'E2', 'E3', 'E4'];

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
     * @return array<int, array{label: string, ok: bool}>
     */
    public static function judgeSlots(?Performance $perf): array
    {
        $labels = ['DB1', 'DB2', 'DA1', 'DA2', 'A1', 'A2', 'A3', 'A4', 'E1', 'E2', 'E3', 'E4', 'LINE1', 'LINE2', 'TIME', 'RESP'];

        if (! $perf) {
            return array_map(fn ($l) => ['label' => $l, 'ok' => false], $labels);
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
            if (isset($slotMap[$label])) {
                $out[] = ['label' => $label, 'ok' => true];
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
            $out[] = ['label' => $label, 'ok' => $ok];
        }

        return $out;
    }

    /**
     * Готовность к автопереходу: все основные слоты (D-DB/DA, A×4, E×4) получили submitted_at.
     */
    public static function scoresCompleteForAutoAdvance(?Performance $perf): bool
    {
        if (! $perf) {
            return false;
        }

        $slots = self::judgeSlots($perf);
        foreach (self::AUTO_ADVANCE_REQUIRED_LABELS as $label) {
            $row = collect($slots)->firstWhere('label', $label);
            if (! $row || ! $row['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Фиксированные колонки как на макете + значения из judge_scores.
     *
     * @return array{columns: array<int, string>, values: array<string, string>, penalty: array<string, bool>}
     */
    public static function fixedScoreMatrix(?Performance $perf): array
    {
        $columns = ['DB1', 'DB2', 'DA1', 'DA2', 'A1', 'A2', 'A3', 'A4', 'E1', 'E2', 'E3', 'E4', 'LINE1', 'LINE2', 'TIME', 'RESP'];
        $values = array_fill_keys($columns, '—');
        $penalty = array_fill_keys($columns, false);

        if (! $perf) {
            return compact('columns', 'values', 'penalty');
        }

        $perf->loadMissing('judgeScores.judge');
        $scores = $perf->judgeScores;

        $fmt = static fn ($v) => $v !== null && $v !== '' ? number_format((float) $v, 3, '.', '') : '—';

        // 1) Сначала пробуем по слоту судьи (надёжно).
        foreach ($scores as $s) {
            $slot = $s->judge?->slot;
            if ($slot && array_key_exists($slot, $values) && $s->score !== null) {
                $values[$slot] = $fmt($s->score);
                if ($s->panel === 'penalty') {
                    $penalty[$slot] = true;
                }
            }
        }

        // 2) Резерв: незаполненные слоты — добиваем по порядку id (как раньше).
        $fillByOrder = function (string $prefix, $coll, int $count) use (&$values, $fmt) {
            $i = 0;
            foreach ($coll as $row) {
                if ($i >= $count) {
                    break;
                }
                $key = $prefix.($i + 1);
                if ($values[$key] === '—' && $row->score !== null) {
                    $values[$key] = $fmt($row->score);
                }
                $i++;
            }
        };

        $fillByOrder('DB', $scores->where('panel', 'd')->where('subpanel', 'db')->sortBy('id')->values(), 2);
        $fillByOrder('DA', $scores->where('panel', 'd')->where('subpanel', 'da')->sortBy('id')->values(), 2);
        $fillByOrder('A', $scores->where('panel', 'a')->sortBy('id')->values(), 4);
        $fillByOrder('E', $scores->where('panel', 'e')->sortBy('id')->values(), 4);

        $lineList = $scores->where('panel', 'penalty')->where('penalty_type', 'line')->sortBy('id')->values();
        foreach ([0 => 'LINE1', 1 => 'LINE2'] as $i => $key) {
            if (isset($lineList[$i]) && $values[$key] === '—') {
                $values[$key] = $fmt($lineList[$i]->score);
                $penalty[$key] = true;
            }
        }

        $time = $scores->first(fn ($s) => $s->panel === 'penalty' && $s->penalty_type === 'time');
        if ($time && $values['TIME'] === '—') {
            $values['TIME'] = $fmt($time->score);
            $penalty['TIME'] = true;
        }

        $resp = $scores->first(fn ($s) => $s->panel === 'penalty' && $s->penalty_type === 'music');
        if ($resp && $values['RESP'] === '—') {
            $values['RESP'] = $fmt($resp->score);
            $penalty['RESP'] = true;
        }

        return compact('columns', 'values', 'penalty');
    }

    public static function formatScore(?float $v): string
    {
        if ($v === null) {
            return '—';
        }

        return number_format($v, 3, '.', '');
    }
}
