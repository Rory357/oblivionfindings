<?php

return [

    // Runtime persistence remains off until the organisation explicitly sets
    // content retention and bounded terminal-metadata retention. Tests supply
    // isolated fixture values; the incident module's policy is not inherited.
    'drafts' => [
        'enabled' => env('IT_DRAFTS_ENABLED', false),
        'retention_days' => env('IT_DRAFT_RETENTION_DAYS'),
        'terminal_retention_days' => env('IT_DRAFT_TERMINAL_RETENTION_DAYS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket approval categories
    |--------------------------------------------------------------------------
    |
    | Ticket categories that require a manager's sign-off before an agent may
    | resolve them — access grants, where the risk is granting standing access
    | rather than a one-off fix. A ticket raised in one of these is flagged
    | requires_approval at creation (§P-S3). Widen the net by adding categories
    | here (e.g. 'hardware' once repair vs purchase can be told apart).
    |
    */

    'approval' => [
        'categories' => ['account'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound email (email-to-ticket)
    |--------------------------------------------------------------------------
    |
    | Shared secret a mail provider must send as the X-IT-Inbound-Secret header
    | to POST /api/it/email/inbound. Empty (the default) makes the webhook
    | reject everything — email-in stays inert until this is set (§P-S4).
    |
    */

    'inbound_mail' => [
        'secret' => env('IT_INBOUND_MAIL_SECRET'),
        // No executable is selected implicitly. An unavailable scanner blocks file ingestion.
        'malware_scanner' => [
            'binary' => env('IT_INBOUND_MALWARE_SCANNER_BINARY'),
            'name' => env('IT_INBOUND_MALWARE_SCANNER_NAME', 'clamav'),
            'fd_pass' => env('IT_INBOUND_MALWARE_SCANNER_FD_PASS', false),
            'timeout_seconds' => env('IT_INBOUND_MALWARE_SCANNER_TIMEOUT', 30),
        ],
    ],

    /* Normalised provider callback for delivered, failed, and bounced mail. */
    'outbound_mail' => [
        'status_secret' => env('IT_OUTBOUND_MAIL_STATUS_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Desktop release acceptance fixtures
    |--------------------------------------------------------------------------
    |
    | Fixture writes remain disabled unless an approved non-production server
    | explicitly enables them and pins the exact MySQL database name by hash.
    | The mutation guard separately binds each action to clean origin/main.
    |
    */

    'desktop_release_fixtures' => [
        'enabled' => env('IT_SECURITY_DESKTOP_FIXTURES_ENABLED', false),
        'environment_class' => env('IT_SECURITY_DESKTOP_FIXTURES_ENVIRONMENT_CLASS'),
        'release_revision' => env('IT_SECURITY_DESKTOP_FIXTURES_RELEASE_REVISION'),
        'database_name_sha256' => env('IT_SECURITY_DESKTOP_FIXTURES_DATABASE_NAME_SHA256'),
        'actor_password' => env('IT_SECURITY_DESKTOP_FIXTURES_PASSWORD'),
        'reviewer_totp_secret' => env('IT_SECURITY_DESKTOP_FIXTURES_REVIEWER_TOTP_SECRET'),
    ],

];
