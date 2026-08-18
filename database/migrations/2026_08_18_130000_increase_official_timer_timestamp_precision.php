<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            $table->timestamp('timer_started_at', 6)->nullable()->change();
            $table->timestamp('timer_ended_at', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            $table->timestamp('timer_started_at', 0)->nullable()->change();
            $table->timestamp('timer_ended_at', 0)->nullable()->change();
        });
    }
};
