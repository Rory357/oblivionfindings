<?php

use SocialiteProviders\Manager\SocialiteWasCalled;

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'listeners' => [
        SocialiteWasCalled::class => [
            'SocialiteProviders\\Microsoft\\MicrosoftExtendSocialite@handle',
            'SocialiteProviders\\Google\\GoogleExtendSocialite@handle',
        ],
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        'tenant' => env('MICROSOFT_TENANT_ID'), // single org lock
    ],

    'telemetry' => [
        'ingest_token' => env('TELEMETRY_INGEST_TOKEN'),
    ],

    'queclink' => [
        // Default TCP listener port for device intake. Operators can override
        // at runtime via the AppSetting key `queclink.listener.port`; the
        // queclink:install command rewrites the systemd unit + UFW rule
        // when the setting changes.
        'port' => env('QUECLINK_LISTENER_PORT', 8090),
        // Public hostname devices dial into. Used by the device-provisioning
        // string generator on the integration hub page. Runtime override via
        // AppSetting key `queclink.public_hostname`.
        'public_hostname' => env('QUECLINK_PUBLIC_HOSTNAME'),
    ],

    'xero' => [
        'client_id' => env('XERO_CLIENT_ID'),
        'client_secret' => env('XERO_CLIENT_SECRET'),
    ],

    'ird' => [
        'api_key' => env('IRD_API_KEY'),
        'api_secret' => env('IRD_API_SECRET'),
        // Live IRD Gateway Services submission (SOAP + WS-Security X.509) is not
        // wired yet. Set IRD_SIMULATION_ENABLED=true to allow an explicit,
        // clearly-labelled SIMULATED submission for testing (never transmits).
        'simulation_enabled' => env('IRD_SIMULATION_ENABLED', false),
    ],

    'sms' => [
        'provider' => env('SMS_PROVIDER'),

        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],
    ],

    'push' => [
        'provider' => env('PUSH_PROVIDER'),

        'expo' => [
            'endpoint' => env('EXPO_PUSH_ENDPOINT', 'https://exp.host/--/api/v2/push/send'),
            'access_token' => env('EXPO_ACCESS_TOKEN'),
        ],
    ],

    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'hello@example.com')),
    ],

];
