<?php

namespace App\Console\Commands\Hr;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Notifications\WorkerComplianceExpiryNotification;
use App\Models\StaffBackgroundCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendWorkerComplianceExpiryRemindersCommand extends Command
{
    protected $signature = 'hr:send-worker-compliance-expiry-reminders {--days=30 : Reminder window in days}';

    protected $description = 'Send and stamp worker police-vetting and driver-licence expiry reminders';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $sent = $this->sendBackgroundCheckReminders($days)
            + $this->sendDriverLicenceReminders($days);

        $this->info("Worker compliance expiry reminders sent: {$sent}");

        return self::SUCCESS;
    }

    private function sendBackgroundCheckReminders(int $days): int
    {
        $sent = 0;
        $ids = StaffBackgroundCheck::query()
            ->whereNull('renewal_reminder_sent_at')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', today())
            ->whereDate('expires_at', '<=', today()->addDays($days))
            ->pluck('id');

        foreach ($ids as $id) {
            $check = DB::transaction(function () use ($id): ?StaffBackgroundCheck {
                $check = StaffBackgroundCheck::query()
                    ->with('user:id,name,email')
                    ->lockForUpdate()
                    ->find($id);

                if (! $check || $check->renewal_reminder_sent_at || ! $check->user) {
                    return null;
                }

                $check->update(['renewal_reminder_sent_at' => now()]);

                return $check;
            });

            if (! $check) {
                continue;
            }

            try {
                $check->user->notify(new WorkerComplianceExpiryNotification([
                    'source_type' => 'background_check',
                    'source_id' => $check->id,
                    'title' => $this->backgroundCheckTitle($check->check_type),
                    'expires_at' => $check->expires_at->toDateString(),
                ]));
                $sent++;
            } catch (\Throwable $exception) {
                $check->update(['renewal_reminder_sent_at' => null]);
                $this->warn("Background-check reminder {$check->id} failed: {$exception->getMessage()}");
            }
        }

        return $sent;
    }

    private function sendDriverLicenceReminders(int $days): int
    {
        $sent = 0;
        $ids = HrDriverEligibility::query()
            ->whereNull('licence_expiry_reminder_sent_at')
            ->whereNotNull('licence_expires_at')
            ->whereDate('licence_expires_at', '>=', today())
            ->whereDate('licence_expires_at', '<=', today()->addDays($days))
            ->pluck('id');

        foreach ($ids as $id) {
            $eligibility = DB::transaction(function () use ($id): ?HrDriverEligibility {
                $eligibility = HrDriverEligibility::query()
                    ->with('user:id,name,email')
                    ->lockForUpdate()
                    ->find($id);

                if (! $eligibility || $eligibility->licence_expiry_reminder_sent_at || ! $eligibility->user) {
                    return null;
                }

                $eligibility->update(['licence_expiry_reminder_sent_at' => now()]);

                return $eligibility;
            });

            if (! $eligibility) {
                continue;
            }

            try {
                $eligibility->user->notify(new WorkerComplianceExpiryNotification([
                    'source_type' => 'driver_licence',
                    'source_id' => $eligibility->id,
                    'title' => 'driver licence',
                    'expires_at' => $eligibility->licence_expires_at->toDateString(),
                ]));
                $sent++;
            } catch (\Throwable $exception) {
                $eligibility->update(['licence_expiry_reminder_sent_at' => null]);
                $this->warn("Driver-licence reminder {$eligibility->id} failed: {$exception->getMessage()}");
            }
        }

        return $sent;
    }

    private function backgroundCheckTitle(string $type): string
    {
        return match ($type) {
            'police_check' => 'police vetting',
            'right_to_work' => 'right-to-work check',
            default => str_replace('_', ' ', $type),
        };
    }
}
