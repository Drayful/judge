<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('judge_scores', function (Blueprint $table) {
            // История выставления оценки с планшета: [{v, label, symbol, acro, notDone, counted}, ...]
            $table->json('entries')->nullable()->after('score');
            // Возрастная группа, выбранная судьёй на планшете (junior / senior).
            $table->string('age_group', 16)->nullable()->after('entries');
        });
    }

    public function down(): void
    {
        Schema::table('judge_scores', function (Blueprint $table) {
            $table->dropColumn(['entries', 'age_group']);
        });
    }
};
