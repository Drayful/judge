<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StreamSession extends Model
{
    protected $fillable = [
        'category_id',
        'session_no',
        'title',
        'scheduled_on',
        'starts_at',
        'ends_at',
        'apparatus',
    ];

    protected $casts = [
        'scheduled_on' => 'date',
        'apparatus' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function performances(): HasMany
    {
        return $this->hasMany(Performance::class);
    }
}
