<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            if (!Schema::hasColumn('performances', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('finalized_at')->index();
            }
            if (!Schema::hasColumn('performances', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('approved_at')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('performances', function (Blueprint $table) {
            if (Schema::hasColumn('performances', 'published_at')) {
                $table->dropColumn('published_at');
            }
            if (Schema::hasColumn('performances', 'approved_at')) {
                $table->dropColumn('approved_at');
            }
        });
    }
};
