<?php

return [
    // Existing deployment domain, now read through configuration so config:cache
    // behaves identically. Microsoft directory IDs remain provider identifiers.
    'staff_domain' => env('ORG_DOMAIN', ''),
];
