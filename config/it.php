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

];
