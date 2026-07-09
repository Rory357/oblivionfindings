<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ticket approval categories
    |--------------------------------------------------------------------------
    |
    | Ticket categories that require a manager's sign-off before an agent may
    | resolve or fulfil them — access grants and equipment spend. A ticket
    | raised in one of these is flagged requires_approval at creation (§P-S3).
    |
    */

    'approval' => [
        'categories' => ['account', 'hardware'],
    ],

];
