<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\DatabaseNotification;

class NewBookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $booking;

    public function __construct($booking)
    {
        $this->booking = $booking;
    }

    /**
     * Tentukan channel notifikasi (email, database, dll).
     *
     * @param  \App\Models\User  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail', 'database']; 
    }

    /**
     * Bentuk notifikasi untuk channel email.
     *
     * @param  \App\Models\User  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->subject('New Booking: ' . $this->booking->booking_code)
                    ->line('You received a new order.')
                    ->line('Package: ' . $this->booking->bookable->name)
                    ->line('Total: ' . number_format($this->booking->total_price, 0, ',', '.'))
                    ->action('View Details', url('/admin/bookings/' . $this->booking->id));
    }

    /**
     * Bentuk notifikasi untuk channel database (untuk in-app notification).
     *
     * @param  \App\Models\User  $notifiable
     * @return array
     */
    public function toDatabase($notifiable)
    {
        return [
            'booking_id' => $this->booking->id,
            'message' => 'There is a new order for ' . $this->booking->bookable->name,
        ];
    }
}