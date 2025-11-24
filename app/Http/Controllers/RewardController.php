<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Resources\ApiResponseResources;
use App\Models\Reward;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class RewardController extends Controller
{
    /**
     * Get user's available rewards
     */
    public function index()
    {
        $user = Auth::user();
        
        $rewards = Reward::where('user_id', $user->id)
            ->where('status', 'available')
            ->where('expired_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get();

        return new ApiResponseResources(true, 'User rewards retrieved successfully', $rewards);
    }

    /**
     * Get user's welcome reward specifically
     */
    public function getWelcomeReward()
    {
        $user = Auth::user();
        
        $welcomeReward = Reward::where('user_id', $user->id)
            ->where('origin', 'welcome')
            ->where('status', 'available')
            ->where('expired_at', '>', now())
            ->first();

        if (!$welcomeReward) {
            return new ApiResponseResources(false, 'No available welcome reward found', null, 404);
        }

        return new ApiResponseResources(true, 'Welcome reward retrieved successfully', $welcomeReward);
    }

    /**
     * Apply reward to booking/checkout
     */
    public function applyReward(Request $request)
    {
        $messages = [
            'reward_id.required' => 'Reward ID is required',
            'reward_id.exists' => 'Reward not found',
            'booking_amount.required' => 'Booking amount is required',
            'booking_amount.numeric' => 'Booking amount must be numeric',
            'booking_amount.min' => 'Booking amount must be at least 0',
        ];

        $validator = Validator::make($request->all(), [
            'reward_id' => 'required|exists:rewards,id',
            'booking_amount' => 'required|numeric|min:0',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        $user = Auth::user();
        $reward = Reward::where('id', $request->reward_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$reward) {
            return new ApiResponseResources(false, 'Reward not found or does not belong to you', null, 404);
        }

        // Check if reward is available
        if ($reward->status !== 'available') {
            return new ApiResponseResources(false, 'Reward is not available', null, 400);
        }

        // Check if reward is expired
        if ($reward->expired_at && $reward->expired_at->isPast()) {
            $reward->update(['status' => 'expired']);
            return new ApiResponseResources(false, 'Reward has expired', null, 400);
        }

        // Check minimum transaction
        if ($reward->min_transaction && $request->booking_amount < $reward->min_transaction) {
            return new ApiResponseResources(
                false, 
                'Minimum transaction amount for this reward is ' . $reward->min_transaction, 
                null, 
                400
            );
        }

        // For welcome reward, check if it's already used (max 1x usage)
        if ($reward->origin === 'welcome' && $reward->status === 'used') {
            return new ApiResponseResources(false, 'Welcome reward can only be used once', null, 400);
        }

        return new ApiResponseResources(
            true, 
            'Reward can be applied', 
            [
                'reward' => $reward,
                'discount_amount' => $reward->amount,
                'final_amount' => max(0, $request->booking_amount - $reward->amount)
            ]
        );
    }

    /**
     * Mark reward as used (to be called from booking service)
     */
    public function markAsUsed($rewardId)
    {
        $reward = Reward::where('id', $rewardId)
            ->where('status', 'available')
            ->first();

        if (!$reward) {
            return false;
        }

        $reward->update([
            'status' => 'used',
            'used_at' => now()
        ]);

        return true;
    }

    /**
     * Get reward usage history
     */
    public function history()
    {
        $user = Auth::user();
        
        $rewards = Reward::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return new ApiResponseResources(true, 'Reward history retrieved successfully', $rewards);
    }
}