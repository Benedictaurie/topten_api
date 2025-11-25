<?php

namespace App\Services\Otp; // Sesuaikan namespace dengan lokasi folder

use App\Mail\OtpMail;
use App\Models\TempToken; // <<< Menggunakan Model TempToken Anda
use App\Models\User; // Perlu diimpor jika ingin menggunakan Type Hinting User
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OtpService
{
    // Konfigurasi dasar
    protected $expiryMinutes = 5;
    protected $tokenLength = 6;
    
    /**
     * Menghasilkan, menyimpan, dan mengirim OTP ke email pengguna.
     * * @param string $email
     * @return TempToken
     */
    public function generate(string $email): TempToken
    {
        // 1. Cek apakah token lama (berdasarkan email) sudah ada.
        // Jika ada, kita perbarui (overwrite) untuk menghindari banyak entri yang tidak perlu.
        $tempToken = TempToken::where('email', $email)->first();

        // Hasilkan OTP baru (6 digit)
        $newOtp = random_int(100000, 999999);
        $expiredAt = Carbon::now()->addMinutes($this->expiryMinutes);

        if (!$tempToken) {
            // Jika token belum ada, buat entri baru
            $tempToken = TempToken::create([
                'email'      => $email,
                'token'      => $newOtp,
                'expired_at' => $expiredAt,
            ]);
        } else {
            // Jika token sudah ada, perbarui token dan waktu kadaluarsa
            $tempToken->token = $newOtp;
            $tempToken->expired_at = $expiredAt;
            $tempToken->save();
        }

        // 2. Kirim email OTP -> App\Mail\OtpMail
        try {
            Mail::to($email)->send(new OtpMail($tempToken->token));
        } catch (\Exception $e) {
            // Log error jika pengiriman email gagal
            logger()->error('Failed to send OTP email to ' . $email . ': ' . $e->getMessage());
        }

        return $tempToken;
    }

    /**
     * Memverifikasi OTP yang dimasukkan oleh user yang sedang login.
     *
     * @param string $token (OTP yang dimasukkan user)
     * @return bool
     */
    public function verify(string $token): bool
    {
        // Pastikan ada user yang login untuk memverifikasi.
        if (!Auth::check()) {
            return false;
        }

        $email = Auth::user()->email;
        
        // 1. Cari token berdasarkan email user yang login DAN token yang dimasukkan
        $otp = TempToken::where('email', $email)
            ->where('token', $token)
            ->first();

        // 2. Cek validitas
        if (!$otp) {
            // Token tidak ditemukan (kode salah)
            return false;
        }
        
        if (now()->greaterThan($otp->expired_at)) {
            // Token kedaluwarsa, hapus dari database untuk dibersihkan
            $otp->delete(); 
            return false;
        }

        // 3. Verifikasi sukses: Hapus token dari database dan kembalikan true
        $otp->delete();
        return true;
    }
}