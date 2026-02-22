<?php

namespace App\Providers;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Policies\ActionItemPolicy;
use App\Domain\Governance\Policies\BudgetPolicy;
use App\Domain\Governance\Policies\GovernanceMeetingPolicy;
use App\Domain\Governance\Policies\ResolutionPolicy;
use App\Domain\Governance\Policies\RiskRegisterEntryPolicy;
use App\Domain\Roadmap\Models\DecisionRequest as RoadmapDecisionRequest;
use App\Domain\Roadmap\Models\Initiative as RoadmapInitiative;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\IncidentTemplate;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteDamage;
use App\Policies\ClientIncidentPolicy;
use App\Policies\IncidentFollowupPolicy;
use App\Policies\IncidentTemplatePolicy;
use App\Policies\Roadmap\DecisionRequestPolicy as RoadmapDecisionRequestPolicy;
use App\Policies\Roadmap\InitiativePolicy as RoadmapInitiativePolicy;
use App\Policies\Roadmap\QuarterlyRoadmapPlanPolicy;
use App\Policies\SiteChecklistTemplatePolicy;
use App\Policies\SiteDamagePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        ClientIncident::class => ClientIncidentPolicy::class,
        IncidentTemplate::class => IncidentTemplatePolicy::class,
        IncidentFollowup::class => IncidentFollowupPolicy::class,
        SiteChecklistTemplate::class => SiteChecklistTemplatePolicy::class,
        SiteDamage::class => SiteDamagePolicy::class,
        RoadmapInitiative::class => RoadmapInitiativePolicy::class,
        QuarterlyRoadmapPlan::class => QuarterlyRoadmapPlanPolicy::class,
        RoadmapDecisionRequest::class => RoadmapDecisionRequestPolicy::class,
        // Governance
        GovernanceMeeting::class => GovernanceMeetingPolicy::class,
        Resolution::class => ResolutionPolicy::class,
        RiskRegisterEntry::class => RiskRegisterEntryPolicy::class,
        ActionItem::class => ActionItemPolicy::class,
        Budget::class => BudgetPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
