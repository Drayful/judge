<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ручное выставление финальной оценки секретарём / главным судьёй.
     * Когда флаг взведён — recalculateTotals() не пересобирает D/A/E/штраф из оценок
     * судей, а считает итог по проставленным вручную значениям.
     */
    public function up(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            if (! Schema::hasColumn('performances', 'scores_overridden')) {
                $table->boolean('scores_overridden')->default(false)->after('total');
            }
            if (! Schema::hasColumn('performances', 'scores_overridden_by')) {
                $table->unsignedBigInteger('scores_overridden_by')->nullable()->after('scores_overridden');
            }
            if (! Schema::hasColumn('performances', 'scores_overridden_at')) {
                $table->timestamp('scores_overridden_at')->nullable()->after('scores_overridden_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            foreach (['scores_overridden_at', 'scores_overridden_by', 'scores_overridden'] as $col) {
                if (Schema::hasColumn('performances', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
