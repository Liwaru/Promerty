<?php

return [
    'google_client_id' => env('GOOGLE_CLIENT_ID'),

    'otp' => [
        'digits' => (int) env('OTP_DIGITS', 6),
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),
        'max_verify_attempts' => (int) env('OTP_MAX_VERIFY_ATTEMPTS', 5),
        'max_requests_per_minute' => (int) env('OTP_MAX_REQUESTS_PER_MINUTE', 1),
    ],
];
