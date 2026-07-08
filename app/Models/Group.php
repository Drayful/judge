<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $fillable = [
        'tournament_id',
        'program',
        'birth_year',
        'birth_year_label',
        'division',
        'name',
        'apparatus',
        'number_mode',
        'order_index',
    ];

    protected $casts = [
        'apparatus' => 'array',
        'birth_year' => 'integer',
        'order_index' => 'integer',
    ];

    /**
     * Упорядоченный список меток снарядов группы (круги).
     *
     * @return list<string>
     */
    public function apparatusLabels(): array
    {
        $raw = $this->apparatus;
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? trim($v) : '',
            $raw,
        ), static fn ($v) => $v !== ''));
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }
}
