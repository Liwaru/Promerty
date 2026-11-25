<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

final class NewPassword
{
    public const MIN_LENGTH = 10;
    public const MAX_BYTES = 72;

    public static function rules(bool $confirmed = true): array
    {
        return [
            'bail', 'required', 'string', ...($confirmed ? ['confirmed'] : []),
            static function (string $attribute, #[\SensitiveParameter] string $value, \Closure $fail): void {
                if (strlen($value) > self::MAX_BYTES) {
                    $fail('Password tidak boleh melebihi '.self::MAX_BYTES.' byte.');
                } elseif (!mb_check_encoding($value, 'UTF-8') || strpbrk($value, "\0\r\n") !== false) {
                    $fail('Format password tidak valid.');
                }
            },
            Password::min(self::MIN_LENGTH)->letters(),
        ];
    }
}
