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
        Schema::create('image_packages', function (Blueprint $table) {
            $table->id();
            $table->string('image');
            $table->morphs('imageable'); // Ini akan membuat imageable_id (BIGINT) dan imageable_type (STRING)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_packages');
    }
};
