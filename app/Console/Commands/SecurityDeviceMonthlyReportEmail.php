<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Reports\MonthlyReport;
use App\Domain\SecurityDevices\Reports\MonthlyReportEmail;
use App\Notifications\SecurityDevicesMonthlyReportNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * S&D rebuild pg-sdr-18b: on the 1st at 7:00 am NZ time, email last month's report to the people
 * chosen in Overview › Reports (off until someone turns it on). Each email is queued and built as
 * its recipient, so it holds only their own Sites' figures. A month is sent once, however often
 * this runs.
 */
final class SecurityDeviceMonthlyReportEmail extends Command
{
    protected $signature = 'security-devices:monthly-report-email';

    protected $description = 'Email last month’s Security & devices report to the people chosen in Overview › Reports, once per month';

    public function handle(MonthlyReportEmail $email, MonthlyReport $report): int
    {
        if (! $email->on()) {
            $this->info('The monthly report email is off.');

            return self::SUCCESS;
        }
        $now = CarbonImmutable::now('UTC');
        $month = $report->month($now->setTimezone(MonthlyReport::NZ)->startOfMonth()->subMonth()->format('Y-m'), $now);
        if ($now->lt(MonthlyReportEmail::sendTime($month['end']))) {
            $this->info('Not yet: the report goes at 7:00 am NZ time on the 1st.');

            return self::SUCCESS;
        }
        $recipients = $email->recipients();
        if ($recipients->isEmpty()) {
            $this->info('Nobody who may see reports is chosen, so nothing was sent.');

            return self::SUCCESS;
        }
        if (! $email->claim($month['key'])) {
            $this->info("The {$month['label']} report was already sent.");

            return self::SUCCESS;
        }

        $queued = [];
        foreach ($recipients as $user) {
            try {
                $user->notify(new SecurityDevicesMonthlyReportNotification($month['key']));
                $queued[] = (int) $user->id;
            } catch (Throwable $exception) {
                report($exception);
                Log::warning('A monthly Security & devices report email could not be queued', ['user_id' => $user->id, 'month' => $month['key']]);
            }
        }
        if ($queued === []) {
            // Nothing went: give the month back so the next hourly run tries again.
            $email->release($month['key']);
            $this->warn("The {$month['label']} report couldn't be queued for anyone; it will be tried again.");

            return self::FAILURE;
        }
        AuditLogger::logOrFail('security_devices.report.emailed', null, ['month' => $month['key'], 'recipient_ids' => $queued], systemActor: true);
        $this->info("Queued the {$month['label']} report for ".count($queued).' '.(count($queued) === 1 ? 'person' : 'people').'.');

        return self::SUCCESS;
    }
}
