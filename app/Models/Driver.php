<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vehicle_type',
        'license_plate',
        'status',
        'last_active',
    ];

    protected $casts = [
        'last_active' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
