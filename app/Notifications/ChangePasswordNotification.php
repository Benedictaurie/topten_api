<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\User;

//untuk notifikasi KONFIRMASI setelah user berhasil mengganti passwordnya (misalnya dari halaman profil).

class ChangePasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $user;

    /**
     * Create a new notification instance.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
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
        return (new MailMessage)
            ->subject('Password Changed Successfully')
            ->greeting('Hello ' . $this->user->name . '!')
            ->line('Your password has been changed successfully.')
            ->line('If you did not make this change, please contact our support immediately.')
            ->line('Thank you for using our application!');
    }

    public function toArray($notifiable)
    {
        return [
            'message' => 'Password changed successfully'
        ];
    }
}
