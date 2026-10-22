<?php

namespace App\Domain\Referrals\Services;

use App\Models\User;
use Illuminate\Support\Collection;

/** Reapply the live referral boundary to initial and delayed task notifications. */
final class ReferralTaskNotifications
{
    public function waitingForOpeningHours(array $payload): bool
    {
        $source = $payload['task_source'] ?? null;
        $id = $payload['task_id'] ?? null;
        if (! in_array($source, ['referral_work', 'referral_next'], true) || ! is_numeric($id) || (int) $id < 1) {
            return false;
        }
        $configuration = app(ReferralFollowUpPolicy::class)->configuration($source, (int) $id);

        return $configuration !== null && ! ReferralFollowUpPolicy::windowOpen($configuration, now());
    }

    public function recipients(Collection $recipients, array $payload): Collection
    {
        $source = $payload['task_source'] ?? null;
        $id = $payload['task_id'] ?? null;
        if (! in_array($source, ['referral_work', 'referral_next'], true)
            || ! is_numeric($id) || (int) $id < 1 || (string) (int) $id !== (string) $id) {
            return collect();
        }
        $queue = app(ReferralWorkQueue::class);
        $configuration = app(ReferralFollowUpPolicy::class)->configuration($source, (int) $id);
        if ($configuration === null || ! ReferralFollowUpPolicy::windowOpen($configuration, now())) {
            return collect();
        }

        return $recipients->filter(function (User $recipient) use ($queue, $source, $id): bool {
            // Never retain a caller's loaded roles or former staff/site relationship.
            $actor = User::find($recipient->id);
            if (! $actor || ! $queue->canView($actor)) {
                return false;
            }
            if ($source === 'referral_work') {
                return $queue->work($actor)->whereKey((int) $id)->where('status', 'open')
                    ->where('due_at', '<', now())->whereHas('referral', fn ($query) => $query->where('stage', '!=', 'closed'))->exists();
            }

            return $queue->nextActions($actor)->whereKey((int) $id)->where('next_due_at', '<', now())->exists();
        })->values();
    }
}
