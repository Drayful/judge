<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entry extends Model
{
    protected $fillable = [
        'tournament_id',
        'athlete_id',
        'group_id',
        'program',
        'birth_year',
        'division',
        'club',
        'stream_no',
        'start_number',
        'order_index',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'birth_year' => 'integer',
        'stream_no' => 'integer',
        'start_number' => 'integer',
        'order_index' => 'integer',
    ];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
