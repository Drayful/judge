<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin'], true);
    }

    public function isSecretary(): bool
    {
        return in_array($this->role, ['secretary', 'organising_committee'], true);
    }

    /**
     * Замена музыки после дедлайна категории (секретариат / админ).
     */
    public function canUploadMusicAfterDeadline(): bool
    {
        return $this->isAdmin() || $this->isSecretary();
    }

    public function judgePanel(): ?array
    {
        return match ($this->role) {
            // ТЗ: judge_d / judge_a / judge_e (планшет бригад)
            'judge_d' => ['panel' => 'd', 'subpanel' => 'db'],
            'judge_a' => ['panel' => 'a', 'subpanel' => null],
            'judge_e' => ['panel' => 'e', 'subpanel' => null],
            'judge' => ['panel' => 'a', 'subpanel' => null],

            // D panel split (два планшета: сложность тела / аппарата)
            'judge_d_db' => ['panel' => 'd', 'subpanel' => 'db'],
            'judge_d_da' => ['panel' => 'd', 'subpanel' => 'da'],

            // Penalties
            'line_judge' => ['panel' => 'penalty', 'subpanel' => null, 'penalty_type' => 'line'],
            'time_judge' => ['panel' => 'penalty', 'subpanel' => null, 'penalty_type' => 'time'],
            'music_operator' => ['panel' => 'penalty', 'subpanel' => null, 'penalty_type' => 'music'],

            default => null,
        };
    }

    public function isAnyJudge(): bool
    {
        return $this->judgePanel() !== null || in_array($this->role, ['head_judge', 'superior_jury'], true) || $this->isAdmin();
    }
}
