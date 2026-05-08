<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('judge_scores', function (Blueprint $table) {
            if (!Schema::hasColumn('judge_scores', 'penalty_type')) {
                $table->string('penalty_type')->nullable()->after('subpanel')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('judge_scores', function (Blueprint $table) {
            if (Schema::hasColumn('judge_scores', 'penalty_type')) {
                $table->dropColumn('penalty_type');
            }
        });
    }
};
