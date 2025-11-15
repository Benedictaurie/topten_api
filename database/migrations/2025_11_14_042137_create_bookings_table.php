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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_code', 20)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->morphs('bookable'); // Akan membuat 2 kolom: bookable_id (BIGINT) dan bookable_type (VARCHAR)
            $table->dateTime('start_date');
            $table->dateTime('end_date')->nullable(); // Nullable untuk rental/activity yang tidak punya 'end date' pasti
            $table->integer('quantity')->default(1); // Jumlah orang, jumlah hari rental, dll
            $table->decimal('unit_price_at_booking', 10, 2); // Simpan harga per unit SAAT PEMESANAN
            $table->decimal('total_price', 10, 2);
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
