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
use App\Models\User;
use App\Notifications\PaymentConfirmationNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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
     * Get payment details for a booking (Customer)
     */
    public function getBookingPayment($bookingId)
    {
        try {
            $user = Auth::user();
            
            $booking = Booking::with(['transactions', 'bookable'])
                ->where('id', $bookingId)
                ->where('user_id', $user->id)
                ->first();

            if (!$booking) {
                return new ApiResponseResources(false, 'Booking not found', null, 404);
            }

            $latestTransaction = $booking->transactions()
                ->orderBy('created_at', 'desc')
                ->first();

            return new ApiResponseResources(true, 'Payment details retrieved', [
                'booking' => $booking,
                'payment_transaction' => $latestTransaction,
                'payment_status' => $latestTransaction->status ?? 'pending'
            ]);

        } catch (\Exception $e) {
            Log::error('Get booking payment failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve payment details', null, 500);
        }
    }

    /**
     * Retry payment for a transaction (Customer)
     */
    public function retryPayment($transactionId)
    {
        try {
            $user = Auth::user();
            
            $transaction = PaymentTransaction::with(['booking'])
                ->where('id', $transactionId)
                ->whereHas('booking', function($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->first();

            if (!$transaction) {
                return new ApiResponseResources(false, 'Transaction not found', null, 404);
            }

            // Hanya status yang bisa di-retry
            $retryableStatuses = ['pending', 'failed', 'canceled'];
            if (!in_array($transaction->status, $retryableStatuses)) {
                return new ApiResponseResources(
                    false, 
                    'Cannot retry payment for current status: ' . $transaction->status, 
                    null, 
                    422
                );
            }

            // Call the existing payBooking method
            return $this->payBooking($transactionId);

        } catch (\Exception $e) {
            Log::error('Retry payment failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retry payment', null, 500);
        }
    }

    /**
     * Get user's payment history (Customer)
     */
    public function paymentHistory(Request $request)
    {
        try {
            $user = Auth::user();
            $perPage = $request->get('per_page', 10);
            
            $transactions = PaymentTransaction::with(['booking.bookable'])
                ->whereHas('booking', function($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return new ApiResponseResources(true, 'Payment history retrieved', $transactions);

        } catch (\Exception $e) {
            Log::error('Payment history retrieval failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve payment history', null, 500);
        }
    }


    /**
     * Membuat pembayaran Midtrans untuk sebuah booking.
     * @param int $transactionId
     */
    public function payBooking($transactionId)
    {
        try {
            $transaction = PaymentTransaction::with('booking.user')->findOrFail($transactionId); 
            $booking = $transaction->booking;

            //Validasi status booking
            if (!in_array($booking->status, ['pending', 'confirmed'])) {
                return new ApiResponseResources(
                    false, 
                    'Cannot process payment for booking with status: ' . $booking->status, 
                    null, 
                    422
                );
            }

            // Gunakan ID unik transaksi baru sebagai order_id Midtrans
            $midtransOrderId = 'TRX-' . $transaction->id . '-' . $booking->booking_code . '-' . time();

            $payload = [
                'transaction_details' => [
                    'order_id' => $midtransOrderId,
                    'gross_amount' => $transaction->amount,
                ],
                'customer_details' => [
                    'first_name' => $booking->user->name,
                    'email' => $booking->user->email,
                    'phone' => $booking->user->phone_number ?? '',
                ],
                'item_details' => [
                    [
                        'id' => $booking->bookable_id,
                        'price' => $booking->final_price,
                        'quantity' => $booking->quantity,
                        'name' => $booking->bookable->name,
                    ]
                ],
                'callbacks' => [
                    'finish' => env('APP_URL') . '/payment/success',
                    'error' => env('APP_URL') . '/payment/error',
                    'pending' => env('APP_URL') . '/payment/pending'
                ]
            ];

            $snapToken = Snap::getSnapToken($payload);
            $paymentUrl = "https://app.sandbox.midtrans.com/snap/v2/vtweb/" . $snapToken;

            // Update status transaksi
            $transaction->update([
                'status' => 'pending', 
                'gateway_reference' => $midtransOrderId,
                'raw_response' => json_encode($payload)
            ]);

            return new ApiResponseResources(true, 'Payment successfully created.', [
                'snap_token' => $snapToken,
                'payment_url' => $paymentUrl,
                'transaction_id' => $transaction->id,
                'order_id' => $midtransOrderId
            ], 200);

        } catch (\Exception $e) {
            Log::error('Pay booking failed - Transaction ID: ' . $transactionId . ' - Error: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to create payment: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Handle notification dari Midtrans.
     */
    /**
     * Handle notification dari Midtrans.
     */
    public function handleNotification(Request $request)
    {
        try {
            $notif = new Notification();
        } catch (\Exception $e) {
            Log::error('Midtrans Notification Error: ' . $e->getMessage());
            return response()->json(['message' => 'Invalid notification data'], 400);
        }
        
        $transactionStatus = $notif->transaction_status;
        $paymentType = $notif->payment_type;
        $midtransOrderId = $notif->order_id;
        $fraudStatus = $notif->fraud_status;

        Log::info('Midtrans Notification Received', [
            'order_id' => $midtransOrderId,
            'status' => $transactionStatus,
            'payment_type' => $paymentType,
            'fraud_status' => $fraudStatus
        ]);

        //Gunakan transaction untuk consistency
        return DB::transaction(function () use ($notif, $transactionStatus, $paymentType, $midtransOrderId, $fraudStatus) {
            
            $paymentTransaction = PaymentTransaction::where('gateway_reference', $midtransOrderId)
                                                    ->with('booking.user')
                                                    ->first();

            if (!$paymentTransaction) {
                Log::error('Payment Transaction not found for Midtrans order_id: ' . $midtransOrderId);
                return response()->json(['message' => 'Transaction not found'], 404); 
            }

            $booking = $paymentTransaction->booking;
            $oldPaymentStatus = $paymentTransaction->status;
            $oldBookingStatus = $booking->status;

            // Mapping status yang tepat
            $statusMap = [
                'capture' => $fraudStatus == 'accept' ? 'success' : 'pending',
                'settlement' => 'success',
                'pending' => 'pending',
                'deny' => 'failed',
                'expire' => 'canceled',  // Midtrans expire -> canceled (bukan expired)
                'cancel' => 'canceled'   // Midtrans cancel -> canceled
            ];

            $newPaymentStatus = $statusMap[$transactionStatus] ?? $oldPaymentStatus;
            
            // Tentukan status booking berdasarkan status payment
            $newBookingStatus = $oldBookingStatus;
            if ($newPaymentStatus === 'success') {
                $newBookingStatus = 'confirmed';
            } elseif (in_array($newPaymentStatus, ['failed', 'canceled'])) {
                $newBookingStatus = 'cancelled';
            }

            // Get system user dynamically
            $systemUser = User::where('role', 'adminWeb')->first();
            $systemUserId = $systemUser ? $systemUser->id : 1;

            // Update payment transaction
            if ($oldPaymentStatus !== $newPaymentStatus) {
                $updateData = [
                    'status' => $newPaymentStatus,
                    'method' => $paymentType,
                    'raw_response' => json_encode($notif->getResponse()),
                ];

                // Hanya set confirmed_at & confirmed_by untuk payment success
                if ($newPaymentStatus === 'success') {
                    $updateData['confirmed_at'] = now();
                    $updateData['confirmed_by'] = $systemUserId;
                }

                $paymentTransaction->update($updateData);

                Log::info('Payment status updated', [
                    'transaction_id' => $paymentTransaction->id,
                    'old_status' => $oldPaymentStatus,
                    'new_status' => $newPaymentStatus
                ]);
            }

            // Update booking status
            if ($oldBookingStatus !== $newBookingStatus) {
                $booking->update(['status' => $newBookingStatus]);
                
                BookingLog::create([
                    'booking_id' => $booking->id,
                    'user_id' => $systemUserId,
                    'old_status' => $oldBookingStatus,
                    'new_status' => $newBookingStatus,
                    'notes' => 'Status updated via Midtrans notification: ' . $transactionStatus,
                ]);

                Log::info('Booking status updated', [
                    'booking_id' => $booking->id,
                    'old_status' => $oldBookingStatus,
                    'new_status' => $newBookingStatus
                ]);

                // Send notifications
                if ($newBookingStatus === 'confirmed') {
                    $booking->user->notify(new PaymentConfirmationNotification($booking));
                    
                    // TODO: Send notification to admin/owner about new confirmed booking
                }
            }

            return response()->json(['status' => 'ok'], 200);
        });
    }

    /**
     * Helper method untuk mendapatkan available payment statuses
     */
    public function getPaymentStatuses()
    {
        return [
            'success' => 'Payment successful',
            'pending' => 'Payment pending', 
            'failed' => 'Payment failed',
            'refunded' => 'Payment refunded',
            'canceled' => 'Payment canceled'
        ];
    }

    /**
     * ADMIN: Get all payments with filters
     */
    public function adminIndex(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $status = $request->get('status');
            $search = $request->get('search');
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');

            $query = PaymentTransaction::with(['booking.user', 'booking.bookable', 'confirmedBy'])
                ->orderBy('created_at', 'desc');

            // Filters
            if ($status) {
                $query->where('status', $status);
            }

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('gateway_reference', 'like', "%{$search}%")
                    ->orWhereHas('booking', function($q) use ($search) {
                        $q->where('booking_code', 'like', "%{$search}%")
                            ->orWhereHas('user', function($q) use ($search) {
                                $q->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                            });
                    });
                });
            }

            if ($dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            $payments = $query->paginate($perPage);

            $stats = [
                'total_payments' => PaymentTransaction::count(),
                'total_revenue' => PaymentTransaction::where('status', 'success')->sum('amount'),
                'pending_payments' => PaymentTransaction::where('status', 'pending')->count(),
                'successful_payments' => PaymentTransaction::where('status', 'success')->count(),
                'failed_payments' => PaymentTransaction::where('status', 'failed')->count(),
            ];

            return new ApiResponseResources(true, 'Admin payments retrieved successfully', [
                'payments' => $payments,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Admin payments retrieval failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve payments', null, 500);
        }
    }

    /**
     * ADMIN: Get specific payment details
     */
    public function adminShow($id)
    {
        try {
            $payment = PaymentTransaction::with([
                    'booking.user', 
                    'booking.bookable', 
                    'confirmedBy',
                    'booking.transactions'
                ])
                ->find($id);

            if (!$payment) {
                return new ApiResponseResources(false, 'Payment not found', null, 404);
            }

            return new ApiResponseResources(true, 'Payment details retrieved successfully', $payment);

        } catch (\Exception $e) {
            Log::error('Admin payment details failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve payment details', null, 500);
        }
    }

    /**
     * ADMIN: Update payment status (manual confirmation, etc.)
     */
    public function adminUpdateStatus(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:success,pending,failed,refunded,canceled',
                'notes' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return new ApiResponseResources(false, $validator->errors(), null, 422);
            }

            $payment = PaymentTransaction::with(['booking'])->find($id);
            if (!$payment) {
                return new ApiResponseResources(false, 'Payment not found', null, 404);
            }

            $oldStatus = $payment->status;
            $newStatus = $request->status;

            DB::transaction(function () use ($payment, $oldStatus, $newStatus, $request) {
                $payment->update([
                    'status' => $newStatus,
                    'confirmed_at' => $newStatus === 'success' ? now() : null,
                    'confirmed_by' => $newStatus === 'success' ? Auth::id() : null,
                ]);

                // Update booking status if payment status changed to success
                if ($newStatus === 'success' && $oldStatus !== 'success') {
                    $payment->booking->update(['status' => 'confirmed']);
                    
                    BookingLog::create([
                        'booking_id' => $payment->booking->id,
                        'user_id' => Auth::id(),
                        'old_status' => $payment->booking->status,
                        'new_status' => 'confirmed',
                        'notes' => 'Payment manually confirmed by admin: ' . ($request->notes ?? ''),
                    ]);
                }

                // Log the payment status change
                Log::info('Payment status manually updated by admin', [
                    'payment_id' => $payment->id,
                    'admin_id' => Auth::id(),
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'notes' => $request->notes
                ]);
            });

            return new ApiResponseResources(true, 'Payment status updated successfully', $payment);

        } catch (\Exception $e) {
            Log::error('Admin payment status update failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to update payment status', null, 500);
        }
    }

    /**
     * ADMIN: Process refund
     */
    public function processRefund($id)
    {
        try {
            $payment = PaymentTransaction::with(['booking'])->find($id);
            
            if (!$payment) {
                return new ApiResponseResources(false, 'Payment not found', null, 404);
            }

            // Only allow refund for successful payments
            if ($payment->status !== 'success') {
                return new ApiResponseResources(false, 'Only successful payments can be refunded', null, 422);
            }

            // TODO: Integrate with Midtrans refund API
            // For now, just update status manually
            $payment->update([
                'status' => 'refunded',
                'confirmed_at' => now(),
                'confirmed_by' => Auth::id(),
            ]);

            // Update booking status to cancelled
            $payment->booking->update(['status' => 'cancelled']);

            BookingLog::create([
                'booking_id' => $payment->booking->id,
                'user_id' => Auth::id(),
                'old_status' => 'confirmed',
                'new_status' => 'cancelled',
                'notes' => 'Payment refunded by admin',
            ]);

            return new ApiResponseResources(true, 'Refund processed successfully', $payment);

        } catch (\Exception $e) {
            Log::error('Refund processing failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to process refund', null, 500);
        }
    }
}