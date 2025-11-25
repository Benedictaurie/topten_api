<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\PaymentTransaction;
use App\Models\BookingLog;
use Illuminate\Http\Request;
use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Notification;
use App\Http\Resources\ApiResponseResources;
use App\Notifications\PaymentConfirmationNotification;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct()
    {
        // Set konfigurasi Midtrans
        Config::$serverKey = env('MIDTRANS_SERVER_KEY');
        Config::$isProduction = (bool) env('MIDTRANS_IS_PRODUCTION');
        Config::$isSanitized = true;
        Config::$is3ds = true;
    }

    /**
     * Membuat pembayaran Midtrans untuk sebuah booking.
     * @param int $transactionId
     */
    public function payBooking($transactionId)
    {
        $transaction = PaymentTransaction::with('booking.user')->findOrFail($transactionId); 
        $booking = $transaction->booking;

        // Gunakan ID unik transaksi baru sebagai order_id Midtrans
        $midtransOrderId = 'TRX-' . $transaction->id . '-' . $booking->booking_code;

        $payload = [
            'transaction_details' => [
                'order_id' => $midtransOrderId, // ID yang disepakati untuk Midtrans
                'gross_amount' => $transaction->amount, // Ambil dari transaksi
            ],
            'customer_details' => [
                'first_name' => $booking->user->name,
                'email' => $booking->user->email,
                'phone' => $booking->user->phone_number,
            ],
            'item_details' => [
                [
                    'id' => $booking->bookable_id,
                    'price' => $booking->final_price,
                    'quantity' => $booking->quantity,
                    'name' => $booking->bookable->name,
                ]
            ]
        ];

        try {
            $snapToken = Snap::getSnapToken($payload);
            $paymentUrl = "https://app.sandbox.midtrans.com/snap/v2/vtweb/" . $snapToken;

            // Update status transaksi: Set gateway reference dari order_id Midtrans
            $transaction->update([
                'status' => 'pending', 
                'gateway_reference' => $midtransOrderId
            ]);

            return new ApiResponseResources(true, 'Payment successfully created.', [
                'snap_token' => $snapToken,
                'payment_url' => $paymentUrl,
                'transaction_id' => $transaction->id,
            ], 200);

        } catch (\Exception $e) {
            return new ApiResponseResources(false, 'Failed to create Midtrans payment: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Handle notification dari Midtrans.
     */
    public function handleNotification(Request $request)
    {
        // 1. Verifikasi Signature Key (Wajib untuk Keamanan!)
        try {
            $notif = new Notification();
        } catch (\Exception $e) {
            // Log error dan kembalikan 400 jika notif tidak valid
            Log::error('Midtrans Notification Error: ' . $e->getMessage());
            return response()->json(['message' => 'Invalid notification data'], 400);
        }
        
        // Data utama dari notifikasi
        $transactionStatus = $notif->transaction_status;
        $paymentType = $notif->payment_type;
        $midtransOrderId = $notif->order_id; // Ini adalah 'TRX-ID-BOOKINGCODE'
        $fraudStatus = $notif->fraud_status;

        // 2. Cari Payment Transaction berdasarkan Order ID Midtrans
        $paymentTransaction = PaymentTransaction::where('gateway_reference', $midtransOrderId)
                                                ->with('booking.user')
                                                ->first();

        if (!$paymentTransaction) {
            return response()->json(['message' => 'Payment Transaction not found for order_id: ' . $midtransOrderId], 404); 
        }

        $booking = $paymentTransaction->booking;
        $oldPaymentStatus = $paymentTransaction->status;
        $oldBookingStatus = $booking->status;

        $newPaymentStatus = $oldPaymentStatus;
        $newBookingStatus = $oldBookingStatus;
        $systemUserId = 1; // Asumsi ID System/Admin

        // 3. Logika Pembaruan Status
        if ($transactionStatus == 'capture' || $transactionStatus == 'settlement') {
            if ($fraudStatus == 'accept' || $transactionStatus == 'settlement') {
                $newPaymentStatus = 'success'; // Ubah dari 'paid' menjadi 'success' sesuai enum PaymentTransaction
                $newBookingStatus = 'confirmed';
            }
        } elseif ($transactionStatus == 'pending') {
            $newPaymentStatus = 'pending';
        } elseif (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
            $newPaymentStatus = 'canceled';
            $newBookingStatus = 'cancelled';
        }

        // 4. Lakukan Update Status Payment Transaction
        if ($oldPaymentStatus !== $newPaymentStatus) {
            $paymentTransaction->update([
                'status' => $newPaymentStatus,
                'method' => $paymentType, // Update metode pembayaran spesifik dari notifikasi
                'confirmed_at' => ($newPaymentStatus === 'success' ? now() : null),
                'confirmed_by' => ($newPaymentStatus === 'success' ? $systemUserId : null), 
                'raw_response' => json_encode($notif->getResponse()), // Simpan raw response Midtrans
            ]);
        }

        // 5. Update Status Booking dan Catat Log
        if ($oldBookingStatus !== $newBookingStatus) {
            // Update status Booking di tabel utama
            $booking->update(['status' => $newBookingStatus]);
            
            // Catat perubahan status di BookingLog
            BookingLog::create([
                'booking_id' => $booking->id,
                'user_id' => $systemUserId, // Sistem yang memicu perubahan karena callback
                'old_status' => $oldBookingStatus,
                'new_status' => $newBookingStatus,
                'notes' => 'Status changed via Midtrans callback notification.',
            ]);
            
            if ($newBookingStatus === 'confirmed') {
                // Notification for User/Customer: Payment successful, Booking confirmed
                $booking->user->notify(new PaymentConfirmationNotification($booking)); 
            }
        }

        // 6. Kembalikan Respons 200 OK ke Midtrans
        return response()->json(['status' => 'ok'], 200);
    }
}