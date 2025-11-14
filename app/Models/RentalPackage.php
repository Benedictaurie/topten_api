<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RentalPackage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'brand',
        'model',
        'plate_number',
        'description',
        'image_url',
        'price_per_day',
        'is_available',
    ];

    public function bookings()
    {
        return $this->morphMany(Booking::class, 'bookable');
    }
}