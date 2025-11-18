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
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->enum('origin', ['welcome', 'seasonal', 'loyalty', 'manual'])->default('manual'); // penting untuk pemberian tipe reward
            $table->decimal('amount', 10,2); //nilai yang akan diberikan ke user saat reward digunakan
            $table->enum('type', ['promo', 'discount'])->default('promo'); //untuk paket touring dan activity. kalau rental jarang
            $table->enum('status', ['available', 'used', 'expired'])->default('available');
            $table->text('description')->nullable();
            $table->enum('applies_to', ['all', 'tour', 'activity', 'rental'])->default('all'); //batasi reward per kategori
            $table->decimal('min_transaction', 10,2)->nullable(); //minimal total belanja agar reward bisa dipakai (opsional)
            $table->string('promo_code')->nullable()->unique(); //untuk sistem kode promo jika diperlukan
            $table->timestamp('used_at')->nullable(); 
            $table->timestamp('expired_at')->nullable();
            $table->
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};
