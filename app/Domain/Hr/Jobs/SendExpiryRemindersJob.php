<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Notifications\ComplianceExpiryNotification;
use App\Models\User;
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
        $now = now();

        // TODO: Query HrStaffComplianceStatus records where expires_at falls on
        // one of the configured reminder-day thresholds. For each match, check
        // that a reminder hasn't already been sent for this interval (use a
        // last_reminded_at or reminders_sent JSON column to track).
        //
        // Categories to check:
        // - Credentials (qualifications, certifications)
        // - Training records (mandatory training expiry)
        // - Vetting / DBS checks
        // - Professional licences / registrations
        //
        // For each expiring item:
        //   $user = User::find($record->user_id);
        //   $user->notify(new ComplianceExpiryNotification($user, [
        //       'name'             => $record->requirement_name,
        //       'requirement_code' => $record->requirement_code,
        //       'expires_at'       => $record->expires_at,
        //   ]));
        //
        // Also notify the user's line manager if configured.

        Log::info('SendExpiryRemindersJob: Expiry reminder check completed.', [
            'tenant_id'     => $this->tenantId,
            'reminder_days' => $reminderDays,
        ]);
    }
}
