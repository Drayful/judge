<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('apparatus_selection_mode')->default('fixed')->after('apparatus');
            $table->unsignedTinyInteger('apparatus_count')->nullable()->after('apparatus_selection_mode');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn(['apparatus_selection_mode', 'apparatus_count']);
        });
    }
};
