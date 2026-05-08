<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            if (! Schema::hasColumn('tournaments', 'active_category_id')) {
                // Без after(): порядок колонок в PostgreSQL не критичен, так надёжнее для pgsql.
                $table->foreignId('active_category_id')
                    ->nullable()
                    ->constrained('categories')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            if (Schema::hasColumn('tournaments', 'active_category_id')) {
                $table->dropConstrainedForeignId('active_category_id');
            }
        });
    }
};
