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
Route::get('/tour-packages', [TourPackageController::class, 'index']);
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

//Only User can access this Route
Route::middleware(['auth:sanctum', 'user', 'email.verified'])->group(function () {
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

// // Package management admin 
//     Route::get('/admin/tour-packages', [TourPackageController::class, 'adminIndex']);
//     Route::post('/admin/tour-packages', [TourPackageController::class, 'store']);
//     Route::put('/admin/tour-packages/{id}', [TourPackageController::class, 'update']);
//     Route::delete('/admin/tour-packages/{id}', [TourPackageController::class, 'delete']);
    
//     Route::get('/admin/activity-packages', [ActivityPackageController::class, 'adminIndex']);
//     Route::post('/admin/activity-packages', [ActivityPackageController::class, 'store']);
//     Route::put('/admin/activity-packages/{id}', [ActivityPackageController::class, 'update']);
//     Route::delete('/admin/activity-packages/{id}', [ActivityPackageController::class, 'delete']);
    
//     Route::get('/admin/rental-packages', [RentalPackageController::class, 'adminIndex']);
//     Route::post('/admin/rental-packages', [RentalPackageController::class, 'store']);
//     Route::put('/admin/rental-packages/{id}', [RentalPackageController::class, 'update']);
//     Route::delete('/admin/rental-packages/{id}', [RentalPackageController::class, 'delete']);

//Only Admin can access this Route
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    
    // Package management
    Route::get('/admin/tour-packages', [TourPackageController::class, 'adminIndex']);
    Route::post('/admin/tour-packages', [TourPackageController::class, 'store']);
    Route::put('/admin/tour-packages/{id}', [TourPackageController::class, 'update']);
    Route::delete('/admin/tour-packages/{id}', [TourPackageController::class, 'delete']);
    
    Route::get('/admin/activity-packages', [ActivityPackageController::class, 'adminIndex']);
    Route::post('/admin/activity-packages', [ActivityPackageController::class, 'store']);
    Route::put('/admin/activity-packages/{id}', [ActivityPackageController::class, 'update']);
    Route::delete('/admin/activity-packages/{id}', [ActivityPackageController::class, 'delete']);
    
     Route::get('/admin/rental-packages', [RentalPackageController::class, 'adminIndex']);
    Route::post('/admin/rental-packages', [RentalPackageController::class, 'store']);
    Route::put('/admin/rental-packages/{id}', [RentalPackageController::class, 'update']);
    Route::delete('/admin/rental-packages/{id}', [RentalPackageController::class, 'delete']);
    
    // User management
    Route::get('/admin/users', [UserController::class, 'index']);
    Route::get('/admin/users/{id}', [UserController::class, 'show']);
    Route::put('/admin/users/{id}', [UserController::class, 'update']); //or {user_id}
    
    // Booking management
    Route::get('/admin/bookings', [BookingController::class, 'index']);
    Route::put('/admin/bookings/{id}/status', [BookingController::class, 'updateStatus']);
    
    // Review management
    Route::get('/admin/reviews', [ReviewController::class, 'adminIndex']);
    Route::get('/admin/reviews/{id}', [ReviewController::class, 'adminShow']);
    Route::delete('/admin/reviews/{id}', [ReviewController::class, 'adminDelete']);

    // Payment management 
    Route::get('/admin/payments', [PaymentController::class, 'adminIndex']);
    Route::get('/admin/payments/{id}', [PaymentController::class, 'adminShow']);
    Route::post('/admin/bookings/{bookingId}/create-payment', [PaymentController::class, 'adminCreatePayment']);
    Route::patch('/admin/payments/{transactionId}/update', [PaymentController::class, 'adminUpdatePayment']);
    Route::post('/admin/payments/{transactionId}/refund', [PaymentController::class, 'processRefund']);
    
    // Payment stats
    Route::get('/admin/payments/stats', [PaymentController::class, 'getPaymentStats']);

    // Analytics and reports in Mobile App
    Route::get('/admin/dashboard', [UserController::class, 'mobileDashboard']);
    Route::get('/admin/system-stats', [UserController::class, 'systemStats']);
    Route::post('/admin/broadcast-notification', [UserController::class, 'broadcastNotification']);
    
    Route::get('/admin/recent-bookings', [UserController::class, 'recentBookings']);
});

