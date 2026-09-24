<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->decimal('max_panel_spread', 6, 3)->nullable();
            $table->decimal('max_average_deviation', 6, 3)->nullable();
        });
        foreach (['categories', 'stream_sessions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('closed_at')->nullable();
            });
        }
        Schema::table('stream_sessions', function (Blueprint $table) {
            $table->unsignedSmallInteger('award_minutes')->default(0);
        });
        Schema::table('judge_scores', function (Blueprint $table) {
            $table->timestamp('returned_at')->nullable();
            $table->string('judge_name')->nullable();
            $table->string('judge_club')->nullable();
        });
        Schema::create('tournament_judges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('club')->nullable();
            $table->foreignId('tablet_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['tournament_id', 'tablet_user_id']);
            $table->unique(['tournament_id', 'name']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_judges');
        Schema::table('judge_scores', fn (Blueprint $table) => $table->dropColumn(['returned_at', 'judge_name', 'judge_club']));
        Schema::table('stream_sessions', fn (Blueprint $table) => $table->dropColumn(['award_minutes', 'closed_at']));
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('closed_at'));
        Schema::table('tournaments', fn (Blueprint $table) => $table->dropColumn(['max_panel_spread', 'max_average_deviation']));
    }
};
