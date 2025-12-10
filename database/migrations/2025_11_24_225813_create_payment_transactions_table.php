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
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('type', 50); // Tipe peristiwa: Payment, Refund, Fee, Adjustment
            $table->integer('amount');
            $table->string('method', 50)->nullable();
            $table->enum('status', ['success', 'pending', 'failed', 'refunded', 'canceled'])->default('pending');
            $table->text('proof_of_payment')->nullable(); // Bukti pembayaran manual (jika ada)
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable(); //konfirmasi hanya dilakukan jika pembayaran berhasil dan valid. jika tidak ada pembayaran (cancel/unpaid), kolom dibiarkan kosong
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete(); //karena kalau tidak ada konfirmasi, tidak ada admin yang perlu dicatat
            $table->timestamp('transacted_at')->useCurrent(); // Waktu transaksi ini benar-benar diproses
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
