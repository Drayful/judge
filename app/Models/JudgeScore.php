<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JudgeScore extends Model
{
    protected $fillable = [
        'performance_id',
        'judge_id',
        'panel',
        'subpanel',
        'penalty_type',
        'score',
        'notes',
        'submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    public function performance(): BelongsTo
    {
        return $this->belongsTo(Performance::class);
    }

    public function judge(): BelongsTo
    {
        return $this->belongsTo(User::class, 'judge_id');
    }
}
