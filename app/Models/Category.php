<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'tournament_id',
        'name',
        'program',
        'apparatus',
        'age_min',
        'age_max',
        'is_published',
        'music_deadline_at',
        'scoring_rules',
        'auto_advance',
    ];

    protected $casts = [
        'is_published' => 'bool',
        'auto_advance' => 'bool',
        'music_deadline_at' => 'datetime',
        'scoring_rules' => 'array',
    ];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function performances(): HasMany
    {
        return $this->hasMany(Performance::class);
    }
}
