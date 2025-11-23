<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RentalPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'brand',
        'model',
        'plate_number',
        'description',
        'price_per_day',
        'is_available',
    ];

    public function images()
    {
        // Mendefinisikan hubungan polimorfik Satu-ke-Banyak
        return $this->morphMany(ImagePackage::class, 'imageable');
    }

    public function bookings()
    {
        return $this->morphMany(Booking::class, 'bookable');
    }
}