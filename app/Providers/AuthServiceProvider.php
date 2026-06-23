<?php

namespace App\Providers;

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrDisciplinaryAction;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrJobPosting;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Policies\HrComplianceMatrixPolicy;
use App\Domain\Hr\Policies\HrCoursePolicy;
use App\Domain\Hr\Policies\HrDisciplinaryActionPolicy;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Policies\ClinicalObservationPolicy;
use App\Domain\Clinical\Policies\ClinicalEventPolicy;
use App\Domain\Clinical\Policies\ClinicalProtocolPolicy;
use App\Domain\Hr\Policies\HrEmployeeProfilePolicy;
use App\Domain\Hr\Policies\HrExpenseClaimPolicy;
use App\Domain\Hr\Policies\HrJobPostingPolicy;
use App\Domain\Hr\Policies\HrPerformanceReviewPolicy;
use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BoardEvaluation;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\BoardMemberInterest;
use App\Domain\Governance\Models\CeoBoardReport;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\GovernanceDocument;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\StrategicPlan;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Policies\ActionItemPolicy;
use App\Domain\Governance\Policies\BoardEvaluationPolicy;
use App\Domain\Governance\Policies\BoardMemberInterestPolicy;
use App\Domain\Governance\Policies\BoardMemberPolicy;
use App\Domain\Governance\Policies\BudgetPolicy;
use App\Domain\Governance\Policies\CeoBoardReportPolicy;
use App\Domain\Governance\Policies\ComplianceObligationPolicy;
use App\Domain\Governance\Policies\GovernanceDocumentPolicy;
use App\Domain\Governance\Policies\GovernanceMeetingPolicy;
use App\Domain\Governance\Policies\GovernancePolicyPolicy;
use App\Domain\Governance\Policies\ResolutionPolicy;
use App\Domain\Governance\Policies\RiskRegisterEntryPolicy;
use App\Domain\Governance\Policies\StrategicPlanPolicy;
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
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinBankReconciliation;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCreditNote;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPettyCashFund;
use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Policies\FinAccountPolicy;
use App\Domain\Finance\Policies\FinBankAccountPolicy;
use App\Domain\Finance\Policies\FinBankTransactionPolicy;
use App\Domain\Finance\Policies\FinBankReconciliationPolicy;
use App\Domain\Finance\Policies\FinBillPolicy;
use App\Domain\Finance\Policies\FinCreditNotePolicy;
use App\Domain\Finance\Policies\FinFixedAssetPolicy;
use App\Domain\Finance\Policies\FinGstReturnPolicy;
use App\Domain\Finance\Policies\FinInvoicePolicy;
use App\Domain\Finance\Policies\FinJournalPolicy;
use App\Domain\Finance\Policies\FinPaymentRunPolicy;
use App\Domain\Finance\Policies\FinPettyCashPolicy;
use App\Domain\Finance\Policies\FinPurchaseOrderPolicy;
use App\Domain\Finance\Policies\FinVendorPolicy;
use App\Models\Asset;
use App\Models\BillingEntry;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\ClientAssessment;
use App\Models\ClientCondition;
use App\Models\ClientConsent;
use App\Models\ConsentRequest;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientNote;
use App\Models\ClientRisk;
use App\Models\DataBreachLog;
use App\Models\DataSubjectRequest;
use App\Models\IncidentFollowup;
use App\Models\IncidentTemplate;
use App\Models\LegalHold;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteDamage;
use App\Policies\AssetPolicy;
use App\Policies\BillingEntryPolicy;
use App\Policies\CarePlanPolicy;
use App\Policies\ClientAssessmentPolicy;
use App\Policies\ClientConditionPolicy;
use App\Policies\ClientConsentPolicy;
use App\Policies\ConsentRequestPolicy;
use App\Policies\ClientControlledDrugEntryPolicy;
use App\Policies\ClientIncidentPolicy;
use App\Policies\ClientMedicationPolicy;
use App\Policies\ClientNotePolicy;
use App\Policies\ClientPolicy;
use App\Policies\ClientRiskPolicy;
use App\Policies\DataBreachLogPolicy;
use App\Policies\DataSubjectRequestPolicy;
use App\Policies\IncidentFollowupPolicy;
use App\Policies\IncidentTemplatePolicy;
use App\Policies\LegalHoldPolicy;
use App\Policies\SafeguardingConcernPolicy;
use App\Policies\SiteChecklistTemplatePolicy;
use App\Policies\SiteDamagePolicy;
use App\Policies\SitePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        // Core
        Asset::class => AssetPolicy::class,
        Client::class => ClientPolicy::class,
        Site::class => SitePolicy::class,
        SafeguardingConcern::class => SafeguardingConcernPolicy::class,
        ClientIncident::class => ClientIncidentPolicy::class,
        IncidentTemplate::class => IncidentTemplatePolicy::class,
        IncidentFollowup::class => IncidentFollowupPolicy::class,
        SiteChecklistTemplate::class => SiteChecklistTemplatePolicy::class,
        SiteDamage::class => SiteDamagePolicy::class,
        ClientMedication::class => ClientMedicationPolicy::class,
        ClientNote::class => ClientNotePolicy::class,
        CarePlan::class => CarePlanPolicy::class,
        ClientAssessment::class => ClientAssessmentPolicy::class,
        ClientCondition::class => ClientConditionPolicy::class,
        ClientConsent::class => ClientConsentPolicy::class,
        ConsentRequest::class => ConsentRequestPolicy::class,
        ClientRisk::class => ClientRiskPolicy::class,
        ClientControlledDrugEntry::class => ClientControlledDrugEntryPolicy::class,
        BillingEntry::class => BillingEntryPolicy::class,
        DataBreachLog::class => DataBreachLogPolicy::class,
        DataSubjectRequest::class => DataSubjectRequestPolicy::class,
        LegalHold::class => LegalHoldPolicy::class,
        // Finance
        FinAccount::class => FinAccountPolicy::class,
        FinBankAccount::class => FinBankAccountPolicy::class,
        FinBankTransaction::class => FinBankTransactionPolicy::class,
        FinBankReconciliation::class => FinBankReconciliationPolicy::class,
        FinBill::class => FinBillPolicy::class,
        FinCreditNote::class => FinCreditNotePolicy::class,
        FinFixedAsset::class => FinFixedAssetPolicy::class,
        FinGstReturn::class => FinGstReturnPolicy::class,
        FinInvoice::class => FinInvoicePolicy::class,
        FinJournal::class => FinJournalPolicy::class,
        FinPaymentRun::class => FinPaymentRunPolicy::class,
        FinPettyCashFund::class => FinPettyCashPolicy::class,
        FinPurchaseOrder::class => FinPurchaseOrderPolicy::class,
        FinVendor::class => FinVendorPolicy::class,
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
        ComplianceObligation::class => ComplianceObligationPolicy::class,
        GovernancePolicy::class => GovernancePolicyPolicy::class,
        StrategicPlan::class => StrategicPlanPolicy::class,
        BoardEvaluation::class => BoardEvaluationPolicy::class,
        BoardMemberInterest::class => BoardMemberInterestPolicy::class,
        BoardMember::class => BoardMemberPolicy::class,
        CeoBoardReport::class => CeoBoardReportPolicy::class,
        GovernanceDocument::class => GovernanceDocumentPolicy::class,
        // HR
        HrEmployeeProfile::class => HrEmployeeProfilePolicy::class,
        HrPerformanceReview::class => HrPerformanceReviewPolicy::class,
        HrExpenseClaim::class => HrExpenseClaimPolicy::class,
        HrJobPosting::class => HrJobPostingPolicy::class,
        HrDisciplinaryAction::class => HrDisciplinaryActionPolicy::class,
        HrComplianceMatrix::class => HrComplianceMatrixPolicy::class,
        HrCourse::class => HrCoursePolicy::class,
        // Clinical
        ClinicalObservation::class => ClinicalObservationPolicy::class,
        ClinicalEvent::class => ClinicalEventPolicy::class,
        ClinicalProtocol::class => ClinicalProtocolPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
