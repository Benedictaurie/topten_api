<?php

namespace App\Services\Availability;

use App\Models\TourPackage;
use App\Models\Booking; 
use Carbon\Carbon;

class TourAvailabilityService
{
    public function checkAvailability($tourId, $startDate, $participants)
    {
        $tour = TourPackage::find($tourId);
        $endDate = Carbon::parse($startDate)->addDays($tour->duration_days - 1)->format('Y-m-d H:i:s'); // Hitung tanggal akhir
        
        // 1. Cek apakah tour tersedia (is_available)
        if (!$tour || !$tour->is_available) {
            return false;
        }
        
        // 2. Cek apakah participants memenuhi minimum persons
        if ($participants < $tour->min_persons) {
            return false;
        }

        // 3. Cek Ketersediaan Kendaraan (diambil dari Booking yang sudah dikonfirmasi)
        // Logika ini mengasumsikan TourPackage memiliki relasi 1:1 atau 1:M ke kendaraan tertentu
        // Jika diasumsikan 1 paket tour menggunakan 1 kendaraan, kita cek tabrakan booking untuk kendaraan tersebut.
        $hasConflict = Booking::where('bookable_id', $tourId)
            ->where('bookable_type', TourPackage::class)
            ->where(function($query) use ($startDate, $endDate) {
                // Logic cek overlap, mirip Rental
                $query->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function($q) use ($startDate, $endDate) {
                        $q->where('start_date', '<=', $startDate)
                          ->where('end_date', '>=', $endDate);
                    });
            })
            ->whereIn('status', ['confirmed', 'pending'])
            ->exists();
            
        if ($hasConflict) {
            return false;
        }

        return true;
    }
}