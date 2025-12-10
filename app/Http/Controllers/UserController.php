<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApiResponseResources;
use App\Models\User;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function profile()
    {
        $user = User::find(Auth::id());

        return new ApiResponseResources(true, 'Successfully displayed profile', $user);
    }
     /**
     * Update user profile
     */ 
    public function updateProfile(Request $request)
    {
        $messages = [
            'name.required' => 'Name is required',
            'name.min' => 'Name must be at least 3 characters',
            'name.max' => 'Name may not be greater than 100 characters',
            'phone_number.regex' => 'Invalid phone number format',
        ];

        $validator = Validator::make($request->all(), [
            'name' => 'required|min:3|max:100',
            'phone_number' => 'nullable|regex:/^[0-9+\-\s()]+$/',
            'address' => 'nullable|string|max:255',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        try {
            $user = Auth::user();
            $user->update($request->only(['name', 'phone_number', 'address']));

            return new ApiResponseResources(true, 'Profile updated successfully', $user);

        } catch (\Exception $e) {
            Log::error('Profile update failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Profile update failed', null, 500);
        }
    }

    /**
     * Update FCM Token
     * POST /user/fcm-token
     * Update FCM token untuk notifikasi
     */
    public function updateFcmToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fcm_token' => 'required|string'
        ]);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        try {
            $user = Auth::user();
            $user->update(['fcm_token' => $request->fcm_token]);

            return new ApiResponseResources(true, 'FCM token updated successfully', null);

        } catch (\Exception $e) {
            Log::error('FCM token update failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to update FCM token', null, 500);
        }
    }

    /**
     * ADMIN: Get all users (for admin)
     * GET /admin/users
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $users = User::withCount(['bookings', 'rewards'])
                        ->orderBy('created_at', 'desc')
                        ->paginate($perPage);

            return new ApiResponseResources(true, 'Users retrieved successfully', $users);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve users: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve users', null, 500);
        }
    }

    /**
     * ADMIN: Get specific user details
     * GET /admin/users/{id}
     */
    public function show($id)
    {
        try {
            $user = User::with(['bookings', 'reviews'])->find($id);

            if (!$user) {
                return new ApiResponseResources(false, 'User not found', null, 404);
            }

            return new ApiResponseResources(true, 'User details retrieved successfully', $user);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve user details: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve user details', null, 500);
        }
    }

    /**
     * ADMIN: Update user (basic info only)
     * PUT /admin/users/{id}
     */
    public function update(Request $request, $id)
    {
        $messages = [
            'name.required' => 'Name is required',
            'name.min' => 'Name must be at least 3 characters',
            'name.max' => 'Name may not be greater than 100 characters',
            'role.in' => 'Invalid role',
            'phone_number.regex' => 'Invalid phone number format',
        ];

        $validator = Validator::make($request->all(), [
            'name' => 'required|min:3|max:100',
            'phone_number' => 'nullable|regex:/^[0-9+\-\s()]+$/',
            'address' => 'nullable|string|max:255',
            'role' => 'nullable|in:user,admin',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        try {
            $user = User::find($id);

            if (!$user) {
                return new ApiResponseResources(false, 'User not found', null, 404);
            }

            $user->update($request->only(['name', 'phone_number', 'address', 'role']));

            return new ApiResponseResources(true, 'User updated successfully', $user);

        } catch (\Exception $e) {
            Log::error('User update failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'User update failed', null, 500);
        }
    }

    /**
     *  Dashboard statistics untuk mobile
     */
    public function mobileDashboard()
    {
        try {
            $today = now()->toDateString();
            $tomorrow = now()->addDay()->toDateString();

            // 1. Stats utama
            $todayBookings = Booking::whereDate('created_at', $today)->count();
            
            // 2. Booking untuk hari ini & besok dengan bookable relation
            $todayAndTomorrowBookings = Booking::with(['user', 'bookable', 'transactions'])
                ->where(function($query) use ($today, $tomorrow) {
                    $query->whereDate('start_date', $today)
                        ->orWhereDate('start_date', $tomorrow);
                })
                ->orderBy('start_date', 'asc')
                ->orderBy('created_at', 'desc')
                ->get()
                ->groupBy(function($booking) {
                    return $booking->user->name; // Group by user name
                });

            // 3. Format grouped bookings untuk response
            $formattedBookings = $todayAndTomorrowBookings->map(function($userBookings, $userName) {
                return $userBookings->map(function($booking) {
                    return [
                        'id' => $booking->id,
                        'booking_code' => $booking->booking_code,
                        'package_type' => $booking->package_type, // dari accessor
                        'package_name' => $booking->bookable->name ?? 'N/A',
                        'date' => $booking->start_date,
                        'date_display' => $this->formatDateDisplay($booking->start_date),
                        'participants_count' => $booking->quantity,
                        'status' => $booking->status,
                        'payment_status' => $booking->transactions->first()->status ?? 'pending',
                        'total_price' => $booking->total_price
                    ];
                });
            });

            // Booking status counts
            $paidBookingsToday = Booking::whereHas('transactions', function($query) {
                    $query->where('status', 'paid');
                })
                ->whereDate('start_date', $today)
                ->count();
                
            $pendingBookingsToday = Booking::whereHas('transactions', function($query) {
                    $query->where('status', 'pending');
                })
                ->whereDate('start_date', $today)
                ->count();

            $stats = [
                // Stats cards
                'today_bookings' => $todayBookings,
                'paid_bookings' => $paidBookingsToday,
                'pending_bookings' => $pendingBookingsToday,
                
                // Grouped bookings untuk tampilan mobile
                'grouped_bookings' => $formattedBookings,
                
                // Summary stats
                'booking_summary' => [
                    'today' => Booking::whereDate('start_date', $today)->count(),
                    'tomorrow' => Booking::whereDate('start_date', $tomorrow)->count(),
                    'paid' => $paidBookingsToday,
                    'pending' => $pendingBookingsToday
                ]
            ];

            return new ApiResponseResources(true, 'Dashboard data retrieved', $stats);

        } catch (\Exception $e) {
            Log::error('Dashboard error: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve dashboard data', null, 500);
        }
    }

    /**
     * Helper method untuk format tanggal display
     */
    private function formatDateDisplay($date)
    {
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();
        
        if ($date == $today) {
            return 'Today';
        } elseif ($date == $tomorrow) {
            return 'Tomorrow';
        } else {
            return \Carbon\Carbon::parse($date)->format('d/m/Y');
        }
    }

    /**
     * Recent bookings dengan filter
     * GET admin/recent-bookings?days=7&status=paid
     */
    public function recentBookings(Request $request)
    {
        try {
            $days = $request->get('days', 7);
            $status = $request->get('status'); // paid, pending, all
            
            $query = Booking::with(['user', 'bookable', 'transactions'])
                ->where('created_at', '>=', now()->subDays($days))
                ->orderBy('created_at', 'desc');

            if ($status && $status !== 'all') {
                $query->whereHas('transactions', function($q) use ($status) {
                    $q->where('status', $status);
                });
            }

            $bookings = $query->get()
                ->groupBy(function($booking) {
                    return $booking->user->name;
                })
                ->map(function($userBookings, $userName) {
                    return $userBookings->map(function($booking) {
                        return [
                            'id' => $booking->id,
                            'booking_code' => $booking->booking_code,
                            'package_type' => $booking->package_type,
                            'package_name' => $booking->bookable->name ?? 'N/A',
                            'date' => $booking->start_date,
                            'date_display' => $this->formatDateDisplay($booking->start_date),
                            'participants_count' => $booking->quantity,
                            'status' => $booking->status,
                            'payment_status' => $booking->transactions->first()->status ?? 'pending',
                            'total_price' => $booking->total_price,
                            'created_at' => $booking->created_at
                        ];
                    });
                });

            return new ApiResponseResources(true, 'Recent bookings retrieved', $bookings);

        } catch (\Exception $e) {
            Log::error('Recent bookings error: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve recent bookings', null, 500);
        }
    }

    /**
     * System statistics
     * GET /system-stats 
     * System statistics lengkap
     */
    public function systemStats()
    {
        try {
            $totalBookings = Booking::count();
            $activeUsers = User::where('role', 'user')->count();
            
            // Monthly growth (example)
            $lastMonthUsers = User::where('created_at', '>=', now()->subMonth())->count();
            $previousMonthUsers = User::whereBetween('created_at', [now()->subMonths(2), now()->subMonth()])->count();
            
            $userGrowth = $previousMonthUsers > 0 
                ? (($lastMonthUsers - $previousMonthUsers) / $previousMonthUsers) * 100 
                : 0;

            $stats = [
                'total_bookings' => $totalBookings,
                'active_users' => $activeUsers,
                'user_growth_percentage' => round($userGrowth, 2)
            ];

            return new ApiResponseResources(true, 'System statistics retrieved', $stats);

        } catch (\Exception $e) {
            Log::error('System stats error: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve system statistics', null, 500);
        }
    }

    /**
     * Broadcast notification
     */
    public function broadcastNotification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'target_roles' => 'nullable|array',
            'target_roles.*' => 'in:user,admin'
        ]);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        try {
            // Here you would integrate with FCM to send notifications
            // This is a simplified example
            $targetRoles = $request->target_roles ?? ['user', 'admin'];
            
            // Logic to send FCM notifications to users with target roles
            // You would typically use a job queue for this
            
            Log::info('Broadcast notification sent', [
                'title' => $request->title,
                'message' => $request->message,
                'target_roles' => $targetRoles
            ]);

            return new ApiResponseResources(true, 'Notification broadcast initiated', null);

        } catch (\Exception $e) {
            Log::error('Broadcast notification failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to send notification', null, 500);
        }
    }

    /**
     * MANAGEMENT: Stats for both admin 
     * GET /management/stats
     * Bisa diakses admin 
     */
    public function managementStats()
    {
        try {
            $stats = [
                'total_users' => User::count(),
                'total_bookings' => Booking::count(),
                'pending_bookings' => Booking::where('status', 'pending')->count(),
            ];

            return new ApiResponseResources(true, 'Management stats retrieved', $stats);

        } catch (\Exception $e) {
            Log::error('Management stats error: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve management stats', null, 500);
        }
    }
}
