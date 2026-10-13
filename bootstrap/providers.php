<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\LlmServiceProvider;
use App\Providers\MedicationSupportServiceProvider;
use App\Providers\ObliServiceProvider;

return [
    ObliServiceProvider::class,
    AppServiceProvider::class,
    AuthServiceProvider::class,
    EventServiceProvider::class,
    MedicationSupportServiceProvider::class,
    FortifyServiceProvider::class,
    LlmServiceProvider::class,
];
