<?php

return [
    // Separate credentials from interactive sign-in and shared site calendars.
    'google' => [
        'service_account_email' => env('WORK_CALENDAR_GOOGLE_SERVICE_ACCOUNT_EMAIL'),
        'private_key' => env('WORK_CALENDAR_GOOGLE_PRIVATE_KEY'),
    ],
    'microsoft' => [
        'directory_id' => env('WORK_CALENDAR_MICROSOFT_DIRECTORY_ID'),
        'client_id' => env('WORK_CALENDAR_MICROSOFT_CLIENT_ID'),
        'client_secret' => env('WORK_CALENDAR_MICROSOFT_CLIENT_SECRET'),
    ],
];
