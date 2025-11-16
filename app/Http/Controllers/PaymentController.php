<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Notification;

class PaymentController extends Controller
{
    public function __construct()
    {
        // Set konfigurasi Midtrans
        Config::$serverKey = env('MIDTRANS_SERVER_KEY');
        Config::$isProduction = env('MIDTRANS_IS_PRODUCTION', false);
        Config::$isSanitized = true;
        Config::$is3ds = true;
    }

    /**
     * Mendapatkan Snap Token untuk pembayaran.
     */
    public function getSnapToken(Request $request)
    {
        $booking = Booking::with('user')->findOrFail($request->booking_id);

        $payload = [
            'transaction_details' => [
                'order_id' => $booking->booking_code,
                'gross_amount' => $booking->total_price,
            ],
            'customer_details' => [
                'first_name' => $booking->user->name,
                'email' => $booking->user->email,
            ],
        ];

        try {
            $snapToken = Snap::getSnapToken($payload);
            return response()->json(['snap_token' => $snapToken]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle notification dari Midtrans.
     */
    public function handleNotification(Request $request)
    {
        $notif = new \Midtrans\Notification();
        
        $transaction = $notif->transaction_status;
        $order_id = $notif->order_id;
        $fraud = $notif->fraud_status;

        $payment = Payment::where('booking_code', $order_id)->first();

        if ($payment) {
            if ($transaction == 'capture') {
                if ($fraud == 'challenge') {
                    $payment->status = 'challenge';
                } else if ($fraud == 'accept') {
                    $payment->status = 'paid';
                }
            } else if ($transaction == 'settlement') {
                $payment->status = 'paid';
            } else if ($transaction == 'pending') {
                $payment->status = 'pending';
            } else if ($transaction == 'deny') {
                $payment->status = 'cancelled';
            } else if ($transaction == 'expire') {
                $payment->status = 'cancelled';
            } else if ($transaction == 'cancel') {
                $payment->status = 'cancelled';
            }
            $payment->save();
        }
    }
}