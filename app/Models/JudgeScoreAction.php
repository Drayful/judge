<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JudgeScoreAction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'performance_id',
        'judge_id',
        'slot',
        'panel',
        'subpanel',
        'penalty_type',
        'action',
        'draft_score',
        'entries',
        'age_group',
    ];

    protected $casts = [
        'draft_score' => 'float',
        'entries' => 'array',
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
