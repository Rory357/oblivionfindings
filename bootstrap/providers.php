<?php

use App\Domain\Referrals\ReferralSearchServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\LlmServiceProvider;
use App\Providers\MedicationSupportServiceProvider;
use App\Providers\ObliServiceProvider;
use App\Providers\WorkforceEligibilityServiceProvider;

return [
    ObliServiceProvider::class,
    AppServiceProvider::class,
    AuthServiceProvider::class,
    EventServiceProvider::class,
    MedicationSupportServiceProvider::class,
    WorkforceEligibilityServiceProvider::class,
    FortifyServiceProvider::class,
    LlmServiceProvider::class,
    ReferralSearchServiceProvider::class,
];
