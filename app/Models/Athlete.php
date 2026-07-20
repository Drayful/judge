<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Athlete extends Model
{
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'birthdate',
        'iin',
        'is_team',
        'club',
        'coach',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'is_team' => 'bool',
    ];

    /**
     * Участницы команды (ростер группового выступления). Только для is_team=true.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'team_members', 'team_athlete_id', 'member_athlete_id')
            ->withPivot('position')
            ->orderBy('team_members.position')
            ->orderBy('team_members.id');
    }

    public function isTeam(): bool
    {
        return (bool) $this->is_team;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function performances(): HasMany
    {
        return $this->hasMany(Performance::class);
    }
}
