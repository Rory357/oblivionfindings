<?php

namespace App\Console\Commands\Hr;

use App\Domain\Hr\Models\HrComplianceRenewalSnooze;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Notifications\WorkerComplianceExpiryNotification;
use App\Domain\Hr\Services\HrComplianceRenewalSnoozePruner;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendWorkerComplianceExpiryRemindersCommand extends Command
{
    protected $signature = 'hr:send-worker-compliance-expiry-reminders {--days=30 : Reminder window in days}';

    protected $description = 'Send and stamp worker police-vetting and driver-licence expiry reminders';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $currentStaff = app(HrCurrentStaffService::class);
        $currentUserIds = $currentStaff->currentUserIds();
        $pruned = app(HrComplianceRenewalSnoozePruner::class)->prune();
        $sent = $this->sendBackgroundCheckReminders($days, $currentStaff, $currentUserIds)
            + $this->sendDriverLicenceReminders($days, $currentStaff, $currentUserIds);

        $this->info("Worker compliance expiry reminders sent: {$sent}");
        $this->line("Expired or orphaned renewal snoozes pruned: {$pruned}");

        return self::SUCCESS;
    }

    /** @param array<int, int> $currentUserIds */
    private function sendBackgroundCheckReminders(
        int $days,
        HrCurrentStaffService $currentStaff,
        array $currentUserIds,
    ): int {
        $sent = 0;
        $ids = StaffBackgroundCheck::query()
            ->whereIn('user_id', $currentUserIds)
            ->whereNull('renewal_reminder_sent_at')
            ->whereNotIn('id', HrComplianceRenewalSnooze::query()
                ->select('entity_id')
                ->forEntityType(HrComplianceRenewalSnooze::TYPE_VETTING)
                ->active())
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', today())
            ->whereDate('expires_at', '<=', today()->addDays($days))
            ->pluck('id');

        foreach ($ids as $id) {
            $check = DB::transaction(function () use ($id, $currentStaff): ?StaffBackgroundCheck {
                $check = StaffBackgroundCheck::query()
                    ->with('user:id,name,email')
                    ->lockForUpdate()
                    ->find($id);

                if (! $check
                    || $check->renewal_reminder_sent_at
                    || ! $check->user
                    || ! $currentStaff->isCurrent((int) $check->user_id)
                    || $this->isActivelySnoozed(HrComplianceRenewalSnooze::TYPE_VETTING, (int) $check->id)
                ) {
                    return null;
                }

                if ($this->hasExistingReminder($check->user, 'background_check', (int) $check->id)) {
                    $check->update(['renewal_reminder_sent_at' => now()]);

                    return null;
                }

                $check->update(['renewal_reminder_sent_at' => now()]);

                return $check;
            }, 3);

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
                StaffBackgroundCheck::query()
                    ->whereKey($check->id)
                    ->update(['renewal_reminder_sent_at' => null]);
                $this->warn("Background-check reminder {$check->id} failed: {$exception->getMessage()}");
            }
        }

        return $sent;
    }

    /** @param array<int, int> $currentUserIds */
    private function sendDriverLicenceReminders(
        int $days,
        HrCurrentStaffService $currentStaff,
        array $currentUserIds,
    ): int {
        $sent = 0;
        $ids = HrDriverEligibility::query()
            ->whereIn('user_id', $currentUserIds)
            ->whereNull('licence_expiry_reminder_sent_at')
            ->whereNotIn('id', HrComplianceRenewalSnooze::query()
                ->select('entity_id')
                ->forEntityType(HrComplianceRenewalSnooze::TYPE_DRIVER)
                ->active())
            ->whereNotNull('licence_expires_at')
            ->whereDate('licence_expires_at', '>=', today())
            ->whereDate('licence_expires_at', '<=', today()->addDays($days))
            ->pluck('id');

        foreach ($ids as $id) {
            $eligibility = DB::transaction(function () use ($id, $currentStaff): ?HrDriverEligibility {
                $eligibility = HrDriverEligibility::query()
                    ->with('user:id,name,email')
                    ->lockForUpdate()
                    ->find($id);

                if (! $eligibility
                    || $eligibility->licence_expiry_reminder_sent_at
                    || ! $eligibility->user
                    || ! $currentStaff->isCurrent((int) $eligibility->user_id)
                    || $this->isActivelySnoozed(HrComplianceRenewalSnooze::TYPE_DRIVER, (int) $eligibility->id)
                ) {
                    return null;
                }

                if ($this->hasExistingReminder($eligibility->user, 'driver_licence', (int) $eligibility->id)) {
                    $eligibility->update(['licence_expiry_reminder_sent_at' => now()]);

                    return null;
                }

                $eligibility->update(['licence_expiry_reminder_sent_at' => now()]);

                return $eligibility;
            }, 3);

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
                HrDriverEligibility::query()
                    ->whereKey($eligibility->id)
                    ->update(['licence_expiry_reminder_sent_at' => null]);
                $this->warn("Driver-licence reminder {$eligibility->id} failed: {$exception->getMessage()}");
            }
        }

        return $sent;
    }

    private function isActivelySnoozed(string $entityType, int $entityId): bool
    {
        return HrComplianceRenewalSnooze::query()
            ->forEntity($entityType, $entityId)
            ->active()
            ->lockForUpdate()
            ->exists();
    }

    private function hasExistingReminder(User $user, string $sourceType, int $sourceId): bool
    {
        return $user->notifications()
            ->where('type', WorkerComplianceExpiryNotification::class)
            ->where('data->source_type', $sourceType)
            ->where('data->source_id', $sourceId)
            ->exists();
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
