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
            if (!Schema::hasColumn('judge_scores', 'subpanel')) {
                $table->string('subpanel')->nullable()->after('panel')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('judge_scores', function (Blueprint $table) {
            if (Schema::hasColumn('judge_scores', 'subpanel')) {
                $table->dropColumn('subpanel');
            }
        });
    }
};
