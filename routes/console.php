<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Console\Scheduling\Schedule;
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
