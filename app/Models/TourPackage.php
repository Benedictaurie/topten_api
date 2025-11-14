<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TourPackage extends Model
{
    use HasFactory, SoftDeletes;

     protected $fillable = [
        'name',
        'description',
        'price_per_person',
        'min_persons',
        'duration_days',
        'image_url',
        'is_available',
    ];

    public function bookings()
    {
        return $this->morphMany(Booking::class, 'bookable');
    }
}