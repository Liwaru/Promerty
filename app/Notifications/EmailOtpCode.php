<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailOtpCode extends Notification
{
    public function __construct(#[\SensitiveParameter] private readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Promerty — Kode Verifikasi Email')
            ->greeting('Verifikasi akun Promerty')
            ->line('Kode verifikasi Anda: '.$this->code)
            ->line('Kode berlaku 10 menit. Jangan bagikan kode ini kepada siapa pun.')
            ->line('Abaikan email ini jika Anda tidak meminta verifikasi.');
    }
}
