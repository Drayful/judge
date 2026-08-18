<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            if (! Schema::hasColumn('tournaments', 'active_stream_session_id')) {
                $table->foreignId('active_stream_session_id')
                    ->nullable()
                    ->constrained('stream_sessions')
                    ->nullOnDelete();
            }
        });

        Schema::table('performances', function (Blueprint $table) {
            if (! Schema::hasColumn('performances', 'timer_revision_requested_at')) {
                $table->timestamp('timer_revision_requested_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            if (Schema::hasColumn('performances', 'timer_revision_requested_at')) {
                $table->dropColumn('timer_revision_requested_at');
            }
        });

        Schema::table('tournaments', function (Blueprint $table) {
            if (Schema::hasColumn('tournaments', 'active_stream_session_id')) {
                $table->dropConstrainedForeignId('active_stream_session_id');
            }
        });
    }
};
