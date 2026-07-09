<?php

namespace App\Models;

use App\Support\PerformanceApparatus;
use App\Support\SecretaryLiveUi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Performance extends Model
{
    protected $fillable = [
        'category_id',
        'athlete_id',
        'original_performance_id',
        'attempt_no',
        'is_counted',
        'restart_reason',
        'decided_by',
        'decided_at',
        'apparatus',
        'start_number',
        'order_index',
        'status',
        'called_at',
        'started_at',
        'ended_at',
        'd_score',
        'a_score',
        'e_score',
        'penalty',
        'total',
        'scores_overridden',
        'scores_overridden_by',
        'scores_overridden_at',
        'finalized_at',
        'approved_at',
        'published_at',
        'withdrawn_at',
    ];

    protected $casts = [
        'called_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'finalized_at' => 'datetime',
        'decided_at' => 'datetime',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
        'scores_overridden_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'is_counted' => 'bool',
        'scores_overridden' => 'bool',
    ];

    public function isWithdrawn(): bool
    {
        return $this->status === 'withdrawn' || $this->withdrawn_at !== null;
    }

    public function originalPerformance(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_performance_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(self::class, 'original_performance_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function judgeScores(): HasMany
    {
        return $this->hasMany(JudgeScore::class);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    public function track(): HasOne
    {
        return $this->hasOne(MusicTrack::class, 'performance_id')
            ->where('type', 'primary')
            ->where('is_active', true);
    }

    public function trackBackup(): HasOne
    {
        return $this->hasOne(MusicTrack::class, 'performance_id')
            ->where('type', 'backup')
            ->where('is_active', true);
    }

    public function recalculateTotals(): void
    {
        $rules = $this->category?->scoring_rules ?? null;
        $round = (int) ($rules['round_decimals'] ?? 3);

        // Ручное выставление итога секретарём / главным судьёй: D/A/E/штраф заданы
        // напрямую, оценки судей игнорируем — считаем только итоговую сумму.
        if ($this->scores_overridden) {
            $d = $this->d_score;
            $a = $this->a_score;
            $e = $this->e_score;
            $pen = $this->penalty;

            if ($d !== null && $a !== null && $e !== null) {
                $this->total = round((float) $d + (float) $a + (float) $e - (float) ($pen ?? 0.0), $round);
            } else {
                $this->total = null;
            }

            return;
        }

        // ТЗ / FIG-подобно:
        // - D = сумма компонент: среднее по судьям DB + среднее по DA (при одном судье на слот — как DB1+DA1)
        // - A & E: judges enter final A/E points (0..10). Drop high+low, average middle two.
        // - Penalties: sum of submitted penalties (line/time/music/etc)
        // - Storage precision 0.001; display/rounding can be configured later.
        $aBase = (float) ($rules['a_base'] ?? 10.0);
        $eBase = (float) ($rules['e_base'] ?? 10.0);

        $scores = $this->judgeScores()
            ->whereNotNull('submitted_at')
            ->get();

        // ВАЖНО: ->filter() без аргумента вырезает все falsy-значения,
        // в т.ч. валидные 0.0. Используем явное «не null».
        $notNull = static fn ($v) => $v !== null;

        if ($this->isBodyOnlyApparatus()) {
            $d = $this->calculateBodyOnlyDScore();
        } else {
            $dDb = $scores->where('panel', 'd')->where('subpanel', 'db')->pluck('score')->filter($notNull)->values();
            $dDa = $scores->where('panel', 'd')->where('subpanel', 'da')->pluck('score')->filter($notNull)->values();

            $db = $dDb->count() ? (float) $dDb->avg() : null;
            $da = $dDa->count() ? (float) $dDa->avg() : null;
            $d = ($db !== null && $da !== null) ? ($db + $da) : null;
        }

        $aVals = $scores->where('panel', 'a')->pluck('score')->filter($notNull)->sort()->values();
        $eVals = $scores->where('panel', 'e')->pluck('score')->filter($notNull)->sort()->values();

        $a = null;
        if ($aVals->count() >= 4) {
            $mid = $aVals->slice(1, $aVals->count() - 2);
            $a = (float) $mid->avg();
        } elseif ($aVals->count() > 0) {
            $a = (float) $aVals->avg();
        }

        $e = null;
        if ($eVals->count() >= 4) {
            $mid = $eVals->slice(1, $eVals->count() - 2);
            $e = (float) $mid->avg();
        } elseif ($eVals->count() > 0) {
            $e = (float) $eVals->avg();
        }

        // penalties: суммируем только реально пришедшие записи penalty-судей,
        // чтобы отсутствие записей сохранило penalty=null (а не 0.0), и в табло
        // не печаталось «−0.000» по неустановленной бригаде.
        $penaltyScores = $scores->where('panel', 'penalty')->pluck('score')->filter($notNull);
        $pen = $penaltyScores->count() ? (float) $penaltyScores->sum() : null;

        $this->d_score = $d !== null ? round($d, $round) : null;
        $this->a_score = $a !== null ? round($a, $round) : null;
        $this->e_score = $e !== null ? round($e, $round) : null;
        $this->penalty = $pen !== null ? round($pen, $round) : null;

        if ($d !== null && $a !== null && $e !== null) {
            $total = $d + $a + $e - ($pen ?? 0.0);
            $this->total = round($total, $round);
        } else {
            $this->total = null;
        }
    }

    public function isBodyOnlyApparatus(): bool
    {
        return PerformanceApparatus::isBodyOnly($this->apparatus);
    }

    /**
     * БП: 4 оценки DB1/DB2/DA1/DA2 → сортировка, отбрасываем min/max, среднее двух центральных.
     */
    private function calculateBodyOnlyDScore(): ?float
    {
        $this->loadMissing('category');
        $inactive = SecretaryLiveUi::inactiveSlots($this->category);
        $rows = SecretaryLiveUi::scoreRowsBySlot($this, $this->category);

        $activeSlots = array_values(array_filter(
            SecretaryLiveUi::D_JUDGE_SLOTS,
            fn (string $slot) => ! in_array($slot, $inactive, true),
        ));

        if ($activeSlots === []) {
            return null;
        }

        $vals = collect($activeSlots)
            ->map(function (string $slot) use ($rows) {
                $row = $rows[$slot] ?? null;
                if ($row === null || $row->submitted_at === null || $row->score === null) {
                    return null;
                }

                return (float) $row->score;
            });

        if ($vals->contains(null)) {
            return null;
        }

        $sorted = $vals->sort()->values();
        $count = $sorted->count();

        if ($count >= 4) {
            $mid = $sorted->slice(1, $count - 2);

            return (float) $mid->avg();
        }

        if ($count >= 3) {
            $mid = $sorted->slice(1, $count - 2);

            return (float) $mid->avg();
        }

        if ($count >= 2) {
            return (float) $sorted->avg();
        }

        return (float) $sorted->first();
    }
}
