<?php

return [
    'mar' => [
        // How early a scheduled dose can be administered (minutes before scheduled time)
        'window_before_minutes' => 30,

        // How late a scheduled dose can be administered before it is flagged as late (minutes after scheduled time)
        'window_after_minutes' => 60,

        // When to mark a scheduled dose as "due soon" (minutes before scheduled time)
        'due_soon_minutes' => 60,
    ],

    'witness_pin' => [
        // Ask for the login password before someone sets a witness PIN while
        // they have no usable one (not set yet, reset by a lead, or needing
        // renewal), so a colleague at an unlocked session can't choose it for
        // them. On by default (Stephan, 30 Sep 2026: approved with PIN-1).
        'login_check_to_set' => (bool) env('MEDICATION_WITNESS_PIN_LOGIN_CHECK', true),
    ],
];
