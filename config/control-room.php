<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shift handover recovery
    |--------------------------------------------------------------------------
    |
    | A handover remains owned by its named outgoing lead until this many
    | hours have elapsed. After that point only the dedicated audited override
    | permission can prepare it on the unavailable lead's behalf.
    |
    */
    'handover_stale_after_hours' => (int) env('CONTROL_ROOM_HANDOVER_STALE_AFTER_HOURS', 16),
];
