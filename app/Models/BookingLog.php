<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingLog extends Model
{
    use HasFactory;

    protected $table = 'booking_logs';

    protected $fillable = [
        'booking_id',
        'user_id',
        'old_status',
        'new_status',
        'notes',
    ];

    /**
     * Booking log belongs to a Booking.
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    /**
     * Booking log can be linked to the User/Admin who made the change.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}