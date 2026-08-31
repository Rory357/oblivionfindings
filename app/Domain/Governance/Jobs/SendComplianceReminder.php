<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Models\ComplianceReminder;
use App\Domain\Governance\Notifications\ComplianceReminderNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class SendComplianceReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ComplianceReminder $reminder
    ) {}

    public function handle(): void
    {
        $obligation = $this->reminder->obligation;
        if (! $obligation) {
            return;
        }

        $recipientIds = collect((array) ($this->reminder->notified_users ?? []))
            ->map(fn (mixed $recipientId): ?int => $this->canonicalRecipientId($recipientId))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($recipientIds === []) {
            return;
        }

        $today = Carbon::today();

        User::query()
            ->whereIn('id', $recipientIds)
            ->whereNotNull('approved_at')
            ->with([
                'hrEmployeeProfile' => fn ($profile) => $profile->withTrashed(),
                'permissionOverrides',
                'roles.permissions',
            ])
            ->get()
            ->each(function (User $user) use ($obligation, $today): void {
                $profile = $user->hrEmployeeProfile;

                if ($profile && (
                    $profile->trashed()
                    || ! $profile->is_active
                    || ! $profile->start_date
                    || $profile->start_date->startOfDay()->gt($today)
                    || ($profile->end_date && $profile->end_date->startOfDay()->lt($today))
                )) {
                    return;
                }

                if (! $user->canDo('governance.compliance.view')
                    || Gate::forUser($user)->denies('view', $obligation)) {
                    return;
                }

                $user->notify(new ComplianceReminderNotification($this->reminder, $obligation));
            });
    }

    private function canonicalRecipientId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 && (string) $id === $value ? $id : null;
    }
}
