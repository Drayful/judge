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
            if (!Schema::hasColumn('performances', 'apparatus')) {
                $table->string('apparatus')->nullable()->after('athlete_id'); // ball, clubs, ribbon, hoop, rope, etc
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            if (Schema::hasColumn('performances', 'apparatus')) {
                $table->dropColumn('apparatus');
            }
        });
    }
};
