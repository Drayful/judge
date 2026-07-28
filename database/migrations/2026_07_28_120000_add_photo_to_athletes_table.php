<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->string('photo_disk')->nullable()->after('coach');
            $table->string('photo_path')->nullable()->after('photo_disk');
            $table->string('photo_original_name')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->dropColumn(['photo_disk', 'photo_path', 'photo_original_name']);
        });
    }
};
