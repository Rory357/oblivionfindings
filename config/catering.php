<?php

use App\Services\Catering\DeliveryProviders\NullDeliveryProvider;

return [
    /*
    |--------------------------------------------------------------------------
    | Default delivery provider
    |--------------------------------------------------------------------------
    |
    | The provider key used when a shopping list does not specify its own
    | provider_key. v1 ships with Manual (Null) only; future Countdown /
    | other grocery integrations register additional keys here.
    |
    */
    'default_provider' => env('CATERING_DEFAULT_PROVIDER', 'manual'),

    /*
    |--------------------------------------------------------------------------
    | Provider registry
    |--------------------------------------------------------------------------
    |
    | Map of provider key → fully-qualified contract implementation. The
    | DeliveryProviderManager resolves these out of the container.
    |
    */
    'providers' => [
        'manual' => NullDeliveryProvider::class,
    ],
];
