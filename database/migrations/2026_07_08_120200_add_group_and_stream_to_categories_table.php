<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Поток (category) теперь может принадлежать группе и нести время начала/конца
     * блока (как на стартовом протоколе: «08:00-08:25»).
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'group_id')) {
                $table->foreignId('group_id')->nullable()->after('tournament_id')
                    ->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('categories', 'stream_no')) {
                $table->unsignedSmallInteger('stream_no')->nullable()->after('division');
            }
            if (! Schema::hasColumn('categories', 'starts_at_label')) {
                $table->string('starts_at_label', 16)->nullable()->after('stream_no');
            }
            if (! Schema::hasColumn('categories', 'ends_at_label')) {
                $table->string('ends_at_label', 16)->nullable()->after('starts_at_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'group_id')) {
                $table->dropConstrainedForeignId('group_id');
            }
            foreach (['ends_at_label', 'starts_at_label', 'stream_no'] as $col) {
                if (Schema::hasColumn('categories', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
