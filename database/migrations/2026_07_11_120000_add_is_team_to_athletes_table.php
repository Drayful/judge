<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Команда группового выступления представлена записью в athletes (is_team=true):
     * это единица вызова на ковёр и оценки. Настоящие участницы (ростер) —
     * отдельные athletes, связанные через team_members.
     */
    public function up(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            if (! Schema::hasColumn('athletes', 'is_team')) {
                $table->boolean('is_team')->default(false)->index()->after('iin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            if (Schema::hasColumn('athletes', 'is_team')) {
                $table->dropColumn('is_team');
            }
        });
    }
};
