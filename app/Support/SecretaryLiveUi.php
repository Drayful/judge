<?php

namespace App\Support;

use App\Models\Performance;
use Illuminate\Support\Collection;

class SecretaryLiveUi
{
    /** Слоты, после заполнения которых считаем «все основные судьи выставили» (LINE/RESP не обязательны). */
    public const AUTO_ADVANCE_REQUIRED_LABELS = ['DB1', 'DA1', 'A1', 'A2', 'A3', 'A4', 'E1', 'E2', 'E3', 'E4'];

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
     * @return array<int, array{label: string, ok: bool}>
     */
    public static function judgeSlots(?Performance $perf): array
    {
        $scores = $perf?->judgeScores ?? collect();

        $a = $scores->where('panel', 'a')->sortBy('id')->values();
        $e = $scores->where('panel', 'e')->sortBy('id')->values();

        $ok = static function ($predicate) use ($scores): bool {
            $row = $scores->first($predicate);

            return $row !== null && $row->submitted_at !== null;
        };

        return [
            ['label' => 'DB1', 'ok' => $ok(fn ($s) => $s->panel === 'd' && $s->subpanel === 'db')],
            ['label' => 'DA1', 'ok' => $ok(fn ($s) => $s->panel === 'd' && $s->subpanel === 'da')],
            ['label' => 'A1', 'ok' => isset($a[0]) && $a[0]->submitted_at],
            ['label' => 'A2', 'ok' => isset($a[1]) && $a[1]->submitted_at],
            ['label' => 'A3', 'ok' => isset($a[2]) && $a[2]->submitted_at],
            ['label' => 'A4', 'ok' => isset($a[3]) && $a[3]->submitted_at],
            ['label' => 'E1', 'ok' => isset($e[0]) && $e[0]->submitted_at],
            ['label' => 'E2', 'ok' => isset($e[1]) && $e[1]->submitted_at],
            ['label' => 'E3', 'ok' => isset($e[2]) && $e[2]->submitted_at],
            ['label' => 'E4', 'ok' => isset($e[3]) && $e[3]->submitted_at],
            ['label' => 'LINE1', 'ok' => $ok(fn ($s) => $s->panel === 'penalty' && $s->penalty_type === 'line')],
            ['label' => 'RESP1', 'ok' => $ok(fn ($s) => $s->panel === 'penalty' && in_array($s->penalty_type, ['time', 'music'], true))],
        ];
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
        $columns = ['DB1', 'DA1', 'A1', 'A2', 'A3', 'A4', 'E1', 'E2', 'E3', 'E4', 'LINE1', 'LINE2', 'TIME', 'RESP'];
        $values = array_fill_keys($columns, '—');
        $penalty = array_fill_keys($columns, false);

        if (! $perf) {
            return compact('columns', 'values', 'penalty');
        }

        $scores = $perf->judgeScores;

        $fmt = static fn ($v) => $v !== null && $v !== '' ? number_format((float) $v, 3, '.', '') : '—';

        $db = $scores->first(fn ($s) => $s->panel === 'd' && $s->subpanel === 'db');
        if ($db) {
            $values['DB1'] = $fmt($db->score);
        }

        $da = $scores->first(fn ($s) => $s->panel === 'd' && $s->subpanel === 'da');
        if ($da) {
            $values['DA1'] = $fmt($da->score);
        }

        $aList = $scores->where('panel', 'a')->sortBy('id')->values();
        foreach (range(0, 3) as $i) {
            $key = 'A'.($i + 1);
            if (isset($aList[$i])) {
                $values[$key] = $fmt($aList[$i]->score);
            }
        }

        $eList = $scores->where('panel', 'e')->sortBy('id')->values();
        foreach (range(0, 3) as $i) {
            $key = 'E'.($i + 1);
            if (isset($eList[$i])) {
                $values[$key] = $fmt($eList[$i]->score);
            }
        }

        $lines = $scores->where('panel', 'penalty')->where('penalty_type', 'line')->sortBy('id')->values();
        if (isset($lines[0])) {
            $values['LINE1'] = $fmt($lines[0]->score);
            $penalty['LINE1'] = true;
        }
        if (isset($lines[1])) {
            $values['LINE2'] = $fmt($lines[1]->score);
            $penalty['LINE2'] = true;
        }

        $time = $scores->first(fn ($s) => $s->panel === 'penalty' && $s->penalty_type === 'time');
        if ($time) {
            $values['TIME'] = $fmt($time->score);
            $penalty['TIME'] = true;
        }

        $resp = $scores->first(fn ($s) => $s->panel === 'penalty' && $s->penalty_type === 'music');
        if ($resp) {
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
