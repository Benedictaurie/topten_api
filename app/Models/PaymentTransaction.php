<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $table = 'payment_transactions';

    protected $fillable = [
        'booking_id',
        'amount',
        'type',
        'method',
        'status',
        'gateway_reference',
        'proof_of_payment',
        'raw_response',
        'confirmed_at',
        'confirmed_by',
        'transacted_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'transacted_at' => 'datetime',
    ];

    /**
     * Payment Transaction belongs to a Booking.
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    /**
     * Payment Transaction can be confirmed by a User/Admin.
     */
    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}