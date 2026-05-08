<?php

namespace App\Models;

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
        'finalized_at',
        'approved_at',
        'published_at',
    ];

    protected $casts = [
        'called_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'finalized_at' => 'datetime',
        'decided_at' => 'datetime',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
        'is_counted' => 'bool',
    ];

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

        // ТЗ / FIG-подобно:
        // - D = сумма компонент: среднее по судьям DB + среднее по DA (при одном судье на слот — как DB1+DA1)
        // - A & E: judges enter final A/E points (0..10). Drop high+low, average middle two.
        // - Penalties: sum of submitted penalties (line/time/music/etc)
        // - Storage precision 0.001; display/rounding can be configured later.
        $aBase = (float) ($rules['a_base'] ?? 10.0);
        $eBase = (float) ($rules['e_base'] ?? 10.0);
        $round = (int) ($rules['round_decimals'] ?? 3);

        $scores = $this->judgeScores()
            ->whereNotNull('submitted_at')
            ->get();

        $dDb = $scores->where('panel', 'd')->where('subpanel', 'db')->pluck('score')->filter()->values();
        $dDa = $scores->where('panel', 'd')->where('subpanel', 'da')->pluck('score')->filter()->values();

        $db = $dDb->count() ? (float) $dDb->avg() : null;
        $da = $dDa->count() ? (float) $dDa->avg() : null;
        $d = ($db !== null && $da !== null) ? ($db + $da) : null;

        $aVals = $scores->where('panel', 'a')->pluck('score')->filter()->sort()->values();
        $eVals = $scores->where('panel', 'e')->pluck('score')->filter()->sort()->values();

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

        // penalties can come from multiple panels; keep it simple for now: panel == penalty
        $pen = (float) $scores->where('panel', 'penalty')->sum('score');

        $this->d_score = $d !== null ? round($d, $round) : null;
        $this->a_score = $a !== null ? round($a, $round) : null;
        $this->e_score = $e !== null ? round($e, $round) : null;
        $this->penalty = $pen ? round($pen, $round) : 0.0;

        if ($d !== null && $a !== null && $e !== null) {
            $total = $d + $a + $e - $pen;
            $this->total = round($total, $round);
        } else {
            $this->total = null;
        }
    }
}
