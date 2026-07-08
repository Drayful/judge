<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Пул участниц турнира (импорт списка участвующих). Пока не привязан к группе —
     * group_id/stream_no/start_number заполняются при формировании групп и потоков.
     */
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('program')->default('individual'); // individual | group
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->string('division', 16)->nullable();
            $table->string('club')->nullable();
            $table->unsignedSmallInteger('stream_no')->nullable();
            $table->unsignedSmallInteger('start_number')->nullable();
            $table->integer('order_index')->default(0);
            $table->json('meta')->nullable(); // лист-источник; для команд — состав
            $table->timestamps();

            $table->index(['tournament_id', 'program', 'birth_year', 'division']);
            $table->index(['group_id', 'stream_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};
