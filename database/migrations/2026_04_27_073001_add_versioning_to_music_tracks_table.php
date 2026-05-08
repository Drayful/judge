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
        Schema::table('music_tracks', function (Blueprint $table) {
            if (!Schema::hasColumn('music_tracks', 'type')) {
                $table->string('type')->default('primary')->index()->after('performance_id'); // primary | backup
            }
            if (!Schema::hasColumn('music_tracks', 'version')) {
                $table->unsignedInteger('version')->default(1)->index()->after('type');
            }
            if (!Schema::hasColumn('music_tracks', 'uploaded_by')) {
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete()->after('version');
            }
            if (!Schema::hasColumn('music_tracks', 'replaced_at')) {
                $table->timestamp('replaced_at')->nullable()->index()->after('uploaded_by');
            }
            if (!Schema::hasColumn('music_tracks', 'is_active')) {
                $table->boolean('is_active')->default(true)->index()->after('replaced_at');
            }
            if (!Schema::hasColumn('music_tracks', 'locked_after')) {
                $table->timestamp('locked_after')->nullable()->after('is_active');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('music_tracks', function (Blueprint $table) {
            foreach (['locked_after', 'is_active', 'replaced_at'] as $col) {
                if (Schema::hasColumn('music_tracks', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('music_tracks', 'uploaded_by')) {
                try {
                    $table->dropConstrainedForeignId('uploaded_by');
                } catch (\Throwable) {
                    $table->dropColumn('uploaded_by');
                }
            }
            foreach (['version', 'type'] as $col) {
                if (Schema::hasColumn('music_tracks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
