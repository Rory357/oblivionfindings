<?php

use App\Domain\Finance\Jobs\CalculateGstReturnJob;
use App\Domain\Finance\Jobs\CheckBillDueDatesJob;
use App\Domain\Finance\Jobs\GenerateRecurringJournalsJob;
use App\Domain\Finance\Jobs\PostLeaveProvisionJob;
use App\Domain\Finance\Jobs\PostSiteRentJob;
use App\Domain\Finance\Jobs\PostSiteUtilitiesJob;
use App\Domain\Finance\Jobs\PruneFinanceAuditExportsJob;
use App\Domain\Finance\Jobs\ReconcileUnpostedClientFundJournalsJob;
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
use App\Domain\Hr\Jobs\RecoverComplianceReminderDeliveriesJob;
use App\Domain\Hr\Jobs\RunHrScheduledReportsJob;
use App\Domain\Hr\Jobs\SendAssetRemindersJob;
use App\Domain\Hr\Jobs\SendEngagementActionPlanRemindersJob;
use App\Domain\Hr\Jobs\SendExpiryRemindersJob;
use App\Domain\Hr\Jobs\SendOfferExpiryRemindersJob;
use App\Domain\Hr\Jobs\SendPipRemindersJob;
use App\Domain\Hr\Jobs\SendWellbeingRemindersJob;
use App\Domain\It\Services\ItAutomationScheduleCatalog;
use App\Domain\Monitoring\Jobs\DownsampleMetrics;
use App\Domain\Monitoring\Jobs\EnforceMonitoringRetention;
use App\Domain\Monitoring\Jobs\EvaluateCollectorHealth;
use App\Domain\Monitoring\Jobs\ScheduleDueMonitors;
use App\Domain\Monitoring\Jobs\ScheduleProviderCapabilities;
use App\Domain\Roadmap\Jobs\DetectRoadmapTriageOverloadJob;
use App\Domain\Roadmap\Jobs\ProcessRoadmapSuggestionsJob;
use App\Domain\Roadmap\Jobs\ScoreRoadmapInitiativesJob;
use App\Domain\Roadmap\Jobs\SendRoadmapDigestJob;
use App\Domain\SecurityDevices\Credentials\Jobs\ReconcileCredentialLeases;
use App\Domain\SecurityDevices\Management\Jobs\RecoverCollectorCommands;
use App\Jobs\AutoEscalateControlRoomQueues;
use App\Jobs\CheckControlRoomSlaBreaches;
use App\Jobs\ChecklistDueJob;
use App\Jobs\CheckLoneWorkerOverdueJob;
use App\Jobs\CheckOverdueCorrectiveActionsJob;
use App\Jobs\CheckOverdueInvestigationsJob;
use App\Jobs\CheckRiskAssessmentReviewsJob;
use App\Jobs\DetectFleetOfflineDevices;
use App\Jobs\EnforceDataRetentionJob;
use App\Jobs\EscalateUnresolvedEligibilityJob;
use App\Jobs\ExpireQueclinkGovernedCommands;
use App\Jobs\FleetAutoAlertJob;
use App\Jobs\Governance\RecoverIncidentGovernanceEscalationsJob;
use App\Jobs\HazardOverdueJob;
use App\Jobs\InspectionDueJob;
use App\Jobs\Notifications\RecoverControlRoomAlertNotificationsJob;
use App\Jobs\Operations\ProcessRecurringChargesJob;
use App\Jobs\PrivacyDeadlineRemindersJob;
use App\Jobs\ProcessControlRoomSignals;
use App\Jobs\PruneAssetTelemetry;
use App\Jobs\PruneFleetTelemetry;
use App\Jobs\PrunePersonalTrackingTelemetry;
use App\Jobs\RecalculateFutureShiftEligibility;
use App\Jobs\ReconcileTimesheetsJob;
use App\Jobs\SendEventReminderJob;
use App\Jobs\ShiftAutoAlertJob;
use App\Jobs\ShiftTaskDueJob;
use App\Jobs\SyncResourceCalendarsJob;
use App\Services\MedicationAlertService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule lightweight monitor orchestration only. Protocol checks and remote
// collector configuration are routed to their isolated runtime workloads.
app(Schedule::class)
    ->job(new ScheduleDueMonitors)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

// Provider adapters expose only verified typed collection contracts. The
// scheduler delegates each declared capability to the same signed runtime.
app(Schedule::class)
    ->job(new ScheduleProviderCapabilities)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

app(Schedule::class)
    ->job(new EvaluateCollectorHealth)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

// Each isolated runtime queue consumes its own canary. This proves worker
// availability without asking web requests to probe runtime processes.
app(Schedule::class)
    ->command('monitoring:dispatch-runtime-heartbeats')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

// This scheduler-direct dead-man heartbeat deliberately bypasses Redis queues.
// It is sent only after every isolated worker and UDP listener proves current;
// scheduler, application, database, Redis, worker, or listener loss withholds it.
app(Schedule::class)
    ->command('monitoring:send-external-heartbeat')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

// Revoke completed or compromised runtime credential leases, retry provider
// revocation while leases remain live, and erase lease identifiers at expiry.
app(Schedule::class)
    ->job(new ReconcileCredentialLeases)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

// Close collector command attempts after their immutable delivery window. An
// unissued attempt expires; an issued configuration becomes uncertain and is
// never repeated without fresh reconciliation and a new governed request.
app(Schedule::class)
    ->job(new RecoverCollectorCommands)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

// Queclink native commands remain accepted/running until the paired tracker
// returns a fresh privacy-governed observation. Close expired requests without
// repeating the Device action and recover any pending reconciliation work.
app(Schedule::class)
    ->job(new ExpireQueclinkGovernedCommands)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

// The Device document row is the durable storage intent. Finish verified
// staged uploads, reasoned quarantine removals and interrupted private-blob
// cleanup without relying on the original web request or a queue worker.
app(Schedule::class)
    ->command('security-devices:reconcile-document-storage --limit=100')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

app(Schedule::class)
    ->job(new DownsampleMetrics)
    ->hourlyAt(10)
    ->onOneServer()
    ->withoutOverlapping();

app(Schedule::class)
    ->job(new EnforceMonitoringRetention)
    ->timezone('Pacific/Auckland')
    ->dailyAt('02:30')
    ->onOneServer()
    ->withoutOverlapping();

// Durable monitoring outbox and replay intents recover after queue outages or
// a process crash between database commit and queue acceptance.
app(Schedule::class)
    ->command('monitoring:recover-delivery')
    ->everyMinute()
    ->withoutOverlapping();

// Fleet, shift, device-event and incident-lifecycle source rows are durable delivery intents.
// Reconcile any missing outbox and retry transiently stranded routing work.
app(Schedule::class)
    ->command('safety-signals:recover --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

// Recover Control Room alert notification outbox rows that were committed
// before a transient queue/worker failure could deliver them.
app(Schedule::class)
    ->job(new RecoverControlRoomAlertNotificationsJob)
    ->everyMinute()
    ->withoutOverlapping();

// HR compliance reminders are staged as durable delivery intents. Recover any
// row committed while the queue was unavailable or after a worker failure.
app(Schedule::class)
    ->job(new RecoverComplianceReminderDeliveriesJob)
    ->everyMinute()
    ->withoutOverlapping();

// Client incidents are the durable intent for governance registration. Recover
// any eligible committed row missed by its immediate after-commit dispatch.
app(Schedule::class)
    ->job(new RecoverIncidentGovernanceEscalationsJob)
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Daily break-glass summary (internal ops): 08:00 NZ time
app(Schedule::class)
    ->command('breakglass:daily-report')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:00');

// IT service operations owns these three definitions so the scheduler and
// the HTTP health view consume one canonical cadence and name catalogue.
app(ItAutomationScheduleCatalog::class)->register();

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

// OKR & development reminders: check-in due / overdue / KR-due / dev review: 08:00 NZ
app(Schedule::class)
    ->command('hr:goal-reminders')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:00');

// Weekly OKR digest to objective owners: Monday 08:00 NZ
app(Schedule::class)
    ->command('hr:goal-weekly-digest')
    ->timezone('Pacific/Auckland')
    ->weeklyOn(1, '08:00');

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

// All Tasks overdue escalations: nudge assignees the hour an item goes
// overdue, escalate 3-day-overdue items to managers. Once per item+level
// for its lifetime (deduped via task_escalations).
app(Schedule::class)
    ->command('tasks:escalate')
    ->timezone('Pacific/Auckland')
    ->hourly()
    ->withoutOverlapping();

// Fleet device offline detection
app(Schedule::class)
    ->job(new DetectFleetOfflineDevices)
    ->timezone('Pacific/Auckland')
    ->everyFiveMinutes();

// Personal tracking retention is assignment-specific and runs before the
// broader Fleet and Asset retention windows.
app(Schedule::class)
    ->job(new PrunePersonalTrackingTelemetry)
    ->timezone('Pacific/Auckland')
    ->dailyAt('01:45');

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

// Fleet compliance sweep — overdue bookings, WOF/rego expiry thresholds,
// maintenance overdue, low battery → FleetSignals + manager notifications
app(Schedule::class)
    ->job(new FleetAutoAlertJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('07:00');

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

// Persisted one-shot reminders for worker police-vetting and driver-licence expiry.
app(Schedule::class)
    ->command('hr:send-worker-compliance-expiry-reminders')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:05')
    ->withoutOverlapping();

// HR asset reminders (warranty expiring, returns overdue, repairs overdue,
// leaver-held): daily at 07:30
app(Schedule::class)
    ->job(new SendAssetRemindersJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('07:30');

// Onboarding email automation: dispatch templates due today (start_date − offset): daily 08:00
app(Schedule::class)
    ->command('hr:onboarding-emails')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:00')
    ->withoutOverlapping();

// Onboarding task reminders: nudge assignees about overdue / due-soon tasks: daily 08:15
app(Schedule::class)
    ->command('hr:onboarding-reminders')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:15')
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

// Performance & Development hub: expire overdue 360 requests + nudge reviewers of
// due 360s and performance reviews (in-app). Daily at 07:00.
app(Schedule::class)
    ->command('hr:performance-reminders')
    ->timezone('Pacific/Auckland')
    ->dailyAt('07:00')
    ->withoutOverlapping();

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

// Client-fund transactions are the durable outbox for GL posting. Recover
// rows left unposted by transient queue or handler failures.
app(Schedule::class)
    ->job(new ReconcileUnpostedClientFundJournalsJob)
    ->timezone('Pacific/Auckland')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Recurring charges: generate billing entries for all due application charges.
app(Schedule::class)
    ->job(new ProcessRecurringChargesJob)
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

// Single hourly budget-actuals sync (C6). SyncBudgetActualsJob refreshes each
// budget line item's actual_amount from posted GL journals — the model's saving
// event recomputes variance — and fires variance alerts. The separate hourly
// `governance:update-budget-variances` schedule was a REDUNDANT second call to the
// same BudgetActualsService::syncActuals (a double-write of the cache every hour) —
// removed. The command still exists for manual/on-demand recompute.
app(Schedule::class)
    ->job(new SyncBudgetActualsJob)
    ->timezone('Pacific/Auckland')
    ->hourly()
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
    ->dailyAt('03:00')
    ->withoutOverlapping();

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

// Recruitment & PIP negative-path reminders

// Offer expiry sweep: remind unanswered candidates (+ hiring manager) as the
// portal window closes, and flag offers that lapsed unanswered: daily 08:30 NZ
app(Schedule::class)
    ->job(new SendOfferExpiryRemindersJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:30');

// PIP lifecycle sweep: overdue milestones + plans ending within 7 days that
// still need an outcome recorded: daily 08:30 NZ
app(Schedule::class)
    ->job(new SendPipRemindersJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:30');

// ── Wellbeing & engagement sweep (audit fixes 2026-07-02) ──────────────────

// Auto-close published engagement surveys past their end date + one-time
// follow-up reminders for due wellbeing check-ins: daily at 08:00 NZ
app(Schedule::class)
    ->job(new SendWellbeingRemindersJob)
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:00')
    ->withoutOverlapping();

// ── Probation review sweep (audit fixes round 2, 2026-07-02) ────────────────

// Probation end dates within 14 days (or already past) with no concluding
// probation review on file → notify the employee's manager (fallback:
// provider managers). Deduped via hr_employee_profiles.probation_reminder_sent_at,
// which storeProbation clears when an extension moves the end date: daily 08:45 NZ
app(Schedule::class)
    ->command('hr:probation-reminders')
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:45')
    ->withoutOverlapping();
