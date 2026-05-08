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
        Schema::create('judge_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('judge_id')->constrained('users')->cascadeOnDelete();

            $table->string('panel')->index(); // d | a | e | penalty
            $table->decimal('score', 6, 3)->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['performance_id', 'judge_id', 'panel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('judge_scores');
    }
};
