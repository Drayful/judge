<?php

namespace App\Policies;

use App\Models\MusicTrack;
use App\Models\User;

class MusicTrackPolicy
{
    public function download(User $user, MusicTrack $track): bool
    {
        if (in_array($user->role, ['admin', 'secretary'], true)) {
            return true;
        }

        return $track->athlete?->user_id === $user->id;
    }
}

