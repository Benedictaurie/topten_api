<?php

use Illuminate\Support\Facades\Route;


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

// Route ini HARUS bernama 'password.reset' agar fungsi url(route('password.reset', ...))
// di Mailable Anda dapat membuat link dengan benar.

Route::get('/password/reset/{token}', function ($token) {
    // Ambil URL frontend (React Web) dari file .env
    $frontendUrl = env('FRONTEND_WEB_URL');

    if (!$frontendUrl) {
        // Handle jika URL frontend belum dikonfigurasi
        abort(500, "FRONTEND_WEB_URL configuration in the .env file was not found.");
    }

    // Arahkan ke halaman reset password di React Web App
    // Asumsi path di frontend adalah '/reset-password'
    $redirectUrl = "{$frontendUrl}/reset-password?token={$token}&email=" . request('email');

    return redirect($redirectUrl);

})->name('password.reset');