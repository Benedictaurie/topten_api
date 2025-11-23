<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TourPackage extends Model
{
    use HasFactory;

     protected $fillable = [
        'name',
        'description',
        'price_per_person',
        'min_persons',
        'duration_days',
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