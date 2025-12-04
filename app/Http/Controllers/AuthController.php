<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Resources\ApiResponseResources;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Reward;
use App\Services\Otp\OtpService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\PasswordChangedMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    protected $otpService;

    protected $tokenLifetime = 60; // Token akan berlaku 60 menit (Standar Laravel)

    public function __construct(OtpService $otpService)
    {
        $this->otpService = $otpService;
    }

    public function register(Request $request)
    {
        $messages = [
            'name.required' => 'Name is required',
            'name.min' => 'Name must be at least 3 characters!', //contoh: Aditya Arya Anandito
            'name.max' => 'Name may not be greater than 100 characters!', 
            'email.required' => 'Email is required', 
            'email.email' => 'Invalid email format!', //error message jika field email tdk sesuai format email yang benar
            'email.unique' => 'Email is already taken!', 
            'password.required' => 'Password is required!',
            'password.min' => 'Password must be at least 8 characters!',
            'password.confirmed' => 'Password confirmation does not match'
        ];
        $validator = Validator::make($request->all(), [
            'name' => 'required|min:3|max:100',
            'email'     => 'required|email|unique:users',
            'password'  => 'required|min:8',   
            'phone_number' => 'nullable|string|max:20', 
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422); //422: Unprocessable Entity 
        }
        try {
            DB::beginTransaction();

            // Membuat user baru
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone_number' => $request->phone_number,
                'role' => 'customer', // Default role
            ]);

            // Generate OTP
            $this->otpService->generate($request->email);

            // BUAT LIMITED TOKEN (hanya untuk verify email)
            $user->tokens()->delete();
            $verifyToken = $user->createToken(
                'verify_token',
                ['verify-email'], // Limited scope
                now()->addMinutes(30) // Expire dalam 30 menit
            )->plainTextToken;

            // GENERATE OTP untuk email verification
            $this->otpService->generate($request->email);

            DB::commit();

            // RESPONSE TANPA TOKEN - user harus verify email dulu
            return new ApiResponseResources(
                true,
                'Registration successful! Please check your email for OTP verification.',
                [
                    'user' => $user->makeHidden(['password', 'remember_token']),
                    'email_verified' => false
                ],
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Registration failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Registration failed. Please try again.', null, 500);
        }
    }

    public function verifyEmail(Request $request)
    {
        // 1. Input Validation (Adopting Your friend's OTP structure)
        $messages = [
            'otp.required' => 'The OTP code is required!',
            'otp.digits' => 'The OTP code must be 6 digits!',
            'otp.integer' => 'The OTP code must be an integer',
        ];

        $validator = Validator::make($request->all(), [
            'otp' => 'required|integer|digits:6',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422); // 422: Unprocessable Entity 
        }

        // Get the authenticated user
        $user = Auth::user();

        /** @var \App\Models\User|null $user */ // Memberi tahu: $user bisa Model User atau NULL

        if (!$user) {
            return new ApiResponseResources(false, 'User not authenticated', null, 401);
        }
        
        // Check if the email is already verified
        if ($user->email_verified_at) {
        return new ApiResponseResources(true, 'Your email is already verified', [
            'user' => $user->makeHidden(['password', 'remember_token']),
            'email_verified' => true // boolean untuk response
        ]);
    }

        // 2. OTP Verification - PERBAIKAN DI SINI
        // Hanya kirim OTP saja, karena OtpService akan ambil email dari Auth::user()
        $succeed = $this->otpService->verify($request->otp); // ← HANYA 1 PARAMETER
        
        if (!$succeed) {
            // Failed OTP Message
            return new ApiResponseResources(false, 'Incorrect or Expired OTP Code!', null, 422);
        }

        // 3. Update Verification Status & Reward
        try {
            DB::beginTransaction();

            // Apply email verification timestamp
            $user->email_verified_at = now();
            $user->save();

            // Create welcome reward after email verification
            $reward = $this->createWelcomeReward($user);

            DB::commit();

            $message = 'Email verification successful!';
            if ($reward) {
                $message .= ' Welcome reward has been added to your account.';
            }

            return new ApiResponseResources(true, $message, [
                'user' => $user->makeHidden(['password', 'remember_token']),
                'email_verified' => true,
                'reward' => $reward
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Email verification and reward failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Email verification failed due to system error', null, 500);
        }

        if ($this->otpService->verify($request->otp)) {
            // Update verification status
            $user->email_verified_at = now();
            $user->save();
            
            // HAPUS verify token, BUAT full access token
            $user->tokens()->delete();
            $accessToken = $user->createToken('access_token')->plainTextToken;
            
            // Create welcome reward
            $reward = $this->createWelcomeReward($user);
            
            return new ApiResponseResources(
                true,
                'Email verified successfully!',
                [
                    'user' => $user,
                    'access_token' => $accessToken, // FULL ACCESS TOKEN
                    'email_verified' => true,
                    'reward' => $reward
                ]
            );
        }
    
        return new ApiResponseResources(false, 'Invalid OTP', null, 422);
    }

     public function resendOtp(Request $request)
    {
        if (!Auth::check()) {
            return new ApiResponseResources(false, 'Unauthorized', null, 401);
        }

        $user = Auth::user();
        
        try {
            $this->otpService->generate($user->email);
            return new ApiResponseResources(true, 'OTP successfully resent');
        } catch (\Exception $e) {
            return new ApiResponseResources(false, 'Failed to send OTP: ' . $e->getMessage(), null, 500);
        }
    }


    /**
     * Create welcome reward for verified user
     */
    private function createWelcomeReward(User $user)
    {
        try {
            $reward = Reward::create([
                'user_id' => $user->id,
                'origin' => 'welcome',
                'amount' => 50000, // Adjust amount as needed
                'type' => 'promo',
                'status' => 'available',
                'description' => 'Welcome Promo for new verified user',
                'applies_to' => 'all',
                'min_transaction' => 100000, // Minimum transaction amount
                'promo_code' => 'WELCOME' . strtoupper(Str::random(6)),
                'expired_at' => now()->addDays(14), // Expires in 14 days
            ]);

            return $reward;
        } catch (\Exception $e) {
            Log::error('Failed to create welcome reward: ' . $e->getMessage());
            return null;
        }
    }

    public function login(Request $request)
    {
        $messages = [
            'email.required' => 'Email is required!',
            'email.email' => 'Invalid email format!',
            'password.required' => 'Password is required!',
            'password.min' => 'Password must be at least 8 characters!',
        ];

        $validator = Validator::make($request->all(), [
            'email'     => 'required|email',
            'password'  => 'required|min:8',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        try {
            $user = User::where('email', $request->email)->first();
            
            if (!$user || !Hash::check($request->password, $user->password)) {
                return new ApiResponseResources(false, 'Email or Password is incorrect', null, 422);
            }

            // KASIH TEMPORARY TOKEN MESKI BELUM VERIFIED
            $user->tokens()->delete();
            $token = $user->createToken('auth_token')->plainTextToken;

            // GENERATE OTP SETIAP LOGIN (jika belum verified)
            if (!$user->email_verified_at) {
                $this->otpService->generate($user->email);
                
                return new ApiResponseResources(
                    false, // Login technically gagal
                    'Please verify your email first',
                    [
                        'user' => $user->makeHidden(['password', 'remember_token']),
                        'token' => $token, // KASIH TOKEN untuk verify
                        'email_verified' => false // boolean untuk response
                    ],
                    403
                );
            }

            // EMAIL SUDAH VERIFIED
            return new ApiResponseResources(
                true, 
                'Login successful', 
                [
                    'user' => $user->makeHidden(['password', 'remember_token']),
                    'token' => $token,
                    'email_verified' => true // ✅ boolean untuk response
                ]
            );

        } catch (\Exception $e) {
            Log::error('Login failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Login failed. Please try again.', null, 500);
        }
    }

    public function logout(Request $request)
    {
       try {
            $request->user()->currentAccessToken()->delete();
            return new ApiResponseResources(true, 'Logout Successful');
        } catch (\Exception $e) {
            Log::error('Logout error: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Logout failed', null, 500);
        }
    }

    /**
     * [API Endpoint] Request password reset link (Forgot Password)
     * Endpoint: POST /api/forgot-password
     */
    public function forgotPassword(Request $request)
    {
        $messages = [
            'email.required' => 'The email field is required!',
            'email.email' => 'Invalid email format!',
            'email.exists' => 'The email is not registered!',
        ];

        $validator = Validator::make($request->all(), [
            'email'=> 'required|email|exists:users,email',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        $user = User::where('email', $request->email)->first();

        // 1. Generate Plain Token (Token yang akan dikirim di URL)
        $plainToken = Str::random(64); 

        // 2. Hash Token sebelum disimpan ke database (Keamanan)
        $hashedToken = Hash::make($plainToken);

        // 3. Simpan token ke database (dengan created_at untuk kedaluwarsa)
        try {
             DB::table('temp_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => $hashedToken,
                    'expired_at' => Carbon::now()->addMinutes($this->tokenLifetime),
                    'created_at' => Carbon::now(),
                    'updated_at' => now()
                ],
            );
        } catch (\Exception $e) {
            Log::error('Failed to save password reset token: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to process password reset. Please try again.', null, 500);
        }

        // 4. Kirim email (menggunakan token non-hashed untuk link)
        try {
            // Gunakan $user dan $plainToken
            Mail::to($user->email)->send(new PasswordChangedMail($user, $plainToken));
        } catch (\Exception $e) {
            Log::error('Failed to send password reset email: ' . $e->getMessage());
            // Beri respons sukses meskipun email gagal dikirim (untuk menghindari enumerasi email)
        }

        // Beri respons sukses (sama seperti Laravel default: beri tahu user, link sudah dikirim)
        return new ApiResponseResources(true, 'A password reset link has been sent to your email.');
    }

    /*/**
     * [API Endpoint] Reset password with token (from email link)
     * Endpoint: POST /api/reset-password
     */
    public function resetPassword(Request $request)
    {
        // Catatan: Karena ini adalah API endpoint, Anda mungkin perlu menerima email juga dari client.
        $messages = [
            'email.required' => 'The email field is required.',
            'token.required' => 'The token field is required.',
            'password.required' => 'The new password is required!',
            'password.min' => 'The password must be at least 8 characters!',
            'password.confirmed' => 'The password confirmation does not match the new password!', // Menggunakan confirmed Laravel
        ];

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'token' => 'required|string',
            // Gunakan 'confirmed' untuk validasi di Laravel
            'password'=> 'required|min:8|confirmed',
            'password_confirmation' => 'required|min:8', // Harus sesuai nama input konfirmasi
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        // 1. Cari token di database
        $reset = DB::table('temp_tokens')
                    ->where('email', $request->email)
                    ->first();

        if (!$reset) {
            return new ApiResponseResources(false, 'Email not found in the reset queue.', null, 400);
        }

        // 2. Verifikasi Token (Harus menggunakan Hash::check karena kita menyimpan versi hashed)
        if (!Hash::check($request->token, $reset->token)) {
            return new ApiResponseResources(false, 'Token is invalid.', null, 400);
        }

        // 3. Periksa Kedaluwarsa Token (60 menit)
        if (Carbon::now()->gt(Carbon::parse($reset->expired_at))) {
            // Delete expired token
            DB::table('temp_tokens')->where('email', $request->email)->delete();
            return new ApiResponseResources(false, 'Password reset token has expired.', null, 400);
        }

        // 4. Update password
        $user = User::where('email', $reset->email)->first();
        if (!$user) {
            return new ApiResponseResources(false, 'User not found', null, 404);
        }

        // Hash dan update password
        $user->update(['password' => Hash::make($request->password)]);

        // 5. Hapus Token Reset
        DB::table('temp_tokens')->where('email', $reset->email)->delete();

        return new ApiResponseResources(true, 'Password updated successfully. You can now log in.');
    }

    public function updatePassword(Request $request)
    {
        $messages = [
            'current_password.required' => 'Current password is required',
            'password.required' => 'New password is required',
            'password.confirmed' => 'Password confirmation does not match',
            'password.min' => 'Password must be at least 8 characters',
        ];

        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'password' => 'required|confirmed|min:8',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password)) {
            return new ApiResponseResources(false, 'The current password is incorrect.', null, 400);
        }

        try {
            // Update password
            $user->password = Hash::make($request->password);
            $user->save();

            // Send confirmation email (optional)
            try {
                // You can create a different email template for profile password change
                Mail::to($user->email)->send(new PasswordChangedMail($user, 'profile_change'));
            } catch (\Exception $e) {
                Log::error('Failed to send password change notification: ' . $e->getMessage());
            }

            return new ApiResponseResources(true, 'Password changed successfully.', null, 200);

        } catch (\Exception $e) {
            Log::error('Password change failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Password change failed.', null, 500);
        }
    }
}
