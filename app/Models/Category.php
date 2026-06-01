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
        'inactive_judge_slots',
    ];

    protected $casts = [
        'is_published' => 'bool',
        'auto_advance' => 'bool',
        'music_deadline_at' => 'datetime',
        'scoring_rules' => 'array',
        'inactive_judge_slots' => 'array',
    ];

    /**
     * Список неактивных слотов судей (DB1, A4, E2 и т. п.).
     *
     * @return array<int, string>
     */
    public function inactiveJudgeSlotList(): array
    {
        $raw = $this->inactive_judge_slots;
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($v) => is_string($v) ? strtoupper(trim($v)) : null,
            $raw,
        ))));
    }

    public function isJudgeSlotActive(string $slot): bool
    {
        return ! in_array(strtoupper($slot), $this->inactiveJudgeSlotList(), true);
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function performances(): HasMany
    {
        return $this->hasMany(Performance::class);
    }
}
