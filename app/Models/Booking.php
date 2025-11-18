<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str; // Digunakan untuk membuat booking code atau accessor

class Booking extends Model
{
    use HasFactory;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */

    protected $fillable = [
        'booking_code',
        'user_id',
        'bookable_id',
        'bookable_type',
        'start_date',
        'end_date',
        'quantity',
        'unit_price_at_booking',
        'total_price',
        'reward_total_applied',
        'final_price',
        'notes',
        'status',
    ];

    /**
     * Mendapatkan user yang melakukan booking.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mendapatkan model parent (tour, activity, atau rental) dari booking ini.
     */
    public function bookable()
    {
        return $this->morphTo();
    }
    /**
     * Accessor untuk mendapatkan tipe paket ('tour', 'activity', 'rental').
     */
    public function getPackageTypeAttribute()
    {
        if (!$this->bookable_type) {
            return null;
        }
        $className = class_basename($this->bookable_type); // Misal: 'TourPackage'
        return strtolower(str_replace('Package', '', $className)); // Menjadi 'tour'
    }

    /**
     * Satu booking hanya dapat diberi satu review
     */
    public function review()
    {
        return $this->hasOne(Review::class);
    }

    /**
     * Satu booking hanya memiliki satu pembayaran
     */
    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * Satu booking bisa menggunakan beberapa reward, dan satu reward bisa digunakan pada beberapa booking 
     * (jika sistem memperbolehkan reward dipakai lebih dari satu kali — bisa juga tidak).
     */
    public function rewards()
    {
        return $this->belongsToMany(Reward::class, 'booking_rewards')
            ->withPivot('applied_amount')
            ->withTimestamps();
    }
}