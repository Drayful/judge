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
        Schema::create('performances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('start_number')->nullable()->index();
            $table->unsignedInteger('order_index')->default(0)->index();

            $table->string('status')->default('scheduled')->index(); // scheduled | on_deck | performing | done | published
            $table->timestamp('called_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            // Aggregated scores (calculated on finalize)
            $table->decimal('d_score', 6, 3)->nullable();
            $table->decimal('a_score', 6, 3)->nullable();
            $table->decimal('e_score', 6, 3)->nullable();
            $table->decimal('penalty', 6, 3)->nullable();
            $table->decimal('total', 7, 3)->nullable()->index();
            $table->timestamp('finalized_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performances');
    }
};
