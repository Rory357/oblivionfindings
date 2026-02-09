<?php

namespace App\Providers;

use App\Models\ClientIncident;
use App\Models\IncidentTemplate;
use App\Models\IncidentFollowup;
use App\Models\SiteChecklistTemplate;
use App\Policies\ClientIncidentPolicy;
use App\Policies\IncidentTemplatePolicy;
use App\Policies\IncidentFollowupPolicy;
use App\Policies\SiteChecklistTemplatePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        ClientIncident::class => ClientIncidentPolicy::class,
        IncidentTemplate::class => IncidentTemplatePolicy::class,
        IncidentFollowup::class => IncidentFollowupPolicy::class,
        SiteChecklistTemplate::class => SiteChecklistTemplatePolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
