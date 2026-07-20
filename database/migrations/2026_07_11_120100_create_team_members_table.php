<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ростер команды: связь «команда (athlete.is_team) → участница (athlete)».
     */
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_athlete_id')->constrained('athletes')->cascadeOnDelete();
            $table->foreignId('member_athlete_id')->constrained('athletes')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['team_athlete_id', 'member_athlete_id']);
            $table->index('team_athlete_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};
