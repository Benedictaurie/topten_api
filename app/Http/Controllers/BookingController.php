<?php

namespace App\Http\Controllers;

use App\Models\TourPackage;
use App\Models\ActivityPackage;
use App\Models\RentalPackage;
use App\Models\Booking;
use App\Models\User;
use App\Models\PaymentTransaction;
use App\Models\BookingLog;
use App\Http\Controllers\PaymentController;
use App\Notifications\NewBookingNotification; 
use App\Services\Notification\FirebaseNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth; 
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\ApiResponseResources;
use App\Models\BookingReward;
use App\Models\Reward;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon; // Tambahkan ini untuk manipulasi tanggal
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    /**
     * Menampilkan form booking untuk paket tertentu.
     * URL: /book/{type}/{id} -> (contoh: /book/tour/1)
     */
    public function create($type, $id)
    {
        $model = match ($type) {
            'tour' => TourPackage::class,
            'activity' => ActivityPackage::class,
            'rental' => RentalPackage::class,
            default => abort(404)
        };

        $package = $model::findOrFail($id);

        return view('bookings.create', [
            'package' => $package,
            'package_type' => $type,
        ]);
    }

    /**
     * Menyimpan data booking baru ke database.
     */
    public function store(Request $request)
    {
        $messages = [
            'package_type.required' => 'The package type is required.',
            'package_type.in' => 'The package type is invalid.',
            'package_id.required' => 'The package ID is required.',
            'quantity.min' => 'The minimum number of participants is 1.',
            'start_date.after_or_equal' => 'The start date cannot be in the past.',
            'end_date.required_if' => 'The end date is required for rentals.',
            'end_date.after_or_equal' => 'The end date must be after or equal to the start date.',
            'reward_ids.array' => 'The reward IDs must be an array.',
            'reward_ids.*.exists' => 'One of the selected rewards is invalid.',
        ];
        $validator = Validator::make($request->all(),[
            'package_type' => 'required|in:tour,activity,rental',
            'package_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required_if:package_type,rental|date|after_or_equal:start_date',
            'notes' => 'nullable|string|max:500',
            'reward_ids' => 'nullable|array',
            'reward_ids.*' => 'exists:rewards,id',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }
        
        $validated = $validator->validated();
        
        try {
            $bookingData = DB::transaction(function () use ($validated) {
                $user = auth()->user();
                $packageModel = match($validated['package_type']) {
                    'tour' => TourPackage::class,
                    'activity' => ActivityPackage::class,
                    'rental' => RentalPackage::class,
                };

                $package = $packageModel::findOrFail($validated['package_id']);
                
                // --- LOGIKA HARGA ---
                $unitPrice = $package->price_per_person ?? $package->price_per_day;
                $totalPrice = $unitPrice * $validated['quantity'];

                // --- LOGIKA REWARD ---
                $rewardTotalApplied = 0;
                $validRewardsToApply = collect();

                if (!empty($validated['reward_ids'])) {
                    $validRewardsToApply = Reward::where('user_id', $user->id)
                        ->whereIn('id', $validated['reward_ids'])
                        ->where('status', 'available')
                        ->where(function ($query) {
                            $query->whereNull('expired_at')->orWhere('expired_at', '>', now());
                        })
                        ->where(function ($query) use ($validated) {
                            $query->where('applies_to', 'all')->orWhere('applies_to', $validated['package_type']);
                        })
                        ->get();

                    // Filter reward yang memenuhi minimum transaksi
                    $applicableRewards = $validRewardsToApply->filter(function ($reward) use ($totalPrice) {
                        return is_null($reward->min_transaction) || $totalPrice >= $reward->min_transaction;
                    });

                    $rewardTotalApplied = $applicableRewards->sum('amount');
                    $validRewardsToApply = $applicableRewards;
                }

                $finalPrice = max(0, $totalPrice - $rewardTotalApplied);

                // --- LOGIKA END DATE ---
                $startDate = Carbon::parse($validated['start_date']);
                $endDate = null;
                if ($validated['package_type'] === 'tour') {
                    $endDate = $startDate->copy()->addDays($package->duration_days - 1);
                } elseif ($validated['package_type'] === 'rental') {
                    $endDate = Carbon::parse($validated['end_date']);
                }

                // --- SIMPAN BOOKING ---
                $booking = Booking::create([
                    'booking_code' => 'BK-' . strtoupper(Str::random(8)),
                    'user_id' => $user->id,
                    'bookable_id' => $package->id,
                    'bookable_type' => $packageModel,
                    'quantity' => $validated['quantity'],
                    'start_date' => $startDate,
                    'end_date' => $endDate, 
                    'unit_price_at_booking' => $unitPrice,
                    'total_price' => $totalPrice,
                    'reward_total_applied' => $rewardTotalApplied,
                    'final_price' => $finalPrice,
                    'notes' => $validated['notes'],
                    'status' => 'pending',
                ]);

                // --- SIMPAN KE PIVOT TABLE booking_rewards ---
                if ($validRewardsToApply->isNotEmpty()) {
                    $pivotData = $validRewardsToApply->map(function ($reward) use ($booking) {
                        return [
                            'booking_id' => $booking->id,
                            'reward_id' => $reward->id,
                            'applied_amount' => $reward->amount,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    })->toArray();
                    
                    BookingReward::insert($pivotData);
                }

                // --- BUAT BOOKING LOG AWAL ---
                BookingLog::create([
                    'booking_id' => $booking->id,
                    'user_id' => $user->id, // User yang membuat booking
                    'old_status' => null,
                    'new_status' => 'pending',
                    'notes' => 'Booking created successfully.',
                ]);

                // --- KIRIM NOTIFIKASI KE OWNER DAN ADMIN ---
                $adminsAndOwners = User::whereIn('role', ['owner', 'admin'])->get(); 
                if ($adminsAndOwners->isNotEmpty()) {
                    foreach ($adminsAndOwners as $admin) {
                        $admin->notify(new NewBookingNotification($booking));
                        if ($admin->fcm_token) {
                            $firebaseService = app(FirebaseNotificationService::class);
                            $firebaseService->sendToDevice(
                                $admin->fcm_token,
                                'New Booking!',
                                'New booking for ' . $booking->bookable->name . ' from ' . $user->name,
                                [
                                    'booking_code' => $booking->booking_code,
                                    'booking_id' => $booking->id,
                                    'type' => 'new_booking'
                                ]
                            );
                        }
                    }
                }

                return ['booking' => $booking];
            });

            // --- RETURN RESPONSE ---
            return new ApiResponseResources(true, 'Booking successful! Our team will contact you for confirmation.', [
                'booking' => $bookingData['booking']->load('bookable', 'rewards'),
            ], 201);

        } catch (\Exception $e) {
            return new ApiResponseResources(false, 'Failed to create booking: ' . $e->getMessage(), null, 500);
        }
    }

    public function cancel($id)
    {
        try {
            $user = Auth::user();
            $booking = Booking::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$booking) {
                return new ApiResponseResources(false, 'Booking not found', null, 404);
            }

            // Only allow cancellation for pending bookings
            if ($booking->status !== 'pending') {
                return new ApiResponseResources(false, 'Only pending bookings can be cancelled', null, 422);
            }

            $booking->update(['status' => 'cancelled']);

            // Log the cancellation
            BookingLog::create([
                'booking_id' => $booking->id,
                'user_id' => $user->id,
                'old_status' => 'pending',
                'new_status' => 'cancelled',
                'notes' => 'Booking cancelled by user',
            ]);

            return new ApiResponseResources(true, 'Booking cancelled successfully', $booking);

        } catch (\Exception $e) {
            Log::error('Booking cancellation failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to cancel booking', null, 500);
        }
    }

    /**
     * Menampilkan halaman sukses setelah booking.
     */
    public function success(Booking $booking)
    {
        // --- SECURITY ---
        // Memastikan hanya user yang membuat booking yang bisa lihat halaman ini
        if (Auth::id() !== $booking->user_id) {
            abort(403); // Akses ditolak
        }

        $booking->load('bookable');
        
        return view('bookings.success', [
            'booking' => $booking,
        ]);
    }

    /**
     * Menampilkan detail booking berdasarkan ID.
     */
    public function show($id)
    {
        // 1. Cari booking berdasarkan ID
        $booking = Booking::with(['bookable', 'user', 'transactions', 'statusLogs', 'rewards']) 
                             ->find($id);

        if (!$booking) {
            return new ApiResponseResources(false, 'Booking not found.', null, 404);
        }

        // 2. Otorisasi: Pastikan hanya user pembuat booking atau admin/owner yang bisa melihatnya.
        // Asumsi: Jika role user adalah 'admin' atau 'owner', mereka bisa melihat semua booking.
        $user = Auth::user();
        $isAuthorized = $user && (
            $booking->user_id === $user->id || 
            in_array($user->role, ['admin', 'owner'])
        );

        if (!$isAuthorized) {
            return new ApiResponseResources(false, 'Access denied. You do not have permission to view the details of this booking.', null, 403);
        }

        // 3. Kembalikan detail booking
        return new ApiResponseResources(true, 'Booking details displayed successfully.', $booking);
    }

    public function myBookings()
    {
        try {
            $user = Auth::user();
            $bookings = Booking::where('user_id', $user->id)
                ->with(['bookable', 'transactions', 'rewards'])
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            return new ApiResponseResources(true, 'Your bookings retrieved successfully', $bookings);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve user bookings: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve your bookings', null, 500);
        }
    }

    /**
     * Menampilkan riwayat booking untuk user yang sedang login.
     */
    public function history()
    {
        $bookings = Booking::where('user_id', auth()->id())
                            ->with(['bookable', 'transactions', 'rewards']) // <<< Tambahkan relasi lain jika diperlukan
                            ->latest()
                            ->paginate(10);

        return view('users.booking_history', compact('bookings'));
    }
}