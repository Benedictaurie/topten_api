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
        ];
        $validator = Validator::make($request->all(), [
            'name' => 'required|min:3|max:100',
            'email'     => 'required|email|unique:users',
            'password'  => 'required|min:8',    
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422); //422: Unprocessable Entity 
        }
        //Membuat user baru
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        if (!$user) {
            return new ApiResponseResources(false, 'Registration Failed!', null, 422);
        }

        //generate token
        $token = $user->createToken('auth_token')->plainTextToken;
        $this->otpService->generate($request->email);

        return new ApiResponseResources(
            true,
            'Registration Successful!',
            [
                'user' => $user,
                'token' => $token,
            ],
            201
        );
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
            // Message if already verified
            return new ApiResponseResources(true, 'Your email is already verified', null);
        }

        // 2. OTP Verification (Using Your OtpService)
        // Assumption: OtpService::verify() uses the authenticated user's email for verification
        $succeed = $this->otpService->verify($request->otp, $user->email); 
        
        if (!$succeed) {
            // Failed OTP Message (Like your friend's code)
            return new ApiResponseResources(false, 'Incorrect or Expired OTP Code!', null, 422);
        }

        // 3. Update Verification Status & Reward
        try {
            // Apply email verification timestamp
            $user->email_verified_at = now();
            
            if (isset($user->email_verified)) {
                $user->email_verified = 1;
            }

            $user->save();

            // Create welcome reward after email verification
            $reward = $this->createWelcomeReward($user);

            // Success Message with Promo Information
            $message = 'Email Verification Successful';

            if ($reward) {
                $message .= '! Congratulations, you received a Welcome Promo that has been added to your account.';
            } else {
                $message .= '.'; // If reward creation failed, the message is still successful.
            }

            return new ApiResponseResources(true, $message, [
                'reward' => $reward // Returning the created reward data
            ]);

        } catch (\Exception $e) {
            Log::error('Email verification and reward failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Email Verification Failed due to a system error', null, 500);
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

        $user = User::where('email', $request->email)->first();
        if (!$user || !Hash::check($request->password, $user->password)) {
            return new ApiResponseResources(false, 'Email or Password is incorrect', null, 422);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $this->otpService->generate($user->email);

        // Cek verifikasi email - prioritaskan email_verified_at
        $isVerified = $user->email_verified_at !== null;

        if (!$isVerified) {
            return new ApiResponseResources(
                true,
                'Please verify your email first',
                [
                    'user' => $user,
                    'token' => $token,
                    'email_verified' => false
                ],
                200
            );
        }

        return new ApiResponseResources(true, 'Login Successful!', [
            'user'  => $user,
            'token' => $token,
            'email_verified' => true
        ]);
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
     * [API Endpoint] Menangani permintaan Lupa Kata Sandi (Forgot Password).
     * Mengirim link reset (dengan token) ke email pengguna.
     * * @param Request $request [email]
     * @return ApiResponseResources
     */
    public function forgetPassword(Request $request)
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
             DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => $hashedToken,
                    'created_at' => Carbon::now()
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

    /**
     * [API Endpoint] Menangani perubahan kata sandi setelah pengguna menekan link reset.
     * Memeriksa token, mengubah kata sandi, dan menghapus token.
     * * @param Request $request [token, email, password, confirmPassword]
     * @return ApiResponseResources
     */
    public function changePassword(Request $request)
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
        $reset = DB::table('password_reset_tokens')
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
        $expirationTime = Carbon::parse($reset->created_at)->addMinutes($this->tokenLifetime);
        if (Carbon::now()->greaterThan($expirationTime)) {
             // Hapus token yang kedaluwarsa
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return new ApiResponseResources(false, 'Password reset token has expired', null, 400);
        }

        // 4. Update Kata Sandi
        $user = User::where('email', $reset->email)->first();
        if (!$user) {
            return new ApiResponseResources(false, 'User not found', null, 404);
        }

        // Hash dan update password
        $user->update(['password' => Hash::make($request->password)]);

        // 5. Hapus Token Reset
        DB::table('password_reset_tokens')->where('email', $reset->email)->delete();

        return new ApiResponseResources(true, 'Password updated successfully. You can now log in.');
    }
}
