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
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_published' => 'bool',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * Поток (категория), который сейчас ведёт секретарь в Live — для планшета судей без выбора потока.
     */
    public function activeCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'active_category_id');
    }
}
