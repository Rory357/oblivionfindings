<?php

return [
    // The staged package records durable effects but does not activate their
    // operational delivery. An approved deployment may enable both the
    // after-commit trigger and the scheduled retry with this single switch.
    'effects_enabled' => env('FLEET_MAINTENANCE_EFFECTS_ENABLED', false),
];
