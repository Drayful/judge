<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('judge_score_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('judge_id')->constrained('users')->cascadeOnDelete();
            $table->string('slot', 24)->nullable();
            $table->string('panel', 24);
            $table->string('subpanel', 24)->nullable();
            $table->string('penalty_type', 24)->nullable();
            $table->string('action', 120);
            $table->decimal('draft_score', 8, 3)->nullable();
            $table->json('entries')->nullable();
            $table->string('age_group', 16)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['performance_id', 'created_at']);
            $table->index(['judge_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('judge_score_actions');
    }
};
