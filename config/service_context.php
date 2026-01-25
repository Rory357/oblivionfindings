<?php

use App\Enums\ServiceType;

return [
    /*
    |--------------------------------------------------------------------------
    | Supported service delivery contexts
    |--------------------------------------------------------------------------
    |
    | Central registry of the service context types this platform supports.
    | We keep this config-driven so future policy/reporting logic can rely on
    | stable codes without hard-coding throughout the codebase.
    |
    */
    'types' => collect(ServiceType::cases())
        ->map(fn (ServiceType $t) => [
            'code' => $t->value,
            'label' => $t->label(),
            'description' => $t->description(),
        ])
        ->values()
        ->all(),
];
