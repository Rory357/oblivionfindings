<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Notifications\ComplianceExpiryNotification;
use App\Domain\Hr\Notifications\VisaExpiryNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendExpiryRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Reminder intervals in days before expiry.
     * Configurable via config('hr.expiry_reminder_days').
     */
    private array $defaultReminderDays = [90, 60, 30, 14, 7];

    public function __construct(
        public ?int $tenantId = null
    ) {}

    public function handle(): void
    {
        $reminderDays = config('hr.expiry_reminder_days', $this->defaultReminderDays);
        $sentCount = 0;

        foreach ($reminderDays as $days) {
            $targetDate = now()->addDays((int) $days)->toDateString();

            $query = HrStaffComplianceStatus::query()
                ->with(['user:id,name,email', 'requirement:id,code,name,renewal_reminder_days'])
                ->whereDate('expires_at', $targetDate)
                ->whereIn('status', ['compliant', 'expiring_soon', 'not_started', 'non_compliant']);

            if ($this->tenantId !== null) {
                $query->where('tenant_id', $this->tenantId);
            }

            $query->chunkById(200, function ($records) use ($days, &$sentCount) {
                foreach ($records as $record) {
                    $user = $record->user;
                    if (! $user || ! $record->requirement) {
                        continue;
                    }

                    $alreadySent = $user->notifications()
                        ->where('type', ComplianceExpiryNotification::class)
                        ->where('data->requirement_code', $record->requirement->code)
                        ->where('data->expires_at', optional($record->expires_at)->toDateString())
                        ->where('data->reminder_days', (int) $days)
                        ->exists();

                    if ($alreadySent) {
                        continue;
                    }

                    $payload = [
                        'name' => $record->requirement->name,
                        'requirement_code' => $record->requirement->code,
                        'expires_at' => optional($record->expires_at)->toDateString(),
                        'reminder_days' => (int) $days,
                    ];

                    try {
                        $user->notify(new ComplianceExpiryNotification($user, $payload));
                        $sentCount++;
                    } catch (\Throwable $exception) {
                        Log::warning('Failed to send compliance expiry notification', [
                            'status_id' => $record->id,
                            'user_id' => $user->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });
        }

        Log::info('SendExpiryRemindersJob: Expiry reminder check completed.', [
            'tenant_id'     => $this->tenantId,
            'reminder_days' => $reminderDays,
            'sent' => $sentCount,
        ]);

        $this->sendVisaExpiryReminders($reminderDays);
    }

    /**
     * Right-to-work expiry sweep: at each reminder interval, notify the
     * worker and their manager that the recorded visa is about to lapse.
     */
    private function sendVisaExpiryReminders(array $reminderDays): void
    {
        $sentCount = 0;

        foreach ($reminderDays as $days) {
            $targetDate = now()->addDays((int) $days)->toDateString();

            $query = HrEmployeeProfile::query()
                ->with(['user:id,name,email', 'manager:id,name,email'])
                ->where('is_active', true)
                ->whereDate('visa_expires_at', $targetDate);

            if ($this->tenantId !== null) {
                $query->where('tenant_id', $this->tenantId);
            }

            $query->chunkById(200, function ($profiles) use ($days, &$sentCount) {
                foreach ($profiles as $profile) {
                    $payload = [
                        'profile_id' => $profile->id,
                        'employee_name' => $profile->user?->name ?? 'Staff member',
                        'visa_type' => $profile->visa_type,
                        'expires_at' => $profile->visa_expires_at->toDateString(),
                        'reminder_days' => (int) $days,
                    ];

                    $recipients = collect([$profile->user, $profile->manager])
                        ->filter()
                        ->unique('id');

                    foreach ($recipients as $recipient) {
                        $alreadySent = $recipient->notifications()
                            ->where('type', VisaExpiryNotification::class)
                            ->where('data->profile_id', $profile->id)
                            ->where('data->expires_at', $payload['expires_at'])
                            ->where('data->reminder_days', (int) $days)
                            ->exists();

                        if ($alreadySent) {
                            continue;
                        }

                        try {
                            $recipient->notify(new VisaExpiryNotification($payload));
                            $sentCount++;
                        } catch (\Throwable $exception) {
                            Log::warning('Failed to send visa expiry notification', [
                                'profile_id' => $profile->id,
                                'recipient_id' => $recipient->id,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }
                }
            });
        }

        Log::info('SendExpiryRemindersJob: Visa expiry reminder check completed.', [
            'tenant_id' => $this->tenantId,
            'sent' => $sentCount,
        ]);
    }
}
