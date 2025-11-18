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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 30); // metode pembayaran
            $table->enum('status', ['unpaid','pending', 'paid', 'cancelled', 'refunded', 'failed'])->default('unpaid'); 
            $table->string('proof_of_payment_path', 255)->nullable(); //menyimpan path file bukti transfer
            $table->timestamps();
            $table->timestamp('confirmed_at')->nullable()->useCurrent(); //konfirmasi hanya dilakukan jika pembayaran berhasil dan valid. jika tidak ada pembayaran (cancel/unpaid), kolom dibiarkan kosong
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete(); //karena kalau tidak ada konfirmasi, tidak ada admin yang perlu dicatat
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
