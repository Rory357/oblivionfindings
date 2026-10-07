<?php

namespace App\Providers;

use App\Observers\WorkforceEligibilitySourceObserver;
use App\Services\Eligibility\WorkforceEligibilitySources;
use Illuminate\Support\ServiceProvider;

class WorkforceEligibilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (array_keys(WorkforceEligibilitySources::definitions()) as $model) {
            $model::observe(WorkforceEligibilitySourceObserver::class);
        }
    }
}
