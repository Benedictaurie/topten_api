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
        Schema::create('booking_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); //user melakukan perubahan (admin) 
            $table->enum('old_status', ['pending', 'confirmed', 'completed', 'cancelled', 'no_show', 'rescheduled'])->nullable();
            $table->enum('new_status', ['pending', 'confirmed', 'completed', 'cancelled', 'no_show', 'rescheduled']);
            $table->text('notes')->nullable(); //menyimpan detail tambahan misal, alasan pembatalan/perubahan jadwal
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_logs');
    }
};
