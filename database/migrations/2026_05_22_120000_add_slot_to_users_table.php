<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'slot')) {
                // Слот судьи в бригаде: DB1, DB2, DA1, DA2, A1..A4, E1..E4, LINE1, LINE2, TIME, RESP.
                $table->string('slot', 16)->nullable()->after('role')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'slot')) {
                $table->dropColumn('slot');
            }
        });
    }
};
