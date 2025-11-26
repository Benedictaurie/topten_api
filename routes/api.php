<?php

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

Route::post('/payment/notification', [PaymentController::class, 'handleNotification']); //public payment callback

// Authenticated routes - All logged in users
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
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
    Route::post('/payments/{transactionId}/retry', [PaymentController::class, 'retryPayment']);
    Route::get('/payment-history', [PaymentController::class, 'paymentHistory']);

    //Review User
    Route::post('/bookings/{bookingId}/review', [ReviewController::class, 'store']);
    Route::get('/bookings/{bookingId}/can-review', [ReviewController::class, 'canReviewBooking']);
    Route::get('/my-reviews', [ReviewController::class, 'myReviews']);
});

//Only Admin Website can access this Route
Route::middleware(['auth:sanctum', 'role:adminWeb'])->group(function () {
    // Package management
    Route::post('/tour-packages', [TourPackageController::class, 'store']);
    Route::put('/tour-packages/{id}', [TourPackageController::class, 'update']);
    Route::delete('/tour-packages/{id}', [TourPackageController::class, 'delete']);
    
    Route::post('/activity-packages', [ActivityPackageController::class, 'store']);
    Route::put('/activity-packages/{id}', [ActivityPackageController::class, 'update']);
    Route::delete('/activity-packages/{id}', [ActivityPackageController::class, 'delete']);
    
    Route::post('/rental-packages', [RentalPackageController::class, 'store']);
    Route::put('/rental-packages/{id}', [RentalPackageController::class, 'update']);
    Route::delete('/rental-packages/{id}', [RentalPackageController::class, 'delete']);
    
    // User management
    Route::get('/admin/users', [UserController::class, 'index']);
    Route::get('/admin/users/{id}', [UserController::class, 'show']);
    Route::put('/admin/users/{id}', [UserController::class, 'update']);
    
    // Booking management
    Route::get('/admin/bookings', [BookingController::class, 'index']);
    Route::put('/admin/bookings/{id}/status', [BookingController::class, 'updateStatus']);
    
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
});

// Multiple roles example (jika diperlukan)
Route::middleware(['auth:sanctum', 'role:adminWeb,owner'])->group(function () {
    Route::get('/management/stats', [UserController::class, 'managementStats']);
});
