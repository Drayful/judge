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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('program')->default('individual'); // individual | group
            $table->string('apparatus')->nullable(); // hoop, ball, clubs, ribbon, rope, free, etc
            $table->unsignedSmallInteger('age_min')->nullable();
            $table->unsignedSmallInteger('age_max')->nullable();
            $table->boolean('is_published')->default(false)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
