<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stream_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('session_no');
            $table->string('title')->nullable();
            $table->date('scheduled_on');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->json('apparatus');
            $table->timestamps();

            $table->unique(['category_id', 'session_no']);
            $table->index(['category_id', 'scheduled_on']);
        });

        Schema::table('performances', function (Blueprint $table) {
            $table->foreignId('stream_session_id')
                ->nullable()
                ->after('category_id')
                ->constrained('stream_sessions')
                ->nullOnDelete();
            $table->index(['category_id', 'stream_session_id', 'order_index'], 'performances_session_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            $table->dropIndex('performances_session_queue_index');
            $table->dropConstrainedForeignId('stream_session_id');
        });

        Schema::dropIfExists('stream_sessions');
    }
};
