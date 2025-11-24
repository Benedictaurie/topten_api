<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Payment;

class PaymentNotification extends Notification
{
    use Queueable;

    public $payment;

    /**
     * Create a new notification instance.
     */
    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable)
    {
        $subject = '';
        $message = '';

        switch ($this->payment->status) {
            case 'paid':
                $subject = 'Payment Successful: ' . $this->payment->booking->booking_code;
                $message = 'The payment for your booking has been successfully confirmed. Thank you.';
                break;
            case 'cancelled':
            case 'failed':
                $subject = 'Payment Failed: ' . $this->payment->booking->booking_code;
                $message = 'The payment for your booking failed or was cancelled. Please try again.';
                break;
            case 'refunded':
                $subject = 'Payment Refunded: ' . $this->payment->booking->booking_code;
                $message = 'The payment for your booking has been refunded.';
                break;
            default:
                $subject = 'Payment Update: ' . $this->payment->booking->booking_code;
                $message = 'The status of your payment has been updated.';
                break;
        }

        return (new MailMessage)
                    ->subject($subject)
                    ->greeting('Hello, ' . $notifiable->name) 
                    ->line($message)
                    ->line('Booking Code: ' . $this->payment->booking->booking_code)
                    ->line('Amount: IDR ' . number_format($this->payment->amount, 0, ',', '.'))
                    ->line('Method: ' . $this->payment->method)
                    // Arahkan ke halaman riwayat booking user (Direct to user's booking history page)
                    ->action('View Booking Details', url('/my-bookings')); 
    }
}
