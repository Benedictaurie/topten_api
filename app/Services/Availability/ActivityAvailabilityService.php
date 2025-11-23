<?php

namespace App\Services\Availability;

use App\Models\ActivityPackage;


class ActivityAvailabilityService
{
    public function checkAvailability($activityId, $date, $participants)
    {
        $activity = ActivityPackage::find($activityId);
        
        // 1. Cek apakah activity tersedia (is_available)
        if (!$activity || !$activity->is_available) {
            return false;
        }
        
        // 2. Cek apakah participants memenuhi minimum persons
        if ($participants < $activity->min_persons) {
            return false;
        }
        
        return true;
    }
}