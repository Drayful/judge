<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Athlete extends Model
{
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'birthdate',
        'club',
        'coach',
    ];

    protected $casts = [
        'birthdate' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function performances(): HasMany
    {
        return $this->hasMany(Performance::class);
    }
}
