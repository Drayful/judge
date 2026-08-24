<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tournament extends Model
{
    protected $fillable = [
        'name',
        'starts_on',
        'ends_on',
        'timezone',
        'is_published',
        'active_category_id',
        'active_stream_session_id',
        'inactive_judge_slots',
        'live_queue_category_ids',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_published' => 'bool',
        'inactive_judge_slots' => 'array',
        'live_queue_category_ids' => 'array',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /**
     * Поток (категория), который сейчас ведёт секретарь в Live — для планшета судей без выбора потока.
     */
    public function activeCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'active_category_id');
    }

    public function activeStreamSession(): BelongsTo
    {
        return $this->belongsTo(StreamSession::class, 'active_stream_session_id');
    }

    /** @return array<int, string> */
    public function inactiveJudgeSlotList(): array
    {
        $raw = $this->inactive_judge_slots;
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($value) => is_string($value) ? strtoupper(trim($value)) : null,
            $raw,
        ))));
    }

    /** @return list<int> */
    public function combinedLiveCategoryIds(): array
    {
        if (! is_array($this->live_queue_category_ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false ? (int) $id : null,
            $this->live_queue_category_ids,
        ))));
    }

    public function hasCombinedLiveQueue(): bool
    {
        return count($this->combinedLiveCategoryIds()) >= 2;
    }

    public function isCategoryInCombinedLiveQueue(Category|int $category): bool
    {
        $categoryId = $category instanceof Category ? $category->id : $category;

        return in_array((int) $categoryId, $this->combinedLiveCategoryIds(), true);
    }
}
