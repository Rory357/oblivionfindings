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
];
