<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->json('inactive_judge_slots')->nullable();
        });

        DB::table('tournaments')->orderBy('id')->eachById(function (object $tournament) {
            $category = DB::table('categories')
                ->where('tournament_id', $tournament->id)
                ->orderBy('id')
                ->first(['inactive_judge_slots']);

            if ($category !== null) {
                DB::table('tournaments')->where('id', $tournament->id)->update([
                    'inactive_judge_slots' => $category->inactive_judge_slots,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('inactive_judge_slots');
        });
    }
};
