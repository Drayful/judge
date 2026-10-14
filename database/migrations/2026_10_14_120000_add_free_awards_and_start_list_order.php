<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->date('scheduled_on')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->unsignedInteger('sheet_position')->nullable();
            $table->timestamps();
            $table->index(['tournament_id', 'scheduled_on']);
        });

        // Ручной порядок блоков дня в стартовом листе.
        Schema::table('stream_sessions', function (Blueprint $table) {
            $table->unsignedInteger('sheet_position')->nullable();
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedInteger('sheet_position')->nullable();
        });
        // 'sessions' — время выступлений считается отдельно по каждому дню группы.
        Schema::table('groups', function (Blueprint $table) {
            $table->string('schedule_mode', 16)->nullable();
        });

        // Старые «минуты награждения после сессии» становятся самостоятельными награждениями.
        $rows = DB::table('stream_sessions')
            ->join('categories', 'categories.id', '=', 'stream_sessions.category_id')
            ->where('stream_sessions.award_minutes', '>', 0)
            ->whereNotNull('stream_sessions.ends_at')
            ->get(['stream_sessions.scheduled_on', 'stream_sessions.ends_at', 'stream_sessions.award_minutes', 'categories.tournament_id']);
        foreach ($rows as $row) {
            $start = Carbon::parse((string) $row->ends_at);
            DB::table('tournament_awards')->insert([
                'tournament_id' => $row->tournament_id,
                'title' => 'Награждение',
                'scheduled_on' => $row->scheduled_on,
                'starts_at' => $start->format('H:i:s'),
                'ends_at' => $start->copy()->addMinutes((int) $row->award_minutes)->format('H:i:s'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('schedule_mode');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('sheet_position');
        });
        Schema::table('stream_sessions', function (Blueprint $table) {
            $table->dropColumn('sheet_position');
        });
        Schema::dropIfExists('tournament_awards');
    }
};
