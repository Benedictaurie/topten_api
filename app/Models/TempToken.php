<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TempToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'token',
        'expired_at',
    ];

    protected $casts = [
        'expired_at' => 'datetime',
    ];

    /**
     * Relasi ke model User.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }
}