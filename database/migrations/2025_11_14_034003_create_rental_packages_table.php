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
        Schema::create('rental_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 50); // motor/mobil
            $table->string('brand', 50);
            $table->string('model', 50);
            $table->string('plate_number', 50)->unique();
            $table->text('description');
            $table->text('includes');
            $table->text('excludes');
            $table->integer('price_per_day');
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rental_packages');
    }
};
