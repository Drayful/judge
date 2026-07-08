<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Группа = (турнир, год рождения, буква категории, набор предметов).
     * Итоговый протокол по-прежнему ранжирует по (год + буква); группа —
     * структурный родитель потоков (categories) с набором снарядов/кругов.
     */
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('program')->default('individual'); // individual | group
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->string('birth_year_label')->nullable(); // «2020 и мл» и т.п.
            $table->string('division', 16)->nullable();      // A | B | C (латиница)
            $table->string('name');
            $table->json('apparatus')->nullable();           // ["Б.П.","Мяч"] — порядок = круги
            $table->string('number_mode')->default('continuous'); // continuous | per_stream
            $table->integer('order_index')->default(0);
            $table->timestamps();

            $table->index(['tournament_id', 'program', 'birth_year', 'division']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
