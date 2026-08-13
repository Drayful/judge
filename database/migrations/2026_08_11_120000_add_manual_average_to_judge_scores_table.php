<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('judge_scores', function (Blueprint $table) {
            $table->decimal('average_score', 6, 3)->nullable()->after('score');
            $table->timestamp('average_submitted_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('judge_scores', function (Blueprint $table) {
            $table->dropColumn(['average_score', 'average_submitted_at']);
        });
    }
};
