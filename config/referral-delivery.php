<?php

return [
    // Deliberate installation configuration; never infer a live transport from a demo/log mailer.
    'enabled' => (bool) env('REFERRAL_EMAIL_ENABLED', false),
    'mailer' => env('REFERRAL_EMAIL_MAILER', 'smtp'),
    'from_address' => env('REFERRAL_EMAIL_FROM_ADDRESS'),
    'from_name' => env('REFERRAL_EMAIL_FROM_NAME'),
    // Staff text messages also need SMS_PROVIDER=twilio and the TWILIO_* keys (config/services.php).
    'sms_enabled' => (bool) env('REFERRAL_SMS_ENABLED', false),
];
