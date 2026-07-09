<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Снятие со старта: выступление помечается снятым (status='withdrawn'),
     * стартовый номер сохраняется, очередь его пропускает, в протокол не идёт.
     */
    public function up(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            if (! Schema::hasColumn('performances', 'withdrawn_at')) {
                $table->timestamp('withdrawn_at')->nullable()->after('published_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            if (Schema::hasColumn('performances', 'withdrawn_at')) {
                $table->dropColumn('withdrawn_at');
            }
        });
    }
};
