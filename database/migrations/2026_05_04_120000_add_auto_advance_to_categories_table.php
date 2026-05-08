<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'auto_advance')) {
                $table->boolean('auto_advance')->default(false)->after('is_published');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'auto_advance')) {
                $table->dropColumn('auto_advance');
            }
        });
    }
};
