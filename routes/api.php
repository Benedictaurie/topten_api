<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ActivityPackageController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RentalPackageController;
use App\Http\Controllers\RewardController;
use App\Http\Controllers\TourPackageController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ReviewController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes - Everyone can access
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/resend-verification-otp', [AuthController::class, 'resendVerificationOtp']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Public package routes
Route::get('/tour-packages/get', [TourPackageController::class, 'index']);
Route::get('/tour-packages/detail/{id}', [TourPackageController::class, 'show']);

Route::get('/activity-packages/get', [ActivityPackageController::class, 'index']);
Route::get('/activity-packages/detail/{id}', [ActivityPackageController::class, 'show']);

Route::get('/rental-packages/get', [RentalPackageController::class, 'index']);
Route::get('/rental-packages/detail/{id}', [RentalPackageController::class, 'show']);

Route::get('/reviews', [ReviewController::class, 'index']);

// Authenticated routes - All logged in users
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/change-password', [AuthController::class, 'updatePassword']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // User profile routes - accessible by all authenticated users
    Route::get('/user/profile', [UserController::class, 'profile']);
    Route::put('/user/profile', [UserController::class, 'updateProfile']);

    Route::post('/user/fcm-token', [UserController::class, 'updateFcmToken']);

    // Detail Booking
    Route::get('/booking/detail/{id}', [BookingController::class, 'show']);
});

//Only Customer can access this Route
Route::middleware(['auth:sanctum', 'role:customer', 'email.verified'])->group(function () {
    // Reward routes
    Route::get('/rewards', [RewardController::class, 'index']);
    Route::get('/rewards/welcome', [RewardController::class, 'getWelcomeReward']);
    Route::post('/rewards/apply', [RewardController::class, 'applyReward']);
    Route::get('/rewards/history', [RewardController::class, 'history']);

    // Booking creation and management for customers
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/my-bookings', [BookingController::class, 'myBookings']);
    Route::post('/bookings/{id}/cancel', [BookingController::class, 'cancel']);

    //Payment transaction
    Route::get('/payments/booking/{bookingId}', [PaymentController::class, 'getBookingPayment']);
    Route::get('/payment-history', [PaymentController::class, 'paymentHistory']);
    Route::post('/bookings/{bookingId}/upload-proof', [PaymentController::class, 'uploadProof']);

    //Review User
    Route::post('/bookings/{bookingId}/review', [ReviewController::class, 'store']);
    Route::get('/bookings/{bookingId}/can-review', [ReviewController::class, 'canReviewBooking']);
    Route::get('/my-reviews', [ReviewController::class, 'myReviews']);
});

//Only Admin Website can access this Route
Route::middleware(['auth:sanctum', 'role:adminWeb'])->group(function () {
    // Package management
    Route::post('/admin/tour-packages', [TourPackageController::class, 'store']);
    Route::put('/admin/tour-packages/{id}', [TourPackageController::class, 'update']);
    Route::delete('/admin/tour-packages/{id}', [TourPackageController::class, 'delete']);
    
    Route::post('/admin/activity-packages', [ActivityPackageController::class, 'store']);
    Route::put('/admin/activity-packages/{id}', [ActivityPackageController::class, 'update']);
    Route::delete('/admin/activity-packages/{id}', [ActivityPackageController::class, 'delete']);
    
    Route::post('/admin/rental-packages', [RentalPackageController::class, 'store']);
    Route::put('/admin/rental-packages/{id}', [RentalPackageController::class, 'update']);
    Route::delete('/admin/rental-packages/{id}', [RentalPackageController::class, 'delete']);
    
    // User management
    Route::get('/admin/users', [UserController::class, 'index']);
    Route::get('/admin/users/{id}', [UserController::class, 'show']);
    Route::put('/admin/users/{id}', [UserController::class, 'update']);
    
    // Booking management
    Route::get('/admin/bookings', [BookingController::class, 'index']);
    Route::put('/admin/bookings/{id}/status', [BookingController::class, 'updateStatus']);

    // Booking confirmation with payment (Admin specific)
    // Route::post('/admin/bookings/{bookingId}/confirm-payment', [AdminController::class, 'confirmBookingWithPayment']);
    // Route::get('/admin/bookings/pending-confirmations', [AdminController::class, 'getPendingConfirmations']);
    // Route::post('/admin/bookings/{bookingId}/manual-payment', [AdminController::class, 'createManualPayment']);
    
    // Review management
    Route::get('/admin/reviews', [ReviewController::class, 'adminIndex']);
    Route::get('/admin/reviews/{id}', [ReviewController::class, 'adminShow']);
    Route::delete('/admin/reviews/{id}', [ReviewController::class, 'adminDelete']);

    // Reward management
    Route::get('/admin/rewards', [RewardController::class, 'adminIndex']);
    Route::post('/admin/rewards', [RewardController::class, 'adminStore']);
    Route::put('/admin/rewards/{id}', [RewardController::class, 'adminUpdate']);
    Route::get('/admin/rewards/stats', [RewardController::class, 'adminStats']);
});

// Owner TOPTEN only routes
Route::middleware(['auth:sanctum', 'role:owner'])->group(function () {
    // Analytics and reports
    Route::get('/owner/dashboard', [UserController::class, 'ownerDashboard']);
    Route::get('/owner/system-stats', [UserController::class, 'systemStats']);
    Route::post('/owner/broadcast-notification', [UserController::class, 'broadcastNotification']);
    
    Route::get('/owner/recent-bookings', [UserController::class, 'recentBookings']);
    // Route::get('/owner/all-users', [UserController::class, 'allUsers']); // Jika perlu view semua user 

    // Owner specific payment/booking management
    // Route::get('/owner/bookings/pending-confirmations', [AdminController::class, 'getPendingConfirmations']);
    // Route::post('/owner/bookings/{bookingId}/confirm-payment', [AdminController::class, 'confirmBookingWithPayment']);
    // Route::post('/owner/bookings/{bookingId}/manual-payment', [AdminController::class, 'createManualPayment']);
});

// ADMIN & OWNER Payment Management Routes (Shared)
Route::middleware(['auth:sanctum', 'role:adminWeb,owner'])->group(function () {
    // Payment management (for both admin and owner)
    Route::get('/management/payments', [PaymentController::class, 'adminIndex']);
    Route::get('/management/payments/{id}', [PaymentController::class, 'adminShow']);
    Route::post('/management/bookings/{bookingId}/create-payment', [PaymentController::class, 'adminCreatePayment']);
    Route::patch('/management/payments/{transactionId}/update', [PaymentController::class, 'adminUpdatePayment']);
    Route::post('/management/payments/{transactionId}/refund', [PaymentController::class, 'processRefund']);
    
    // Payment stats
    Route::get('/management/payments/stats', [PaymentController::class, 'getPaymentStats']);
});

// Multiple roles example (jika diperlukan)
Route::middleware(['auth:sanctum', 'role:adminWeb,owner'])->group(function () {
    Route::get('/management/stats', [UserController::class, 'managementStats']);

    // Shared booking management
    Route::get('/management/bookings', [BookingController::class, 'adminIndex']);
    Route::get('/management/bookings/{id}', [BookingController::class, 'adminShow']);
    Route::put('/management/bookings/{id}/update-status', [BookingController::class, 'adminUpdateStatus']);
});
