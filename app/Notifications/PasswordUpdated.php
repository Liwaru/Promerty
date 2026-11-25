<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PasswordUpdated extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Promerty — Password diperbarui')->greeting('Halo,')
            ->line('Password akun Promerty Anda telah diperbarui.')
            ->line('Jika Anda tidak melakukan perubahan ini, segera amankan akun melalui halaman pemulihan Promerty.');
    }
}
