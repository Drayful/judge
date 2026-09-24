<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class JudgeScore extends Model
{
    protected static function booted(): void
    {
        static::updated(function (self $score) {
            if (! $score->wasChanged(['score', 'submitted_at', 'returned_at', 'average_score', 'average_submitted_at'])) {
                return;
            }
            $field = in_array($score->judge?->slot, ['DB_AVG', 'DA_AVG'], true) ? 'average_score' : 'score';
            $before = $score->getRawOriginal($field);
            $base = (float) (($score->performance?->category?->scoring_rules ?? [])[$score->panel.'_base'] ?? 10);
            $display = fn ($value) => $value === null ? '—' : number_format(
                in_array($score->panel, ['a', 'e'], true) ? $base - (float) $value : (float) $value, 3, '.', '',
            );
            JudgeScoreAction::create([
                'performance_id' => $score->performance_id, 'judge_id' => $score->judge_id,
                'slot' => $score->judge?->slot ?? '', 'panel' => $score->panel,
                'subpanel' => $score->subpanel, 'penalty_type' => $score->penalty_type,
                'action' => ($score->returned_at !== null ? 'Возврат на доработку: '.$display($before)
                    : 'Исходный балл/сбавка: '.$display($before).' → '.$display($score->$field)).' ('.(auth()->user()?->name ?? $score->judge_name ?? 'Система').')',
                'draft_score' => $score->$field, 'entries' => $score->entries ?? [],
            ]);
        });
        static::saving(function (self $score) {
            if ($score->submitted_at !== null || $score->average_submitted_at !== null) {
                $score->returned_at = null;
            }
            if ($score->judge_name === null) {
                $tournamentId = $score->performance?->category?->tournament_id;
                $identity = DB::table('tournament_judges')
                    ->where('tournament_id', $tournamentId)->where('tablet_user_id', $score->judge_id)->first();
                $score->judge_name = $identity?->name ?? $score->judge?->name;
                $score->judge_club = $identity?->club;
            }
        });
    }

    protected $fillable = [
        'performance_id',
        'judge_id',
        'panel',
        'subpanel',
        'penalty_type',
        'score',
        'average_score',
        'entries',
        'age_group',
        'submitted_at',
        'average_submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'average_submitted_at' => 'datetime',
        'entries' => 'array',
    ];

    public function performance(): BelongsTo
    {
        return $this->belongsTo(Performance::class);
    }

    public function judge(): BelongsTo
    {
        return $this->belongsTo(User::class, 'judge_id');
    }
}
