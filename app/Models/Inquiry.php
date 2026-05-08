<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inquiry extends Model
{
    protected $fillable = [
        'performance_id',
        'created_by',
        'panel',
        'subpanel',
        'penalty_type',
        'judge_score_id',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'decision',
        'decision_notes',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function performance(): BelongsTo
    {
        return $this->belongsTo(Performance::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function judgeScore(): BelongsTo
    {
        return $this->belongsTo(JudgeScore::class, 'judge_score_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
