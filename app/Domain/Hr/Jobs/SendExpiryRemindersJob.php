<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrComplianceRenewalSnooze;
use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Notifications\DocumentExpiryNotification;
use App\Domain\Hr\Notifications\PolicyAttestationRequiredNotification;
use App\Domain\Hr\Notifications\SignatureReminderNotification;
use App\Domain\Hr\Notifications\VisaExpiryNotification;
use App\Domain\Hr\Services\HrComplianceReminderDeliveryService;
use App\Domain\Hr\Services\HrComplianceRenewalSnoozePruner;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendExpiryRemindersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DEFAULT_REMINDER_DAYS = [90, 60, 30, 14, 7];

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'hr-application-expiry-reminders';
    }

    public function handle(): void
    {
        $pruned = app(HrComplianceRenewalSnoozePruner::class)->prune();
        $currentUserIds = app(HrCurrentStaffService::class)->currentUserIds();
        $currentUserLookup = array_fill_keys($currentUserIds, true);
        $reminderDays = $this->reminderDays();
        $sentCount = 0;

        foreach ($reminderDays as $days) {
            $targetDate = now()->addDays($days)->toDateString();

            HrStaffComplianceStatus::query()
                ->with(['user:id,name,email', 'requirement:id,code,name,renewal_reminder_days'])
                ->whereIn('user_id', $currentUserIds)
                ->whereNotIn('id', HrComplianceRenewalSnooze::query()
                    ->select('entity_id')
                    ->forEntityType(HrComplianceRenewalSnooze::TYPE_COMPLIANCE)
                    ->active())
                ->whereDate('expires_at', $targetDate)
                ->whereIn('status', ['compliant', 'expiring_soon', 'not_started', 'non_compliant'])
                ->chunkById(200, function ($records) use ($days, &$sentCount): void {
                    foreach ($records as $record) {
                        $user = $record->user;
                        if (! $user || ! $record->requirement) {
                            continue;
                        }

                        $payload = [
                            'name' => $record->requirement->name,
                            'requirement_code' => $record->requirement->code,
                            'expires_at' => optional($record->expires_at)->toDateString(),
                            'reminder_days' => $days,
                        ];

                        try {
                            $deliveries = app(HrComplianceReminderDeliveryService::class);
                            $delivery = $deliveries->stageExpiry($record, $user, $payload, $days);
                            $deliveries->queue($delivery);
                            if ($delivery->wasRecentlyCreated) {
                                $sentCount++;
                            }
                        } catch (\Throwable $exception) {
                            Log::warning('Failed to stage compliance expiry notification', [
                                'status_id' => $record->id,
                                'user_id' => $user->id,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }
                });
        }

        Log::info('SendExpiryRemindersJob: Expiry reminder check completed.', [
            'reminder_days' => $reminderDays,
            'sent' => $sentCount,
            'snoozes_pruned' => $pruned,
        ]);

        $this->sendVisaExpiryReminders($reminderDays, $currentUserIds, $currentUserLookup);
        $this->sendDocumentExpiryReminders(max($reminderDays), $currentUserIds, $currentUserLookup);
        $this->sendSignatureDueReminders($currentUserIds);
        $this->sendAttestationOverdueReminders($currentUserIds);
    }

    /** @return array<int, int> */
    private function reminderDays(): array
    {
        $configured = config('hr.expiry_reminder_days', self::DEFAULT_REMINDER_DAYS);
        $days = collect(is_array($configured) ? $configured : self::DEFAULT_REMINDER_DAYS)
            ->filter(fn ($day): bool => is_numeric($day) && (int) $day >= 0)
            ->map(fn ($day): int => (int) $day)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return $days !== [] ? $days : self::DEFAULT_REMINDER_DAYS;
    }

    /**
     * Policy-attestation sweep for current approved staff who have not
     * attested the active version after its grace window.
     *
     * @param  array<int, int>  $currentUserIds
     */
    private function sendAttestationOverdueReminders(array $currentUserIds): void
    {
        $overdueDays = (int) config('hr.policy_attestation_overdue_days', 7);
        $cutoff = now()->subDays($overdueDays);
        $sentCount = 0;

        $policies = HrPolicy::query()
            ->active()
            ->where('requires_attestation', true)
            ->with('currentVersion')
            ->get();

        foreach ($policies as $policy) {
            $version = $policy->currentVersion;
            if (! $version) {
                continue;
            }

            $publishedAt = $version->effective_from ?? $version->created_at;
            if (! $publishedAt || $publishedAt->gt($cutoff)) {
                continue;
            }

            $attestedUserIds = HrPolicyAttestation::query()
                ->where('policy_id', $policy->id)
                ->where(fn ($query) => $query
                    ->where('policy_version_id', $version->id)
                    ->orWhereNull('policy_version_id'))
                ->pluck('user_id')
                ->unique()
                ->all();

            $pendingStaff = User::query()
                ->whereIn('id', $currentUserIds)
                ->whereNotIn('id', $attestedUserIds)
                ->get(['id', 'name', 'email']);

            foreach ($pendingStaff as $member) {
                $alreadyNudged = $member->notifications()
                    ->where('type', PolicyAttestationRequiredNotification::class)
                    ->where('data->policy_version_id', $version->id)
                    ->where('data->kind', 'reminder')
                    ->exists();

                if ($alreadyNudged) {
                    continue;
                }

                try {
                    $member->notify(new PolicyAttestationRequiredNotification([
                        'policy_id' => $policy->id,
                        'policy_version_id' => $version->id,
                        'policy_title' => $policy->title,
                        'version_number' => (int) $version->version_number,
                        'kind' => 'reminder',
                    ]));
                    $sentCount++;
                } catch (\Throwable $exception) {
                    Log::warning('Failed to send policy attestation reminder', [
                        'policy_id' => $policy->id,
                        'user_id' => $member->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        Log::info('SendExpiryRemindersJob: Policy attestation reminder check completed.', [
            'sent' => $sentCount,
        ]);
    }

    /** @param array<int, int> $currentUserIds */
    private function sendSignatureDueReminders(array $currentUserIds): void
    {
        $leadDays = (int) config('hr.signature_reminder_lead_days', 2);
        $cutoff = now()->addDays($leadDays)->toDateString();
        $sentCount = 0;

        HrDocumentSignature::query()
            ->with('document:id,title')
            ->where('status', 'pending')
            ->whereNull('reminder_sent_at')
            ->whereNotNull('due_at')
            ->whereIn('signer_user_id', $currentUserIds)
            ->whereDate('due_at', '<=', $cutoff)
            ->chunkById(200, function ($signatures) use (&$sentCount): void {
                foreach ($signatures as $signature) {
                    $signer = User::find($signature->signer_user_id);
                    if (! $signer) {
                        continue;
                    }

                    try {
                        $signer->notify(new SignatureReminderNotification([
                            'signature_id' => $signature->id,
                            'document_title' => $signature->document?->title ?? 'a document',
                            'due_at' => optional($signature->due_at)->toDateString(),
                        ]));
                        $signature->update(['reminder_sent_at' => now()]);
                        $sentCount++;
                    } catch (\Throwable $exception) {
                        Log::warning('Failed to send signature reminder', [
                            'signature_id' => $signature->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        Log::info('SendExpiryRemindersJob: Signature-due reminder check completed.', [
            'sent' => $sentCount,
        ]);
    }

    /**
     * @param  array<int, int>  $currentUserIds
     * @param  array<int, bool>  $currentUserLookup
     */
    private function sendDocumentExpiryReminders(
        int $windowDays,
        array $currentUserIds,
        array $currentUserLookup,
    ): void {
        $sentCount = 0;
        $cutoff = now()->addDays($windowDays)->toDateString();
        $today = now()->toDateString();

        HrDocument::query()
            ->with(['employeeProfile.user:id,name,email', 'employeeProfile.manager:id,name,email'])
            ->whereHas('employeeProfile', fn ($profile) => $profile->whereIn('user_id', $currentUserIds))
            ->whereNotNull('expires_at')
            ->where('expiry_reminder_sent', false)
            ->whereDate('expires_at', '<=', $cutoff)
            ->whereDate('expires_at', '>=', $today)
            ->chunkById(200, function ($documents) use (&$sentCount, $currentUserLookup): void {
                foreach ($documents as $document) {
                    $payload = [
                        'document_id' => $document->id,
                        'title' => $document->title,
                        'expires_at' => optional($document->expires_at)->toDateString(),
                        'reminder_days' => now()->diffInDays($document->expires_at, false),
                    ];

                    $recipients = collect([
                        $document->employeeProfile?->user,
                        $document->employeeProfile?->manager,
                    ])->filter(fn ($recipient) => $recipient
                        && isset($currentUserLookup[(int) $recipient->id]))
                        ->unique('id');
                    $dispatched = false;

                    foreach ($recipients as $recipient) {
                        try {
                            $recipient->notify(new DocumentExpiryNotification($payload));
                            $sentCount++;
                            $dispatched = true;
                        } catch (\Throwable $exception) {
                            Log::warning('Failed to send document expiry notification', [
                                'document_id' => $document->id,
                                'recipient_id' => $recipient->id,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }

                    if ($dispatched) {
                        $document->update(['expiry_reminder_sent' => true]);
                    }
                }
            });

        Log::info('SendExpiryRemindersJob: Document expiry reminder check completed.', [
            'sent' => $sentCount,
        ]);
    }

    /**
     * @param  array<int, int>  $reminderDays
     * @param  array<int, int>  $currentUserIds
     * @param  array<int, bool>  $currentUserLookup
     */
    private function sendVisaExpiryReminders(
        array $reminderDays,
        array $currentUserIds,
        array $currentUserLookup,
    ): void {
        $sentCount = 0;

        foreach ($reminderDays as $days) {
            $targetDate = now()->addDays($days)->toDateString();

            HrEmployeeProfile::query()
                ->with(['user:id,name,email', 'manager:id,name,email'])
                ->whereIn('user_id', $currentUserIds)
                ->whereDate('visa_expires_at', $targetDate)
                ->chunkById(200, function ($profiles) use ($days, &$sentCount, $currentUserLookup): void {
                    foreach ($profiles as $profile) {
                        $payload = [
                            'profile_id' => $profile->id,
                            'employee_name' => $profile->user?->name ?? 'Staff member',
                            'visa_type' => $profile->visa_type,
                            'expires_at' => $profile->visa_expires_at->toDateString(),
                            'reminder_days' => $days,
                        ];

                        $recipients = collect([$profile->user, $profile->manager])
                            ->filter(fn ($recipient) => $recipient
                                && isset($currentUserLookup[(int) $recipient->id]))
                            ->unique('id');

                        foreach ($recipients as $recipient) {
                            $alreadySent = $recipient->notifications()
                                ->where('type', VisaExpiryNotification::class)
                                ->where('data->profile_id', $profile->id)
                                ->where('data->expires_at', $payload['expires_at'])
                                ->where('data->reminder_days', $days)
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
            'sent' => $sentCount,
        ]);
    }
}
