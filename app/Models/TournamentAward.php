<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Награждение: секретарь сам задаёт название, день и время. */
class TournamentAward extends Model
{
    protected $fillable = [
        'tournament_id',
        'title',
        'scheduled_on',
        'starts_at',
        'ends_at',
        'sheet_position',
    ];

    protected $casts = [
        'scheduled_on' => 'date',
        'sheet_position' => 'integer',
    ];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function timeLabel(): string
    {
        $start = $this->starts_at ? substr((string) $this->starts_at, 0, 5) : '';
        $end = $this->ends_at ? substr((string) $this->ends_at, 0, 5) : '';

        return $end !== '' ? $start.'–'.$end : $start;
    }
}
