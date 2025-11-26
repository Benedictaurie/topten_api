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

    /**
     * ADMIN: Get all rewards with filters
     */
    public function adminIndex(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $status = $request->get('status');
            $origin = $request->get('origin');
            $userId = $request->get('user_id');

            $query = Reward::with(['user'])
                ->orderBy('created_at', 'desc');

            if ($status) {
                $query->where('status', $status);
            }

            if ($origin) {
                $query->where('origin', $origin);
            }

            if ($userId) {
                $query->where('user_id', $userId);
            }

            $rewards = $query->paginate($perPage);

            return new ApiResponseResources(true, 'Admin rewards retrieved successfully', $rewards);

        } catch (\Exception $e) {
            \Log::error('Admin rewards retrieval failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve rewards', null, 500);
        }
    }

    /**
     * ADMIN: Create new reward for user
     */
    public function adminStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0',
            'origin' => 'required|in:welcome,promotion,referral,manual',
            'expired_at' => 'required|date|after:today',
            'min_transaction' => 'nullable|numeric|min:0',
            'applies_to' => 'nullable|in:all,tour,activity,rental',
        ]);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        try {
            $reward = Reward::create([
                'user_id' => $request->user_id,
                'amount' => $request->amount,
                'origin' => $request->origin,
                'status' => 'available',
                'expired_at' => $request->expired_at,
                'min_transaction' => $request->min_transaction,
                'applies_to' => $request->applies_to ?? 'all',
            ]);

            $reward->load('user');

            return new ApiResponseResources(true, 'Reward created successfully', $reward, 201);

        } catch (\Exception $e) {
            \Log::error('Admin reward creation failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to create reward', null, 500);
        }
    }

    /**
     * ADMIN: Update reward
     */
    public function adminUpdate(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:available,used,expired',
            'expired_at' => 'nullable|date|after:today',
            'min_transaction' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        try {
            $reward = Reward::find($id);
            
            if (!$reward) {
                return new ApiResponseResources(false, 'Reward not found', null, 404);
            }

            $reward->update($request->only(['amount', 'status', 'expired_at', 'min_transaction']));
            $reward->load('user');

            return new ApiResponseResources(true, 'Reward updated successfully', $reward);

        } catch (\Exception $e) {
            \Log::error('Admin reward update failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to update reward', null, 500);
        }
    }

    /**
     * ADMIN: Get reward statistics
     */
    public function adminStats()
    {
        try {
            $stats = [
                'total_rewards' => Reward::count(),
                'available_rewards' => Reward::where('status', 'available')->count(),
                'used_rewards' => Reward::where('status', 'used')->count(),
                'expired_rewards' => Reward::where('status', 'expired')->count(),
                'total_discount_given' => Reward::where('status', 'used')->sum('amount'),
                'origin_distribution' => Reward::selectRaw('origin, count(*) as count')
                    ->groupBy('origin')
                    ->get()
            ];

            return new ApiResponseResources(true, 'Reward statistics retrieved', $stats);

        } catch (\Exception $e) {
            \Log::error('Admin reward stats failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve reward statistics', null, 500);
        }
    }
}
