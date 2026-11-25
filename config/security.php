<?php

return [
    // Rollout is deliberately isolated until schema + existing-user rollout are approved.
    'qa_rollout' => (bool) env('PROMERTY_SECURITY_QA_ROLLOUT', false),
    // Owner decision: retain the foundation, but do not enroll or challenge users.
    // This is independent of session, audit and recovery rollout.
    'mfa_enabled' => false,
    'email_otp' => (bool) env('PROMERTY_EMAIL_OTP_ROLLOUT', false),
    'audit_retention_days' => 90,
];
