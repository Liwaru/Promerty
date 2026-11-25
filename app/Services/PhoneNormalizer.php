<?php

namespace App\Services;

use InvalidArgumentException;

class PhoneNormalizer
{
    public function normalize(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', trim($phone));

        if (str_starts_with($phone, '08')) {
            $phone = '+62'.substr($phone, 1);
        } elseif (str_starts_with($phone, '628')) {
            $phone = '+'.$phone;
        } elseif (str_starts_with($phone, '8')) {
            $phone = '+62'.$phone;
        }

        if (!preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
            throw new InvalidArgumentException('Format nomor HP tidak valid.');
        }

        return $phone;
    }
}
