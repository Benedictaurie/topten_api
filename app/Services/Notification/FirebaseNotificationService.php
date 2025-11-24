<?php

namespace App\Services\Notification;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\Notification;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\MessagingException;

//mengirim notifikasi push ke perangkat mobile (FCM).

class FirebaseNotificationService
{
    protected $messaging;

    public function __construct()
    {
        // Konfigurasi Firebase berdasarkan file config/firebase.php
        // yang sudah Anda publish sebelumnya
        $this->messaging = (new Factory)
            ->withServiceAccount(config('firebase.credentials'))
            ->createMessaging();
    }

    /**
     * Mengirim notifikasi ke perangkat spesifik berdasarkan token.
     *
     * @param string $token FCM Token dari perangkat mobile
     * @param string $title Judul notifikasi
     * @param string $body Isi notifikasi
     * @param array $data Data tambahan yang akan dikirim ke mobile app
     * @return void
     */
    public function sendToDevice($token, $title, $body, $data = [])
    {
        $notification = Notification::create($title, $body);

        try {
            $message = [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data,
            ];

            $this->messaging->send($message);
        } catch (MessagingException $e) { // Gunakan exception yang lebih spesifik
            // Log error jika token tidak valid atau ada masalah lain
            Log::error('Gagal mengirim notifikasi FCM: ' . $e->getMessage());
        } catch (\Exception $e) { // Tambahkan catch-all untuk error lainnya
            Log::error('Error tidak terduga saat mengirim FCM: ' . $e->getMessage());
        }
    }
}