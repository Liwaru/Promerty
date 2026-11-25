<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class VerifyAccountEmail extends Notification
{
    public function __construct(private readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verifikasi email Promerty')
            ->greeting('Halo,')
            ->line('Masuk ke akun Promerty Anda, lalu konfirmasi alamat email melalui tautan ini.')
            ->action('Verifikasi email', $this->url)
            ->line('Tautan berlaku selama 60 menit. Abaikan pesan ini jika Anda tidak memintanya.');
    }
}
