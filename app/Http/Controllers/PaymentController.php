<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Notification;
use App\Http\Resources\ApiResponseResources;
use App\Notifications\PaymentNotification;

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
     * @param int $paymentId
     */
    public function payBooking($paymentId)
    {
        $payment = Payment::with('booking.user')->findOrFail($paymentId);

        $payload = [
            'transaction_details' => [
                'order_id' => $payment->booking->booking_code,
                'gross_amount' => $payment->amount,
            ],
            'customer_details' => [
                'first_name' => $payment->booking->user->name,
                'email' => $payment->booking->user->email,
                'phone' => $payment->booking->user->phone_number,
            ],
            'item_details' => [
                [
                    'id' => $payment->booking->bookable_id,
                    'price' => $payment->booking->final_price,
                    'quantity' => $payment->booking->quantity,
                    'name' => $payment->booking->bookable->name,
                ]
            ]
        ];

        try {
            $snapToken = Snap::getSnapToken($payload);
            $paymentUrl = "https://app.sandbox.midtrans.com/snap/v2/vtweb/" . $snapToken;

            // Update status payment ke 'pending'
            $payment->update(['status' => 'pending']);

            return new ApiResponseResources(true, 'Payment successfully created.', [
                'snap_token' => $snapToken,
                'payment_url' => $paymentUrl,
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
        $notif = new Notification($request);
        $transaction = $notif->transaction_status;
        $orderId = $notif->order_id;

        // --- TAMBAHKAN WITH UNTUK MENGAMBIL DATA USER ---
        $payment = Payment::whereHas('booking', function ($query) use ($orderId) {
            $query->where('booking_code', $orderId);
        })->with('booking.user')->first(); // <-- Penting: Load data user

        if ($payment) {
            $newStatus = 'pending';
            if ($transaction == 'capture' || $transaction == 'settlement') {
                $newStatus = 'paid';
            } elseif (in_array($transaction, ['deny', 'expire', 'cancel'])) {
                $newStatus = 'cancelled';
            }

            // --- KIRIM NOTIFIKASI HANYA JIKA STATUS BERUBAH ---
            if ($payment->status !== $newStatus) {
                $payment->update([
                    'status' => $newStatus,
                    'confirmed_at' => $newStatus === 'paid' ? now() : null,
                    'confirmed_by' => $newStatus === 'paid' ? 1 : null, // 1 untuk system user
                ]);

                // --- INTEGRASI NOTIFIKASI ---
                // Kirim notifikasi ke user yang melakukan pembayaran
                $payment->booking->user->notify(new PaymentNotification($payment));
            }
        }

        return response()->json(['status' => 'ok']); // Perbaiki typo 'status' -> 'ok'
    }
}