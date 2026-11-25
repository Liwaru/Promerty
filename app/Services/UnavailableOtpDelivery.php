<?php

namespace App\Services;

use App\Contracts\OtpDelivery;

class UnavailableOtpDelivery implements OtpDelivery
{
    public function available(): bool { return false; }
    public function send(string $phone, string $purpose, string $code): void
    {
        abort(503, 'Layanan SMS OTP belum dikonfigurasi.');
    }
}
