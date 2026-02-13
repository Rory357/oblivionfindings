<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\DetectFleetOfflineDevices;
use App\Jobs\PruneFleetTelemetry;
use App\Jobs\PruneAssetTelemetry;
use App\Jobs\ProcessControlRoomSignals;
use App\Jobs\CheckControlRoomSlaBreaches;
use App\Jobs\AutoEscalateControlRoomQueues;
use App\Jobs\SendEventReminderJob;
use App\Jobs\ChecklistDueJob;
use App\Jobs\InspectionDueJob;
use App\Jobs\HazardOverdueJob;
use App\Domain\Hr\Jobs\EvaluateComplianceMatrixJob;
use App\Domain\Hr\Jobs\SendExpiryRemindersJob;
use App\Domain\Hr\Jobs\CalculateWellbeingIndicatorsJob;
use App\Domain\Hr\Jobs\ProcessLeaveBalanceAccrualJob;
use App\Domain\Hr\Jobs\ArchiveCandidateDataJob;

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

// High severity incidents that have not been reviewed: hourly (internal ops)
app(Schedule::class)
    ->command('incidents:remind-high-unreviewed')
    ->timezone('Pacific/Auckland')
    ->hourly();

// Escalation engine: re-notify pending items based on admin-configured rules
app(Schedule::class)
    ->command('notifications:escalate')
    ->timezone('Pacific/Auckland')
    ->everyTenMinutes();

// Fleet device offline detection
app(Schedule::class)
    ->job(new DetectFleetOfflineDevices())
    ->timezone('Pacific/Auckland')
    ->everyFiveMinutes();

// Fleet telemetry retention cleanup
app(Schedule::class)
    ->job(new PruneFleetTelemetry())
    ->timezone('Pacific/Auckland')
    ->dailyAt('02:00');

// Asset telemetry retention cleanup
app(Schedule::class)
    ->job(new PruneAssetTelemetry())
    ->timezone('Pacific/Auckland')
    ->dailyAt('02:30');

// Control Room signal processing
app(Schedule::class)
    ->job(new ProcessControlRoomSignals())
    ->timezone('Pacific/Auckland')
    ->everyMinute();

// Control Room SLA breach checks
app(Schedule::class)
    ->job(new CheckControlRoomSlaBreaches())
    ->timezone('Pacific/Auckland')
    ->everyFiveMinutes();

// Control Room auto escalation between queues
app(Schedule::class)
    ->job(new AutoEscalateControlRoomQueues())
    ->timezone('Pacific/Auckland')
    ->everyTenMinutes();

// Sites Module Scheduled Jobs

// Event reminders: check every 5 minutes for upcoming events
app(Schedule::class)
    ->job(new SendEventReminderJob())
    ->timezone('Pacific/Auckland')
    ->everyFiveMinutes();

// Checklist due reminders and overdue checks: daily at 08:00
app(Schedule::class)
    ->job(new ChecklistDueJob())
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:00');

// Inspection due reminders: daily at 08:30
app(Schedule::class)
    ->job(new InspectionDueJob())
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:30');

// Hazard overdue checks and escalations: daily at 09:00
app(Schedule::class)
    ->job(new HazardOverdueJob())
    ->timezone('Pacific/Auckland')
    ->dailyAt('09:00');

// HR Module Scheduled Jobs

// Evaluate compliance matrix for all employees: daily at 01:00
app(Schedule::class)
    ->job(new EvaluateComplianceMatrixJob())
    ->timezone('Pacific/Auckland')
    ->dailyAt('01:00');

// Calculate wellbeing indicators (fatigue, overtime): daily at 02:00
app(Schedule::class)
    ->job(new CalculateWellbeingIndicatorsJob())
    ->timezone('Pacific/Auckland')
    ->dailyAt('02:00');

// Send expiry reminders (credentials, training, vetting): daily at 08:00
app(Schedule::class)
    ->job(new SendExpiryRemindersJob())
    ->timezone('Pacific/Auckland')
    ->dailyAt('08:00');

// Leave balance accrual: monthly on the 1st at 00:30
app(Schedule::class)
    ->job(new ProcessLeaveBalanceAccrualJob())
    ->timezone('Pacific/Auckland')
    ->monthlyOn(1, '00:30');

// Archive expired candidate data per retention policy: weekly Sunday 03:00
app(Schedule::class)
    ->job(new ArchiveCandidateDataJob())
    ->timezone('Pacific/Auckland')
    ->weeklyOn(0, '03:00');
