<?php

use App\Domain\Finance\Jobs\CalculateGstReturnJob;
use App\Domain\Finance\Jobs\CheckBillDueDatesJob;
use App\Domain\Finance\Jobs\GenerateRecurringJournalsJob;
use App\Domain\Finance\Jobs\PostLeaveProvisionJob;
use App\Domain\Finance\Jobs\PostSiteRentJob;
use App\Domain\Finance\Jobs\PostSiteUtilitiesJob;
use App\Domain\Finance\Jobs\ProcessRecurringChargesJob;
use App\Domain\Finance\Jobs\PruneFinanceAuditExportsJob;
use App\Domain\Finance\Jobs\RunDepreciationJob;
use App\Domain\Finance\Jobs\RunPaymentMatchingJob;
use App\Domain\Finance\Jobs\SnapshotFinancialReportsJob;
use App\Domain\Finance\Jobs\SyncAccountingIntegrationJob;
use App\Domain\Finance\Jobs\SyncBankFeedsJob;
use App\Domain\Finance\Jobs\SyncBudgetActualsJob;
use App\Domain\Finance\Models\FinAccountingIntegration;
use App\Domain\Governance\Jobs\SendBoardDigest;
use App\Domain\Hr\Jobs\ArchiveCandidateDataJob;
use App\Domain\Hr\Jobs\CalculateWellbeingIndicatorsJob;
use App\Domain\Hr\Jobs\EscalateLeaveApprovalsJob;
use App\Domain\Hr\Jobs\EvaluateComplianceMatrixJob;
use App\Domain\Hr\Jobs\ProcessLeaveBalanceAccrualJob;
use App\Domain\Hr\Jobs\PublishDueAnnouncementsJob;
use App\Domain\Hr\Jobs\RunHrScheduledReportsJob;
use App\Domain\Hr\Jobs\SendEngagementActionPlanRemindersJob;
use App\Domain\Hr\Jobs\SendExpiryRemindersJob;
use App\Domain\Roadmap\Jobs\DetectRoadmapTriageOverloadJob;
use App\Domain\Roadmap\Jobs\ProcessRoadmapSuggestionsJob;
use App\Domain\Roadmap\Jobs\ScoreRoadmapInitiativesJob;
use App\Domain\Roadmap\Jobs\SendRoadmapDigestJob;
use App\Jobs\AutoEscalateControlRoomQueues;
use App\Jobs\CheckControlRoomSlaBreaches;
use App\Jobs\ChecklistDueJob;
use App\Jobs\CheckLoneWorkerOverdueJob;
use App\Jobs\CheckOverdueCorrectiveActionsJob;
use App\Jobs\CheckOverdueInvestigationsJob;
use App\Jobs\CheckRiskAssessmentReviewsJob;
use App\Jobs\DetectCrDeviceOfflineJob;
use App\Jobs\DetectFleetOfflineDevices;
use App\Jobs\EnforceDataRetentionJob;
use App\Jobs\EscalateUnresolvedEligibilityJob;
use App\Jobs\HazardOverdueJob;
use App\Jobs\InspectionDueJob;
use App\Jobs\PrivacyDeadlineRemindersJob;
use App\Jobs\ProcessControlRoomSignals;
use App\Jobs\PruneAssetTelemetry;
use App\Jobs\PruneFleetTelemetry;
use App\Jobs\RecalculateFutureShiftEligibility;
use App\Jobs\ReconcileTimesheetsJob;
use App\Jobs\SendEventReminderJob;
use App\Jobs\ShiftAutoAlertJob;
use App\Jobs\ShiftTaskDueJob;
use App\Jobs\SyncResourceCalendarsJob;
use App\Models\RecurringCharge;
use App\Services\MedicationAlertService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily break-glass summary (internal ops): 08:00 NZ time
app(Schedule::class)
    ->command('breakglass:daily-report')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:00');

// Overdue follow-up reminders: every day 09:00 NZ
app(Schedule::class)
    ->command('followups:remind-overdue')
    ->timezone('Pacific/Auckland')
    ->dailyAt('09:00');

// Day-before interview reminders (candidate + panel): 08:00 NZ
app(Schedule::class)
    ->command('recruitment:send-interview-reminders')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:00');

// Escalate offers stuck awaiting sign-off (≥2 days) to the hiring manager: 08:20 NZ
app(Schedule::class)
    ->command('recruitment:send-offer-approval-reminders')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:20');

// PPE compliance reminders: worker unacknowledged/fit-test digests + H&S lead
// overdue-inspection/expiring/condemned digests — every day 08:15 NZ.
app(Schedule::class)
    ->command('ppe:compliance-reminders')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:15');

// Controlled-drug balance checks not done in ≥7 days → dashboard alert: 07:30 NZ
app(Schedule::class)
    ->command('emar:escalate-overdue-cd-checks')
    ->timezone('Pacific/Auckland')
    ->dailyAt('07:30');

// HR calendar event reminders: every minute, fire any reminder whose lead time
// lands in the last minute (de-duped via last_sent_at).
app(Schedule::class)
    ->command('hr:dispatch-calendar-reminders')
    ->everyMinute()
    ->withoutOverlapping();

// High severity incidents that have not been reviewed: hourly (internal ops)
app(Schedule::class)
    ->command('incidents:remind-high-unreviewed')
    ->timezone('Pacific/Auckland')
    ->hourly();

// Expire stale consent requests past expires_at (14-day default window).
app(Schedule::class)
    ->command('consent-requests:expire-stale')
    ->timezone('Pacific/Auckland')
    ->hourly();

// Remind recipients of pending consent requests expiring in one to three days.
app(Schedule::class)
    ->command('consent-requests:send-reminders')
    ->timezone('Pacific/Auckland')
    ->hourly();

// Escalation engine: re-notify pending items based on admin-configured rules
app(Schedule::class)
    ->command('notifications:escalate')
    ->timezone('Pacific/Auckland')
    ->everyTenMinutes();

// Fleet device offline detection
app(Schedule::class)
    ->job(new DetectFleetOfflineDevices)
    ->timezone('Pacific/Auckland')
    ->everyFiveMinutes();

// Control Room device offline detection (non-fleet: bed sensors, cameras, alarm panels, etc.)
app(Schedule::class)
    ->job(new DetectCrDeviceOfflineJob)
    ->timezone('Pacific/Auckland')
    ->everyFiveMinutes();

// Fleet telemetry retention cleanup
app(Schedule::class)
    ->job(new PruneFleetTelemetry)
    ->timezone('Pacific/Auckland')
    ->dailyAt('02:00');

// Asset telemetry retention cleanup
app(Schedule::class)
    ->job(new PruneAssetTelemetry)
    ->timezone('Pacific/Auckland')
    ->dailyAt('02:30');

// Control Room signal processing
app(Schedule::class)
    ->job(new ProcessControlRoomSignals)
    ->timezone('Pacific/Auckland')
    ->everyMinute();

// Fire scheduled HR announcements the moment their publish time arrives
app(Schedule::class)
    ->job(new PublishDueAnnouncementsJob)
    ->timezone('Pacific/Auckland')
    ->everyMinute()
    ->withoutOverlapping();

// Control Room SLA breach checks
app(Schedule::class)
    ->job(new CheckControlRoomSlaBreaches)
    ->timezone('Pacific/Auckland')
    ->everyFiveMinutes();

// Shift anomaly detection and control-room signal emission
app(Schedule::class)
    ->job(new ShiftAutoAlertJob)
    ->timezone('Pacific/Auckland')
    ->everyFiveMinutes();

// Timed shift task reminders for My Day, email, in-app, and push delivery.
app(Schedule::class)
    ->job(new ShiftTaskDueJob)
    ->timezone('Pacific/Auckland')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Lone worker overdue check-in detection and control-room signal emission
app(Schedule::class)
    ->job(new CheckLoneWorkerOverdueJob)
    ->timezone('Pacific/Auckland')
    ->everyFiveMinutes();

// Control Room auto escalation between queues
app(Schedule::class)
    ->job(new AutoEscalateControlRoomQueues)
    ->timezone('Pacific/Auckland')
    ->everyTenMinutes();

// Sites Module Scheduled Jobs

// Event reminders: check every 5 minutes for upcoming events
app(Schedule::class)
    ->job(new SendEventReminderJob)
    ->timezone('Pacific/Auckland')
    ->everyFiveMinutes();

// Checklist due reminders and overdue checks: daily at 08:00
app(Schedule::class)
    ->job(new ChecklistDueJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:00');

// Inspection due reminders: daily at 08:30
app(Schedule::class)
    ->job(new InspectionDueJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:30');

// H&S monitoring — overdue investigations and corrective actions
app(Schedule::class)
    ->job(new CheckOverdueInvestigationsJob)
    ->timezone('Pacific/Auckland')
    ->everyFifteenMinutes();

app(Schedule::class)
    ->job(new CheckOverdueCorrectiveActionsJob)
    ->timezone('Pacific/Auckland')
    ->everyFifteenMinutes();

// H&S monitoring — risk assessment review dates (daily)
app(Schedule::class)
    ->job(new CheckRiskAssessmentReviewsJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:15');

// Safeguarding monitoring — risk reviews due + external-report acknowledgements awaited (daily)
app(Schedule::class)
    ->command('safeguarding:review-reminders')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:20');

// Injuries & RTW monitoring — return-to-work reviews + capacity reassessments due (daily)
app(Schedule::class)
    ->command('injuries:review-reminders')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:25');

// First Aid — follow-ups due/overdue on open records (daily)
app(Schedule::class)
    ->command('first-aid:followup-reminders')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:30');

// Health & Clinical — deterioration watch + overdue observations digest (daily)
app(Schedule::class)
    ->command('clinical:deterioration-reminders')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:35');

// Hazard overdue checks and escalations: daily at 09:00
app(Schedule::class)
    ->job(new HazardOverdueJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('09:00');

// Privacy deadlines — access/correction requests overdue or due soon + notifiable
// breaches still awaiting OPC notification (daily)
app(Schedule::class)
    ->job(new PrivacyDeadlineRemindersJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:25');

// HR Module Scheduled Jobs

// Evaluate compliance matrix for all employees: daily at 01:00
app(Schedule::class)
    ->job(new EvaluateComplianceMatrixJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('01:00');

// Re-evaluate eligibility for future shifts (detect newly blocked): daily at 01:30
app(Schedule::class)
    ->job(new RecalculateFutureShiftEligibility)
    ->timezone('Pacific/Auckland')
    ->dailyAt('01:30');

// Escalate unresolved invalid future shifts (24h+ after first detection): daily at 08:30
app(Schedule::class)
    ->job(new EscalateUnresolvedEligibilityJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:30');

// Calculate wellbeing indicators (fatigue, overtime) + notify on red escalation: daily at 02:00
app(Schedule::class)
    ->job(new CalculateWellbeingIndicatorsJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('02:00');

// Send expiry reminders (credentials, training, vetting): daily at 08:00
app(Schedule::class)
    ->job(new SendExpiryRemindersJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:00');

// Onboarding email automation: dispatch templates due today (start_date − offset): daily 08:00
app(Schedule::class)
    ->command('hr:onboarding-emails')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:00')
    ->withoutOverlapping();

// Vacancy check: reconcile position headcounts + report understaffed positions: daily 06:30
app(Schedule::class)
    ->command('hr:check-vacancies')
    ->timezone('Pacific/Auckland')
    ->dailyAt('06:30')
    ->withoutOverlapping();

// Engagement action plan reminders and overdue escalations: daily at 07:15
app(Schedule::class)
    ->job(new SendEngagementActionPlanRemindersJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('07:15');

// Leave balance accrual: monthly on the 1st at 00:30
app(Schedule::class)
    ->job(new ProcessLeaveBalanceAccrualJob)
    ->timezone('Pacific/Auckland')
    ->monthlyOn(1, '00:30');

// Site rent posting: monthly on the 1st at 02:00 NZT
app(Schedule::class)
    ->job(new PostSiteRentJob)
    ->timezone('Pacific/Auckland')
    ->monthlyOn(1, '02:00');

// Site utility costs: monthly on the 1st at 02:30 NZT
app(Schedule::class)
    ->job(new PostSiteUtilitiesJob)
    ->timezone('Pacific/Auckland')
    ->monthlyOn(1, '02:30');

// Leave liability provision: monthly on the 1st at 01:00 (after accrual at 00:30)
app(Schedule::class)
    ->job(new PostLeaveProvisionJob)
    ->timezone('Pacific/Auckland')
    ->monthlyOn(1, '01:00');

// Finance recurring journals: daily before rent/utilities/depreciation posting
app(Schedule::class)
    ->job(new GenerateRecurringJournalsJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('02:45')
    ->withoutOverlapping();

// Fixed asset depreciation: monthly on the 1st after recurring site costs
app(Schedule::class)
    ->job(new RunDepreciationJob)
    ->timezone('Pacific/Auckland')
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping();

// Bank feed sync: regular import cadence for active feeds
app(Schedule::class)
    ->job(new SyncBankFeedsJob)
    ->timezone('Pacific/Auckland')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

// Payment matching: trails bank-feed sync by 15 minutes
app(Schedule::class)
    ->job(new RunPaymentMatchingJob(null))
    ->timezone('Pacific/Auckland')
    ->cron('15,45 * * * *')
    ->withoutOverlapping();

// Accounts payable reminders: unpaid bills due soon or overdue
app(Schedule::class)
    ->job(new CheckBillDueDatesJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('07:00')
    ->withoutOverlapping();

// Recurring charges: generate billing entries for due recurring charges, per org.
app(Schedule::class)
    ->call(function () {
        RecurringCharge::query()
            ->where('is_active', true)
            ->whereDate('next_charge_at', '<=', now()->toDateString())
            ->whereNotNull('organization_id')
            ->distinct()
            ->pluck('organization_id')
            ->each(fn ($orgId) => ProcessRecurringChargesJob::dispatch((int) $orgId));
    })
    ->name('finance:process-recurring-charges')
    ->timezone('Pacific/Auckland')
    ->dailyAt('02:45')
    ->withoutOverlapping();

// GST draft calculation: two-monthly, before the 28th filing cycle
app(Schedule::class)
    ->job(new CalculateGstReturnJob)
    ->timezone('Pacific/Auckland')
    ->cron('0 4 28 */2 *')
    ->withoutOverlapping();

// Immutable financial report snapshots: month opening snapshot for audit trail
app(Schedule::class)
    ->job(new SnapshotFinancialReportsJob)
    ->timezone('Pacific/Auckland')
    ->monthlyOn(1, '23:55')
    ->withoutOverlapping();

// Accounting integration sync: only dispatch active Xero integrations.
app(Schedule::class)
    ->call(function () {
        FinAccountingIntegration::query()
            ->active()
            ->forProvider('xero')
            ->whereNotNull('tenant_id')
            ->pluck('id')
            ->each(fn (int $integrationId) => SyncAccountingIntegrationJob::dispatch($integrationId));
    })
    ->name(SyncAccountingIntegrationJob::class)
    ->timezone('Pacific/Auckland')
    ->hourlyAt(10)
    ->withoutOverlapping();

// Leave approval SLA escalations: every 30 minutes
app(Schedule::class)
    ->job(new EscalateLeaveApprovalsJob)
    ->timezone('Pacific/Auckland')
    ->everyThirtyMinutes();

// Archive expired candidate data per retention policy: weekly Sunday 03:00
app(Schedule::class)
    ->job(new ArchiveCandidateDataJob)
    ->timezone('Pacific/Auckland')
    ->weeklyOn(0, '03:00');

// Scheduled HR report subscriptions: every 15 minutes
app(Schedule::class)
    ->job(new RunHrScheduledReportsJob)
    ->timezone('Pacific/Auckland')
    ->everyFifteenMinutes();

// Medical Module Scheduled Jobs

// Generate daily medication rounds from active templates: 00:05 NZ time
app(Schedule::class)
    ->command('emar:generate-rounds')
    ->timezone('Pacific/Auckland')
    ->dailyAt('00:05');

// Check medication stock levels and expiry dates: daily at 06:00
app(Schedule::class)
    ->command('emar:check-medication-stock')
    ->timezone('Pacific/Auckland')
    ->dailyAt('06:00');

// Clear stale medication alerts: hourly
app(Schedule::class)
    ->call(fn () => app(MedicationAlertService::class)->clearStaleAlerts())
    ->timezone('Pacific/Auckland')
    ->hourly();

// Send medication alert digests and escalations: every 15 minutes
app(Schedule::class)
    ->command('emar:send-alerts')
    ->timezone('Pacific/Auckland')
    ->everyFifteenMinutes();

// Generate medication chart review, medicine review, and INR due alerts: daily
app(Schedule::class)
    ->command('emar:check-medication-reviews')
    ->timezone('Pacific/Auckland')
    ->dailyAt('07:05')
    ->withoutOverlapping();

// Sync Governance clinical indicators from Health & Clinical/eMAR: daily at 00:20
app(Schedule::class)
    ->command('governance:sync-clinical-data')
    ->timezone('Pacific/Auckland')
    ->dailyAt('00:20');

// Roadmap Module Scheduled Jobs

// Suggestion ingestion + dedupe sweep: hourly
app(Schedule::class)
    ->job(new ProcessRoadmapSuggestionsJob)
    ->timezone('Pacific/Auckland')
    ->hourly();

// Re-score active initiatives: daily at 05:30
app(Schedule::class)
    ->job(new ScoreRoadmapInitiativesJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('05:30');

// Triage inbox overload check: every 30 minutes
app(Schedule::class)
    ->job(new DetectRoadmapTriageOverloadJob)
    ->timezone('Pacific/Auckland')
    ->everyThirtyMinutes();

// Digest for managers/board leads: weekly Monday 07:30
app(Schedule::class)
    ->job(new SendRoadmapDigestJob)
    ->timezone('Pacific/Auckland')
    ->weeklyOn(1, '07:30');

// Governance scheduled jobs

app(Schedule::class)
    ->command('governance:compliance-reminders')
    ->timezone('Pacific/Auckland')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

app(Schedule::class)
    ->command('governance:check-risk-reviews')
    ->timezone('Pacific/Auckland')
    ->dailyAt('07:45')
    ->withoutOverlapping();

app(Schedule::class)
    ->job(new SyncBudgetActualsJob)
    ->timezone('Pacific/Auckland')
    ->hourly()
    ->withoutOverlapping();

app(Schedule::class)
    ->command('governance:update-budget-variances')
    ->timezone('Pacific/Auckland')
    ->hourlyAt(10)
    ->withoutOverlapping();

app(Schedule::class)
    ->job(new SendBoardDigest)
    ->timezone('Pacific/Auckland')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Spawn recurring governance meetings from active templates: weekly Monday 06:00
app(Schedule::class)
    ->command('governance:spawn-recurring-meetings')
    ->timezone('Pacific/Auckland')
    ->weeklyOn(1, '06:00')
    ->withoutOverlapping();

// Generate compliance obligations from upcoming donor-fund report deadlines: daily 06:15
app(Schedule::class)
    ->command('governance:sync-donor-fund-compliance')
    ->timezone('Pacific/Auckland')
    ->dailyAt('06:15')
    ->withoutOverlapping();

// Generate site risk-review action items 14 days ahead: daily 06:30
app(Schedule::class)
    ->command('governance:sync-site-risk-reviews')
    ->timezone('Pacific/Auckland')
    ->dailyAt('06:30')
    ->withoutOverlapping();

// Generate HSWA worker-participation obligations (HSC 3-month cadence, HSR term
// re-election, HSR training) ahead of their deadlines: daily 06:20
app(Schedule::class)
    ->command('participation:sync-obligations')
    ->timezone('Pacific/Auckland')
    ->dailyAt('06:20')
    ->withoutOverlapping();

// Timesheet reconciliation: re-sync draft timesheets and re-evaluate submitted timesheets
app(Schedule::class)
    ->job(new ReconcileTimesheetsJob)
    ->timezone('Pacific/Auckland')
    ->hourly()
    ->withoutOverlapping();

// Orphan detection: completed shifts without timesheets, attendance gaps, broken linkage
app(Schedule::class)
    ->command('shifts:detect-orphans')
    ->timezone('Pacific/Auckland')
    ->dailyAt('06:00');

app(Schedule::class)
    ->command('shifts:expire-positions')
    ->timezone('Pacific/Auckland')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Rostering maintenance: expire stale suggestions and archive completed published periods
app(Schedule::class)
    ->command('rostering:expire-stale-suggestion-runs')
    ->timezone('Pacific/Auckland')
    ->hourly()
    ->withoutOverlapping();

app(Schedule::class)
    ->command('rostering:archive-completed-periods')
    ->timezone('Pacific/Auckland')
    ->dailyAt('05:30')
    ->withoutOverlapping();

// Stale alert auto-resolution: clears unactioned operational alerts past TTL
app(Schedule::class)
    ->command('control-room:auto-resolve-stale-alerts')
    ->timezone('Pacific/Auckland')
    ->hourly();

// Privacy & Compliance Scheduled Jobs

// Enforce data retention policies: daily at 03:00
app(Schedule::class)
    ->job(new EnforceDataRetentionJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('03:00');

// Finance audit export retention: daily at 03:00, alongside privacy retention.
app(Schedule::class)
    ->job(new PruneFinanceAuditExportsJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('03:00');

// Client profile retention: prune audit_logs and timeline_events older than
// configured windows (defaults: 2 yrs audit, 5 yrs timeline). Pinned timeline
// events are preserved.
app(Schedule::class)
    ->command('oblivion:prune-retention')
    ->timezone('Pacific/Auckland')
    ->weeklyOn(0, '03:30');

// Calendar sync (Part D): push house calendar events out to mapped Google/Outlook
// resource calendars + pull external busy for two-way mappings. Cadence default 15m.
app(Schedule::class)
    ->job(new SyncResourceCalendarsJob)
    ->timezone('Pacific/Auckland')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
