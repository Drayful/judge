<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ИИН (12 цифр) для надёжной идентификации гимнастки между турнирами.
     * Строка, чтобы не терять ведущий ноль (годы 200x).
     */
    public function up(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            if (! Schema::hasColumn('athletes', 'iin')) {
                $table->string('iin', 12)->nullable()->after('birthdate')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            if (Schema::hasColumn('athletes', 'iin')) {
                $table->dropColumn('iin');
            }
        });
    }
};
