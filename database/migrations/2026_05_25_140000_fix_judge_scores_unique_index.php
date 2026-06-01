<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Заменяет старый UNIQUE-индекс (performance_id, judge_id, panel) на полный
     * композит, совпадающий с ключом updateOrCreate в JudgeController::saveJudgeScore:
     * (performance_id, judge_id, panel, subpanel, penalty_type).
     *
     * Раньше D-судья не мог сохранить и DB, и DA-сабпанели одновременно — старый
     * индекс падал с UNIQUE violation. Аналогично для admin'а, заполняющего разные
     * penalty_type для одного судьи.
     */
    public function up(): void
    {
        Schema::table('judge_scores', function (Blueprint $table) {
            try {
                $table->dropUnique(['performance_id', 'judge_id', 'panel']);
            } catch (\Throwable $e) {
                // индекс мог быть удалён вручную или иметь иное имя — игнорируем
            }
        });

        Schema::table('judge_scores', function (Blueprint $table) {
            $table->unique(
                ['performance_id', 'judge_id', 'panel', 'subpanel', 'penalty_type'],
                'judge_scores_perf_judge_panel_sub_penalty_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('judge_scores', function (Blueprint $table) {
            try {
                $table->dropUnique('judge_scores_perf_judge_panel_sub_penalty_unique');
            } catch (\Throwable $e) {
                //
            }
        });

        Schema::table('judge_scores', function (Blueprint $table) {
            $table->unique(['performance_id', 'judge_id', 'panel']);
        });
    }
};
