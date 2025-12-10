<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use App\Http\Resources\ApiResponseResources;
use App\Models\User;
use App\Notifications\PaymentConfirmationNotification;
use App\Services\Notification\FirebaseNotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
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

            $transactions = $booking->transactions()->orderBy('created_at', 'desc')->get();
            
            // Hitung total pembayaran yang berhasil
            $totalPaid = $transactions->where('status', 'success')->sum('amount');
            $remaining = max(0, $booking->final_price - $totalPaid);
            $isFullyPaid = $remaining <= 0;

            return new ApiResponseResources(true, 'Payment details retrieved', [
                'booking' => $booking,
                'transactions' => $transactions,
                'payment_summary' => [
                    'total_amount' => $booking->final_price,
                    'total_paid' => $totalPaid,
                    'remaining' => $remaining,
                    'is_fully_paid' => $isFullyPaid,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get booking payment failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve payment details', null, 500);
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
            
            $transactions = PaymentTransaction::with(['booking.bookable', 'booking'])
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
     * Customer: Upload proof of payment
     */
    public function uploadProof(Request $request, $bookingId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'proof_file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
                'payment_method' => 'required|in:cash,transfer,other',
                'amount' => 'required|numeric|min:1',
                'notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return new ApiResponseResources(false, $validator->errors(), null, 422);
            }

            $user = Auth::user();
            $booking = Booking::where('id', $bookingId)
                ->where('user_id', $user->id)
                ->first();

            if (!$booking) {
                return new ApiResponseResources(false, 'Booking not found', null, 404);
            }

            // Validasi amount tidak melebihi sisa pembayaran
            $totalPaid = $booking->transactions()->where('status', 'success')->sum('amount');
            $remaining = max(0, $booking->final_price - $totalPaid);
            
            if ($request->amount > $remaining) {
                return new ApiResponseResources(
                    false, 
                    'Payment amount exceeds remaining balance. Remaining: ' . number_format($remaining), 
                    null, 
                    422
                );
            }

            // Upload file
            $file = $request->file('proof_file');
            $path = $file->store('payment-proofs', 'public');

            return DB::transaction(function () use ($booking, $request, $path, $user) {
                // Buat payment transaction
                $transaction = PaymentTransaction::create([
                    'booking_id' => $booking->id,
                    'type' => 'Payment',
                    'amount' => $request->amount,
                    'method' => $request->payment_method,
                    'status' => 'pending', // Menunggu konfirmasi admin
                    'proof_of_payment' => $path,
                    'transacted_at' => now(),
                ]);

                // Notify admin
                $admins = User::whereIn('role', ['admin'])->get();
                foreach ($admins as $admin) {
                    if ($admin->fcm_token) {
                        $firebaseService = app(FirebaseNotificationService::class);
                        $firebaseService->sendToDevice(
                            $admin->fcm_token,
                            'New Payment Proof!',
                            'Customer uploaded payment proof for booking #' . $booking->booking_code,
                            [
                                'booking_id' => $booking->id,
                                'transaction_id' => $transaction->id,
                                'type' => 'payment_proof_uploaded'
                            ]
                        );
                    }
                }

                return new ApiResponseResources(true, 'Proof of payment uploaded successfully. Waiting for admin confirmation.', [
                    'transaction' => $transaction,
                    'proof_url' => Storage::url($path),
                    'booking' => $booking->fresh(),
                ]);

            });

        } catch (\Exception $e) {
            Log::error('Proof upload failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to upload proof', null, 500);
        }
    }

    /**
     * ADMIN: Get bookings pending confirmation
     * GET /management/bookings/pending-confirmations
     */
    public function getPendingConfirmations(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            
            $bookings = Booking::with(['user', 'bookable', 'transactions'])
                ->whereIn('status', ['pending', 'confirmed'])
                ->whereHas('transactions', function($query) {
                    $query->where('status', 'pending');
                })
                ->orWhere('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            $stats = [
                'total_pending_bookings' => Booking::where('status', 'pending')->count(),
                'pending_payments' => PaymentTransaction::where('status', 'pending')->count(),
            ];

            return new ApiResponseResources(true, 'Pending confirmations retrieved', [
                'bookings' => $bookings,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Pending confirmations retrieval failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve pending confirmations', null, 500);
        }
    }

    /**
     * ADMIN: Confirm booking with payment
     * POST /management/bookings/{bookingId}/confirm-payment
     */
    public function confirmBookingWithPayment(Request $request, $bookingId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:1',
                'payment_method' => 'required|in:cash,transfer,other',
                'status' => 'required|in:success,pending,failed',
                'notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return new ApiResponseResources(false, $validator->errors(), null, 422);
            }

            $booking = Booking::with('user')->find($bookingId);
            
            if (!$booking) {
                return new ApiResponseResources(false, 'Booking not found', null, 404);
            }

            return DB::transaction(function () use ($booking, $request) {
                $admin = Auth::user();
                
                // Buat payment transaction
                $transaction = PaymentTransaction::create([
                    'booking_id' => $booking->id,
                    'type' => 'Payment',
                    'amount' => $request->amount,
                    'method' => $request->payment_method,
                    'status' => $request->status,
                    'confirmed_at' => $request->status === 'success' ? now() : null,
                    'confirmed_by' => $request->status === 'success' ? $admin->id : null,
                    'transacted_at' => now(),
                    'notes' => $request->notes,
                ]);

                return new ApiResponseResources(true, 'Payment confirmed successfully', [
                    'transaction' => $transaction,
                    'booking' => $booking,
                ]);

            });

        } catch (\Exception $e) {
            Log::error('Booking confirmation failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to confirm booking', null, 500);
        }
    }

    /**
     * ADMIN: Create manual payment for booking
     * POST /management/bookings/{bookingId}/manual-payment
     */
    public function createManualPayment(Request $request, $bookingId)
    {
        // Sama dengan adminCreatePayment, bisa gunakan method yang sama
        return $this->adminCreatePayment($request, $bookingId);
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
            $paymentMethod = $request->get('payment_method');

            $query = PaymentTransaction::with(['booking.user', 'booking.bookable', 'confirmedBy'])
                ->orderBy('created_at', 'desc');

            // Filters
            if ($status) {
                $query->where('status', $status);
            }

            if ($paymentMethod) {
                $query->where('method', $paymentMethod);
            }

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('id', 'like', "%{$search}%")
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

            // Stats
            $stats = [
                'total_payments' => PaymentTransaction::count(),
                'total_revenue' => PaymentTransaction::where('status', 'success')->sum('amount'),
                'pending_payments' => PaymentTransaction::where('status', 'pending')->count(),
                'successful_payments' => PaymentTransaction::where('status', 'success')->count(),
                'failed_payments' => PaymentTransaction::where('status', 'failed')->count(),
                'refunded_payments' => PaymentTransaction::where('status', 'refunded')->count(),
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

            // Get booking payment summary
            $booking = $payment->booking;
            $totalPaid = $booking->transactions()->where('status', 'success')->sum('amount');
            $remaining = max(0, $booking->final_price - $totalPaid);

            return new ApiResponseResources(true, 'Payment details retrieved successfully', [
                'payment' => $payment,
                'booking_summary' => [
                    'total_amount' => $booking->final_price,
                    'total_paid' => $totalPaid,
                    'remaining' => $remaining,
                    'is_fully_paid' => $remaining <= 0,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Admin payment details failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve payment details', null, 500);
        }
    }

    /**
     * ADMIN: Create manual payment for booking
     */
    public function adminCreatePayment(Request $request, $bookingId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:1',
                'payment_method' => 'required|in:cash,transfer,other',
                'status' => 'required|in:success,pending,failed',
                'payment_date' => 'required|date',
                'notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return new ApiResponseResources(false, $validator->errors(), null, 422);
            }

            $booking = Booking::with('user')->find($bookingId);
            
            if (!$booking) {
                return new ApiResponseResources(false, 'Booking not found', null, 404);
            }

            // Validasi amount tidak melebihi sisa pembayaran
            $totalPaid = $booking->transactions()->where('status', 'success')->sum('amount');
            $remaining = max(0, $booking->final_price - $totalPaid);
            
            if ($request->amount > $remaining) {
                return new ApiResponseResources(
                    false, 
                    'Payment amount exceeds remaining balance. Remaining: ' . number_format($remaining), 
                    null, 
                    422
                );
            }

            return DB::transaction(function () use ($booking, $request) {
                $admin = Auth::user();
                $oldBookingStatus = $booking->status;
                
                // Buat payment transaction
                $transaction = PaymentTransaction::create([
                    'booking_id' => $booking->id,
                    'type' => 'Payment',
                    'amount' => $request->amount,
                    'method' => $request->payment_method,
                    'status' => $request->status,
                    'confirmed_at' => $request->status === 'success' ? now() : null,
                    'confirmed_by' => $request->status === 'success' ? $admin->id : null,
                    'transacted_at' => $request->payment_date,
                    'notes' => $request->notes,
                ]);

                // Update booking status jika payment success
                if ($request->status === 'success') {
                    // Notify user
                    $booking->user->notify(new PaymentConfirmationNotification($booking, $transaction));
                }

                return new ApiResponseResources(true, 'Payment recorded successfully', [
                    'transaction' => $transaction,
                    'booking' => $booking->fresh(),
                ]);

            });

        } catch (\Exception $e) {
            Log::error('Admin payment creation failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to record payment', null, 500);
        }
    }

    /**
     * ADMIN: Update payment status (confirm/reject payment proof)
     */
    public function adminUpdatePayment(Request $request, $transactionId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:success,failed',
                'notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return new ApiResponseResources(false, $validator->errors(), null, 422);
            }

            $transaction = PaymentTransaction::with(['booking.user'])->find($transactionId);
            
            if (!$transaction) {
                return new ApiResponseResources(false, 'Transaction not found', null, 404);
            }

            // Hanya bisa update yang statusnya pending
            if ($transaction->status !== 'pending') {
                return new ApiResponseResources(false, 'Only pending payments can be updated', null, 422);
            }

            return DB::transaction(function () use ($transaction, $request) {
                $admin = Auth::user();
                $booking = $transaction->booking;
                $oldBookingStatus = $booking->status;
                $oldPaymentStatus = $transaction->status;
                
                // Update transaction
                $transaction->update([
                    'status' => $request->status,
                    'confirmed_at' => $request->status === 'success' ? now() : null,
                    'confirmed_by' => $request->status === 'success' ? $admin->id : null,
                ]);
                
                return new ApiResponseResources(true, 'Payment status updated successfully', [
                    'transaction' => $transaction,
                    'booking' => $booking,
                ]);
            });

        } catch (\Exception $e) {
            Log::error('Admin payment update failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to update payment status', null, 500);
        }
    }

    /**
     * ADMIN: Process refund
     */
    public function processRefund(Request $request, $transactionId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'refund_amount' => 'required|numeric|min:1',
                'reason' => 'required|string|max:500',
            ]);

            if ($validator->fails()) {
                return new ApiResponseResources(false, $validator->errors(), null, 422);
            }

            $transaction = PaymentTransaction::with(['booking'])->find($transactionId);
            
            if (!$transaction) {
                return new ApiResponseResources(false, 'Transaction not found', null, 404);
            }

            // Only allow refund for successful payments
            if ($transaction->status !== 'success') {
                return new ApiResponseResources(false, 'Only successful payments can be refunded', null, 422);
            }

            // Validasi refund amount tidak melebihi amount transaksi
            if ($request->refund_amount > $transaction->amount) {
                return new ApiResponseResources(false, 'Refund amount cannot exceed original payment amount', null, 422);
            }

            return DB::transaction(function () use ($transaction, $request) {
                $admin = Auth::user();
                $booking = $transaction->booking;
                
                // Buat transaksi refund
                $refundTransaction = PaymentTransaction::create([
                    'booking_id' => $booking->id,
                    'type' => 'Refund',
                    'amount' => $request->refund_amount,
                    'method' => $transaction->method,
                    'status' => 'refunded',
                    'confirmed_at' => now(),
                    'confirmed_by' => $admin->id,
                    'transacted_at' => now(),
                    'notes' => 'Refund: ' . $request->reason,
                ]);

                return new ApiResponseResources(true, 'Refund processed successfully', [
                    'refund_transaction' => $refundTransaction,
                    'booking' => $booking,
                ]);

            });

        } catch (\Exception $e) {
            Log::error('Refund processing failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to process refund', null, 500);
        }
    }

    /**
     * Get payment statistics
     */
    public function getPaymentStats()
    {
        try {
            $today = now()->format('Y-m-d');
            $firstDayOfMonth = now()->firstOfMonth()->format('Y-m-d');
            $lastDayOfMonth = now()->lastOfMonth()->format('Y-m-d');

            $stats = [
                'today' => [
                    'count' => PaymentTransaction::whereDate('created_at', $today)
                        ->where('status', 'success')
                        ->count(),
                    'revenue' => PaymentTransaction::whereDate('created_at', $today)
                        ->where('status', 'success')
                        ->sum('amount'),
                ],
                'this_month' => [
                    'count' => PaymentTransaction::whereBetween('created_at', [$firstDayOfMonth, $lastDayOfMonth])
                        ->where('status', 'success')
                        ->count(),
                    'revenue' => PaymentTransaction::whereBetween('created_at', [$firstDayOfMonth, $lastDayOfMonth])
                        ->where('status', 'success')
                        ->sum('amount'),
                ],
                'all_time' => [
                    'count' => PaymentTransaction::where('status', 'success')->count(),
                    'revenue' => PaymentTransaction::where('status', 'success')->sum('amount'),
                ],
                'pending_count' => PaymentTransaction::where('status', 'pending')->count(),
                'payment_methods' => PaymentTransaction::selectRaw('method, COUNT(*) as count, SUM(amount) as total')
                    ->where('status', 'success')
                    ->groupBy('method')
                    ->get(),
            ];

            return new ApiResponseResources(true, 'Payment statistics retrieved', $stats);

        } catch (\Exception $e) {
            Log::error('Payment stats retrieval failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Failed to retrieve payment statistics', null, 500);
        }
    }
}