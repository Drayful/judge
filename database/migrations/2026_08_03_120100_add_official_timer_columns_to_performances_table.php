<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            if (! Schema::hasColumn('performances', 'timer_started_at')) {
                $table->timestamp('timer_started_at')->nullable()->after('started_at');
            }
            if (! Schema::hasColumn('performances', 'timer_ended_at')) {
                $table->timestamp('timer_ended_at')->nullable()->after('timer_started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            foreach (['timer_ended_at', 'timer_started_at'] as $column) {
                if (Schema::hasColumn('performances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
