<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Auth\Passwords\CanResetPassword;

class User extends Authenticatable implements CanResetPasswordContract
{
    use HasApiTokens, HasFactory, Notifiable, CanResetPassword;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone_number',
        'address',
        'profile_picture',
        'fcm_token'
    ];
    protected $appends = ['profile'];

    public function getProfileAttribute()
    {
        if (!$this->profile_picture) {
            return null;
        }
        
        return asset('uploads/profile/' . $this->profile_picture);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    //memperlakukan data timestamp menjadi DateTime object di kode.
    protected $casts = [ 
        'email_verified_at' => 'datetime',  
        'phone_number_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    //Relasi satu user bisa punya banyak booking
    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
    //Relasi user (admin/owner) dapat mengonfirmasi banyak payments.
    public function confirmedPayments()
    {
        return $this->hasMany(PaymentTransaction::class, 'confirmed_by');
    }
    //satu user membuat banyak ulasan
    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}
