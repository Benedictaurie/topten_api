<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BookingController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Route untuk menampilkan form booking (misalnya)
Route::get('/book/{type}/{id}', [BookingController::class, 'create'])->name('booking.create');

// Route untuk PROSES PEMESANAN (ini yang Anda tanyakan)
Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');

// Route untuk halaman sukses
Route::get('/booking/success/{booking}', [BookingController::class, 'success'])->name('booking.success');
