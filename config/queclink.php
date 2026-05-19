<?php

return [
    /*
    |--------------------------------------------------------------------------
    | IMEI → model hints
    |--------------------------------------------------------------------------
    |
    | When a Queclink frame arrives without a device_name and the canonical
    | Device row has no model set, the ingest service infers the model from
    | the first 8 digits of the IMEI (TAC range). Extend this map as new
    | hardware is rolled out — keys are the 8-character IMEI prefix, values
    | are the model string that ends up on devices.model.
    |
    */
    'imei_model_hints' => [
        '86796306' => 'GL30MEU',
        '86110605' => 'GL30MEU',
        '86469606' => 'GV500CG',
    ],
];
