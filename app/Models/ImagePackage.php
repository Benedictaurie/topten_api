<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImagePackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'image', 
        'imageable_id', // Akan menyimpan ID paket (misalnya, ID Tour, ID Activity, atau ID Rental).
        'imageable_type', //Akan menyimpan nama Model (misalnya, App\Models\TourPackage, App\Models\ActivityPackage) untuk mengetahui tabel mana yang harus dihubungi.
    ];

    public function imageable()
    {
        // Mendefinisikan bahwa gambar ini adalah bagian dari hubungan polimorfik
        return $this->morphTo();
    }
}
