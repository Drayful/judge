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
        Schema::create('music_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            // Performance table may be created after this migration (filename ordering),
            // so we keep this as a nullable reference without an FK for the MVP.
            $table->foreignId('performance_id')->nullable()->index();

            $table->string('original_name');
            $table->string('disk'); // e.g. s3
            $table->string('path'); // storage path/key
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('is_primary')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('music_tracks');
    }
};
