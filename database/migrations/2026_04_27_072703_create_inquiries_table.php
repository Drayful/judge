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
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Optional: inquiry can target a panel or an individual judge score.
            $table->string('panel')->nullable()->index(); // d | a | e | penalty
            $table->string('subpanel')->nullable()->index(); // db | da
            $table->string('penalty_type')->nullable()->index(); // line | time | music | ...
            $table->foreignId('judge_score_id')->nullable()->constrained('judge_scores')->nullOnDelete();

            $table->string('reason')->nullable();
            $table->string('status')->default('submitted')->index(); // submitted | under_review | decided | cancelled

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->index();
            $table->string('decision')->nullable(); // accepted | rejected | partially_accepted
            $table->string('decision_notes')->nullable();

            $table->timestamps();

            $table->index(['performance_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
