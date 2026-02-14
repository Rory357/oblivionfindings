<?php

namespace App\Providers;

use App\Domain\Roadmap\Models\DecisionRequest as RoadmapDecisionRequest;
use App\Domain\Roadmap\Models\Initiative as RoadmapInitiative;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\IncidentTemplate;
use App\Models\SiteChecklistTemplate;
use App\Policies\ClientIncidentPolicy;
use App\Policies\IncidentFollowupPolicy;
use App\Policies\IncidentTemplatePolicy;
use App\Policies\Roadmap\DecisionRequestPolicy as RoadmapDecisionRequestPolicy;
use App\Policies\Roadmap\InitiativePolicy as RoadmapInitiativePolicy;
use App\Policies\Roadmap\QuarterlyRoadmapPlanPolicy;
use App\Policies\SiteChecklistTemplatePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        ClientIncident::class => ClientIncidentPolicy::class,
        IncidentTemplate::class => IncidentTemplatePolicy::class,
        IncidentFollowup::class => IncidentFollowupPolicy::class,
        SiteChecklistTemplate::class => SiteChecklistTemplatePolicy::class,
        RoadmapInitiative::class => RoadmapInitiativePolicy::class,
        QuarterlyRoadmapPlan::class => QuarterlyRoadmapPlanPolicy::class,
        RoadmapDecisionRequest::class => RoadmapDecisionRequestPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
