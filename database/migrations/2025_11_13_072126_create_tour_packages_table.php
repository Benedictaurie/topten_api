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
        Schema::create('tour_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price_per_person', 10,2); //harga per orang utk paket activity
            $table->integer('min_persons'); //jumlah orang minimum untuk harga paket 
            $table->integer('duration_days'); //informasi durasi paket (misal paket 3hari, jadi durasi harinya adalah 3 hari)
            $table->string('image_url', 255)->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tour_packages');
    }
};
