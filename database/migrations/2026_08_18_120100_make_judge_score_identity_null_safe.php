<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_INDEX = 'judge_scores_perf_judge_panel_sub_penalty_unique';

    private const NEW_INDEX = 'judge_scores_identity_null_safe_unique';

    public function up(): void
    {
        // PostgreSQL и SQLite считают NULL различными значениями внутри UNIQUE,
        // поэтому старый индекс мог пропустить несколько логически одинаковых строк.
        // Перед усилением ограничения сохраняем самую свежую отправленную запись.
        DB::table('judge_scores')
            ->select([
                'id',
                'performance_id',
                'judge_id',
                'panel',
                'subpanel',
                'penalty_type',
                'submitted_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($row) => implode('|', [
                $row->performance_id,
                $row->judge_id,
                $row->panel,
                $row->subpanel ?? '',
                $row->penalty_type ?? '',
            ]))
            ->each(function ($rows) {
                if ($rows->count() < 2) {
                    return;
                }

                $keep = $rows
                    ->sortByDesc(fn ($row) => [
                        $row->submitted_at !== null ? 1 : 0,
                        (string) ($row->updated_at ?? ''),
                        (int) $row->id,
                    ])
                    ->first();

                DB::table('judge_scores')
                    ->whereIn('id', $rows->pluck('id')->reject(fn ($id) => (int) $id === (int) $keep->id))
                    ->delete();
            });

        Schema::table('judge_scores', function (Blueprint $table) {
            $table->dropUnique(self::OLD_INDEX);
        });

        $subpanel = "COALESCE(subpanel, '')";
        $penaltyType = "COALESCE(penalty_type, '')";
        DB::statement('CREATE UNIQUE INDEX '.self::NEW_INDEX
            .' ON judge_scores (performance_id, judge_id, panel, '.$subpanel.', '.$penaltyType.')');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::NEW_INDEX);

        Schema::table('judge_scores', function (Blueprint $table) {
            $table->unique(
                ['performance_id', 'judge_id', 'panel', 'subpanel', 'penalty_type'],
                self::OLD_INDEX,
            );
        });
    }
};
