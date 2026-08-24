<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('groups', 'live_queues_combined')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->dropColumn('live_queues_combined');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('groups', 'live_queues_combined')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->boolean('live_queues_combined')->default(false);
            });
        }
    }
};
