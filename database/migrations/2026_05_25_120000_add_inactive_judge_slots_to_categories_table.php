<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'inactive_judge_slots')) {
                // Список неактивных слотов судей (DB1, DB2, DA1, DA2, A1..A4, E1..E4, LINE1..2, TIME, RESP),
                // на случай неполного состава бригады. Эти слоты не требуются для автоперехода и не считаются «ожидающими».
                $table->json('inactive_judge_slots')->nullable()->after('auto_advance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'inactive_judge_slots')) {
                $table->dropColumn('inactive_judge_slots');
            }
        });
    }
};
