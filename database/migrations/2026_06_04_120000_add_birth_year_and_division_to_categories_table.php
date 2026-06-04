<?php

use App\Support\CategoryMeta;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Делает год рождения и букву категории структурными полями (раньше они были
     * только текстом внутри categories.name). Это нужно для корректной сборки
     * итогового протокола по (год рождения + категория).
     *
     * Существующие строки разбираются один раз тем же парсером, что и импорт.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'birth_year')) {
                $table->unsignedSmallInteger('birth_year')->nullable()->index()->after('apparatus');
            }
            if (! Schema::hasColumn('categories', 'division')) {
                $table->string('division', 16)->nullable()->index()->after('birth_year');
            }
        });

        DB::table('categories')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $meta = CategoryMeta::parse($row->name);

                // Год: берём из name, иначе из старого age_min (импорт писал туда год).
                $birthYear = $meta['birth_year'];
                if ($birthYear === null && isset($row->age_min) && $row->age_min >= 1990) {
                    $birthYear = (int) $row->age_min;
                }

                DB::table('categories')->where('id', $row->id)->update([
                    'birth_year' => $birthYear,
                    'division' => $meta['division'],
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            foreach (['division', 'birth_year'] as $col) {
                if (Schema::hasColumn('categories', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
