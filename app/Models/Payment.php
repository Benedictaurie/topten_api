<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Booking;
use App\Models\User;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'amount',
        'method',
        'status',
        'proof_of_payment_path',
        'confirmed_at',
        'confirmed_by',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
    ];

    /**
     * Payment belongs to a booking
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Payment is confirmed by 1 adminWeb (user) atau owner (user)
     */
    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
