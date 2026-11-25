<?php

namespace App\Support;

final class PrivateMailDelivery
{
    public static function available(): bool
    {
        $mailer = config('mail.default');
        if (!is_string($mailer)) {
            return false;
        }

        $transport = config('mail.mailers.'.$mailer.'.transport');

        return in_array($transport, ['smtp', 'sendmail'], true)
            || ($transport === 'array' && app()->environment('testing'));
    }
}
