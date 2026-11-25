<?php

namespace App\Contracts;

interface OtpDelivery
{
    public function available(): bool;
    public function send(string $phone, string $purpose, string $code): void;
}
