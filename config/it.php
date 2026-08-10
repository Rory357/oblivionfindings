<?php

return [

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
    ],

    /* Normalised provider callback for delivered, failed, and bounced mail. */
    'outbound_mail' => [
        'status_secret' => env('IT_OUTBOUND_MAIL_STATUS_SECRET'),
    ],

];
