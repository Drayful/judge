<?php

namespace App\Models;

use App\Support\CategoryMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'tournament_id',
        'group_id',
        'name',
        'program',
        'apparatus',
        'birth_year',
        'division',
        'stream_no',
        'starts_at_label',
        'ends_at_label',
        'age_min',
        'age_max',
        'is_published',
        'music_deadline_at',
        'scoring_rules',
        'auto_advance',
        'inactive_judge_slots',
    ];

    protected $casts = [
        'is_published' => 'bool',
        'auto_advance' => 'bool',
        'birth_year' => 'integer',
        'music_deadline_at' => 'datetime',
        'scoring_rules' => 'array',
        'inactive_judge_slots' => 'array',
    ];

    /**
     * Год рождения категории: структурное поле, иначе разбор названия.
     */
    public function resolvedBirthYear(): ?int
    {
        if ($this->birth_year) {
            return (int) $this->birth_year;
        }

        return CategoryMeta::extractBirthYear($this->name);
    }

    /**
     * Буква категории (A/B/C…): структурное поле, иначе разбор названия.
     */
    public function resolvedDivision(): ?string
    {
        if (is_string($this->division) && trim($this->division) !== '') {
            return strtoupper(trim($this->division));
        }

        return CategoryMeta::extractDivision($this->name);
    }

    /**
     * Ключ группировки для итогового протокола: «2015 / A» (или «2015» без буквы).
     */
    public function protocolGroupKey(): string
    {
        $year = $this->resolvedBirthYear();
        $division = $this->resolvedDivision();

        return trim(($year ?? '—').' / '.($division ?? '—'));
    }

    /**
     * Список неактивных слотов судей (DB1, A4, E2 и т. п.).
     *
     * @return array<int, string>
     */
    public function inactiveJudgeSlotList(): array
    {
        $raw = $this->inactive_judge_slots;
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($v) => is_string($v) ? strtoupper(trim($v)) : null,
            $raw,
        ))));
    }

    public function isJudgeSlotActive(string $slot): bool
    {
        return ! in_array(strtoupper($slot), $this->inactiveJudgeSlotList(), true);
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function performances(): HasMany
    {
        return $this->hasMany(Performance::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(StreamSession::class)
            ->orderBy('scheduled_on')
            ->orderBy('starts_at')
            ->orderBy('session_no');
    }
}
