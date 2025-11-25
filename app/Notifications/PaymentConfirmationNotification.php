<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\PaymentTransaction;
use App\Models\Booking;

class PaymentConfirmationNotification extends Notification
{
    use Queueable;

    // Ubah dari $payment menjadi $booking
    public $booking; 

    /**
     * Create a new notification instance.
     */
    public function __construct(Booking $booking) // <<< Ubah type-hinting menjadi Booking
    {
        $this->booking = $booking; // Simpan Booking Model
    }

    // ...
    public function toMail($notifiable)
    {
        $subject = '';
        $message = '';
        
        // Asumsi: Kita ambil transaksi yang paling baru (yang seharusnya sukses)
        $payment = $this->booking->transactions->where('status', 'success')->first() 
                 ?? $this->booking->transactions->last();
        
        // Cek jika payment tidak ditemukan (seharusnya tidak terjadi jika dipanggil setelah sukses)
        if (!$payment) {
            $payment = (object)['status' => 'unknown', 'amount' => $this->booking->final_price, 'method' => 'N/A'];
        }
        
        switch ($payment->status) { // <<< Menggunakan $payment->status
            case 'success': // <<< Sesuaikan dengan status 'success' di PaymentController
            case 'paid':
                $subject = 'Payment Successful: ' . $this->booking->booking_code;
                $message = 'The payment for your booking has been successfully confirmed. Thank you.';
                break;
            // ... (Kasus lainnya dihilangkan karena notifikasi ini idealnya hanya dipanggil saat sukses)
            default:
                $subject = 'Payment Update: ' . $this->booking->booking_code;
                $message = 'The status of your payment has been updated.';
                break;
        }

        return (new MailMessage)
                    ->subject($subject)
                    ->greeting('Hello, ' . $notifiable->name) 
                    ->line($message)
                    ->line('Booking Code: ' . $this->booking->booking_code)
                    ->line('Amount: IDR ' . number_format($payment->amount, 0, ',', '.')) // Menggunakan $payment->amount
                    ->line('Method: ' . $payment->method) // Menggunakan $payment->method
                    ->action('View Booking Details', url('/my-bookings/' . $this->booking->id)); // <<< Lebih baik langsung ke detail booking
    }
}