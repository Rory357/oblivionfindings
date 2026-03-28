<?php

namespace App\Providers;

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrDisciplinaryAction;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrJobPosting;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Policies\HrComplianceMatrixPolicy;
use App\Domain\Hr\Policies\HrCoursePolicy;
use App\Domain\Hr\Policies\HrDisciplinaryActionPolicy;
use App\Domain\Hr\Policies\HrEmployeeProfilePolicy;
use App\Domain\Hr\Policies\HrExpenseClaimPolicy;
use App\Domain\Hr\Policies\HrJobPostingPolicy;
use App\Domain\Hr\Policies\HrLeaveRequestPolicy;
use App\Domain\Hr\Policies\HrPerformanceReviewPolicy;
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
use App\Domain\Roadmap\Models\InitiativeBudget as RoadmapInitiativeBudget;
use App\Domain\Roadmap\Models\InitiativeSuggestion as RoadmapInitiativeSuggestion;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Domain\Roadmap\Policies\DecisionRequestPolicy as RoadmapDecisionRequestPolicy;
use App\Domain\Roadmap\Policies\InitiativeBudgetPolicy as RoadmapInitiativeBudgetPolicy;
use App\Domain\Roadmap\Policies\InitiativePolicy as RoadmapInitiativePolicy;
use App\Domain\Roadmap\Policies\InitiativeSuggestionPolicy as RoadmapInitiativeSuggestionPolicy;
use App\Domain\Roadmap\Policies\QuarterlyRoadmapPlanPolicy as RoadmapQuarterlyRoadmapPlanPolicy;
use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\IncidentTemplate;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteDamage;
use App\Policies\ClientIncidentPolicy;
use App\Policies\IncidentFollowupPolicy;
use App\Policies\IncidentTemplatePolicy;
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
        QuarterlyRoadmapPlan::class => RoadmapQuarterlyRoadmapPlanPolicy::class,
        RoadmapDecisionRequest::class => RoadmapDecisionRequestPolicy::class,
        RoadmapInitiativeSuggestion::class => RoadmapInitiativeSuggestionPolicy::class,
        RoadmapInitiativeBudget::class => RoadmapInitiativeBudgetPolicy::class,
        // Governance
        GovernanceMeeting::class => GovernanceMeetingPolicy::class,
        Resolution::class => ResolutionPolicy::class,
        RiskRegisterEntry::class => RiskRegisterEntryPolicy::class,
        ActionItem::class => ActionItemPolicy::class,
        Budget::class => BudgetPolicy::class,
        // HR
        HrEmployeeProfile::class => HrEmployeeProfilePolicy::class,
        HrLeaveRequest::class => HrLeaveRequestPolicy::class,
        HrPerformanceReview::class => HrPerformanceReviewPolicy::class,
        HrExpenseClaim::class => HrExpenseClaimPolicy::class,
        HrJobPosting::class => HrJobPostingPolicy::class,
        HrDisciplinaryAction::class => HrDisciplinaryActionPolicy::class,
        HrComplianceMatrix::class => HrComplianceMatrixPolicy::class,
        HrCourse::class => HrCoursePolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
