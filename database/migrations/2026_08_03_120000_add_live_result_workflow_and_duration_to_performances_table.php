<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            if (! Schema::hasColumn('performances', 'db_average')) {
                $table->decimal('db_average', 6, 3)->nullable()->after('d_score');
            }
            if (! Schema::hasColumn('performances', 'da_average')) {
                $table->decimal('da_average', 6, 3)->nullable()->after('db_average');
            }
            if (! Schema::hasColumn('performances', 'actual_duration_seconds')) {
                $table->unsignedSmallInteger('actual_duration_seconds')->nullable()->after('ended_at');
            }
            if (! Schema::hasColumn('performances', 'time_penalty')) {
                $table->decimal('time_penalty', 6, 3)->default(0)->after('penalty');
            }
            if (! Schema::hasColumn('performances', 'scoreboard_accepted_at')) {
                $table->timestamp('scoreboard_accepted_at')->nullable()->after('published_at')->index();
            }
            if (! Schema::hasColumn('performances', 'scoreboard_accepted_by')) {
                $table->foreignId('scoreboard_accepted_by')->nullable()->after('scoreboard_accepted_at')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            if (Schema::hasColumn('performances', 'scoreboard_accepted_by')) {
                $table->dropConstrainedForeignId('scoreboard_accepted_by');
            }
            foreach (['scoreboard_accepted_at', 'time_penalty', 'actual_duration_seconds', 'da_average', 'db_average'] as $column) {
                if (Schema::hasColumn('performances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
