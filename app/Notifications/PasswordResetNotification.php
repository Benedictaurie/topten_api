<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public $token)
    {
        // $this->token = $token;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Reset Your Account Password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', url('/api/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->getEmailForPasswordReset())))
            ->line('This link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}