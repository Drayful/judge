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
        Schema::table('performances', function (Blueprint $table) {
            if (!Schema::hasColumn('performances', 'original_performance_id')) {
                $table->foreignId('original_performance_id')
                    ->nullable()
                    ->constrained('performances')
                    ->nullOnDelete()
                    ->after('athlete_id');
            }

            if (!Schema::hasColumn('performances', 'attempt_no')) {
                $table->unsignedSmallInteger('attempt_no')->default(1)->after('original_performance_id');
            }

            if (!Schema::hasColumn('performances', 'is_counted')) {
                $table->boolean('is_counted')->default(true)->index()->after('attempt_no');
            }

            if (!Schema::hasColumn('performances', 'restart_reason')) {
                $table->string('restart_reason')->nullable()->after('is_counted');
            }

            if (!Schema::hasColumn('performances', 'decided_by')) {
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete()->after('restart_reason');
            }

            if (!Schema::hasColumn('performances', 'decided_at')) {
                $table->timestamp('decided_at')->nullable()->index()->after('decided_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            foreach (['decided_at', 'restart_reason', 'is_counted', 'attempt_no', 'original_performance_id', 'decided_by'] as $col) {
                if (Schema::hasColumn('performances', $col)) {
                    // Drop foreign keys before columns when applicable.
                    if (in_array($col, ['original_performance_id', 'decided_by'], true)) {
                        try {
                            $table->dropConstrainedForeignId($col);
                        } catch (\Throwable) {
                            $table->dropColumn($col);
                        }
                    } else {
                        $table->dropColumn($col);
                    }
                }
            }
        });
    }
};
