<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'reward_id',
        'applied_amount',
    ];

    /**
     * Satu booking bisa menggunakan beberapa reward, dan satu reward bisa digunakan pada beberapa booking 
     * (jika sistem memperbolehkan reward dipakai lebih dari satu kali — bisa juga tidak).
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function reward()
    {
        return $this->belongsTo(Reward::class);
    }
}
