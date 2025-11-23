<?php

namespace App\Services\Availability;

use App\Models\RentalPackage;
use App\Models\Booking; // Untuk cek tanggal yang sudah dibooking
use Carbon\Carbon; 

class RentalAvailabilityService
{
    public function checkAvailability($rentalId, $startDate, $endDate)
    {
        $rental = RentalPackage::find($rentalId);
        
        // 1. Cek apakah rental vehicle tersedia (is_available)
        if (!$rental || !$rental->is_available) {
            return false;
        }
        
        // 2. Cek apakah vehicle sudah dibooking di rentang tanggal tersebut
        // 2. Cek apakah vehicle sudah dibooking di rentang tanggal tersebut
        $hasConflict = Booking::where('bookable_id', $rentalId)
            ->where('bookable_type', RentalPackage::class) // Tambahkan bookable_type
            ->where(function($query) use ($startDate, $endDate) {
                $query->whereBetween('start_date', [$startDate, $endDate])
                      ->orWhereBetween('end_date', [$startDate, $endDate])
                      ->orWhere(function($q) use ($startDate, $endDate) {
                          $q->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                      });
            })
            ->whereIn('status', ['confirmed', 'pending']) // Sesuaikan dengan status yang blocking
            ->exists();
            
        return !$hasConflict; // Return true jika TIDAK ada conflict
    }
    
    public function calculatePrice($rentalId, $startDate, $endDate)
    {
        $rental = RentalPackage::find($rentalId);
        $days = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        
        return $rental->price_per_day * $days;
    }
}