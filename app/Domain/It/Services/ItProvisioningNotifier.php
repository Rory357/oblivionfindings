<?php

namespace App\Domain\It\Services;

use App\Models\ItEmailDelivery;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningWorkflow;
use App\Models\User;
use App\Notifications\It\ProvisioningUpdateNotification;
use Illuminate\Support\Collection;

/**
 * Chooses who hears about a provisioning change and rechecks that choice
 * when the queued message is actually sent. Recipients are resolved from the
 * canonical record at both moments; nothing is sent to an actor about their
 * own action, and nobody outside the current access boundary is retained.
 */
final class ItProvisioningNotifier
{
    public function __construct(
        private readonly ItEmailDeliveryService $deliveries,
        private readonly ItProvisioningAccessService $access,
        private readonly ItProvisioningResponsibilityService $responsibility,
    ) {}

    /** @param  iterable<User>  $approvers */
    public function approvalRequested(ItProvisioningRequest $task, iterable $approvers): void
    {
        $this->dispatch($task, 'approval_requested', collect($approvers)->mapWithKeys(fn (User $user) => [$user->id => 'approver']));
    }

    public function approvalDecided(ItProvisioningRequest $task, string $decision, User $actor): void
    {
        $this->dispatch($task, $decision, $this->requesterAudience($task)->except([$actor->id]));
    }

    public function approvalExpired(ItProvisioningRequest $task): void
    {
        $recipients = $this->requesterAudience($task);
        $task->loadMissing('workflow');
        foreach (array_filter([$task->workflow?->owner_user_id, $task->workflow?->cover_user_id]) as $ownerId) {
            $recipients->put((int) $ownerId, $recipients->get((int) $ownerId, 'agent'));
        }
        $this->dispatch($task, 'approval_expired', $recipients);
    }

    /** @param  'fulfilled'|'failed'|'retried'|'cancelled'  $event */
    public function taskChanged(ItProvisioningRequest $task, string $event, User $actor): void
    {
        $recipients = $this->requesterAudience($task)->except([$actor->id]);
        if ($event === 'failed') {
            $task->loadMissing('workflow');
            foreach (array_filter([$task->workflow?->owner_user_id]) as $ownerId) {
                if ((int) $ownerId !== (int) $actor->id) {
                    $recipients->put((int) $ownerId, $recipients->get((int) $ownerId, 'agent'));
                }
            }
        }
        $this->dispatch($task, $event, $recipients);
    }

    public function workflowCompleted(ItProvisioningWorkflow $workflow, User $actor): void
    {
        $anchor = $workflow->requests()->whereNull('reversal_of_request_id')->orderBy('stage')->orderBy('id')->first();
        if (! $anchor) {
            return;
        }
        $recipients = $this->requesterAudience($anchor);
        $workflow->loadMissing('employeeProfile');
        if ($workflow->employeeProfile?->user_id) {
            $recipients->put((int) $workflow->employeeProfile->user_id, 'requester');
        }
        if ($workflow->created_by_user_id) {
            $recipients->put((int) $workflow->created_by_user_id, $recipients->get((int) $workflow->created_by_user_id, 'requester'));
        }
        $this->dispatch($anchor, 'workflow_completed', $recipients->except([$actor->id]));
    }

    public function reversalRequested(ItProvisioningWorkflow $workflow, User $actor): void
    {
        $anchor = $workflow->requests()->whereNotNull('reversal_of_request_id')->orderBy('stage')->orderBy('id')->first();
        if (! $anchor) {
            return;
        }
        $recipients = collect(array_filter([$workflow->owner_user_id, $workflow->cover_user_id]))
            ->mapWithKeys(fn ($id) => [(int) $id => 'agent'])->except([$actor->id]);
        $this->dispatch($anchor, 'reversal_requested', $recipients);
    }

    /** Send-time recheck used by the delivery outbox for every queued channel. */
    public function canReceive(ItProvisioningRequest $task, User $recipient, array $context): bool
    {
        if ($recipient->approved_at === null) {
            return false;
        }
        $event = (string) ($context['event'] ?? '');
        $audience = (string) ($context['audience'] ?? '');
        if ($audience === 'approver') {
            if ($event !== 'approval_requested' || in_array($task->status, ['done', 'cancelled'], true)) {
                return false;
            }
            $task->refresh();

            return in_array((int) $recipient->id, [(int) $task->primary_approver_user_id, (int) $task->cover_approver_user_id], true)
                && $task->approval_status === 'pending'
                && $this->responsibility->eligible((int) $recipient->id, $this->access->siteIdFor($task), true) !== null;
        }
        if ($audience === 'agent') {
            return $recipient->canDo('it.manage') && $this->access->canManage($recipient, $task);
        }

        return $this->access->canTrack($recipient, $task) || $this->access->canManage($recipient, $task);
    }

    /** @return Collection<int, string> user id → audience */
    private function requesterAudience(ItProvisioningRequest $task): Collection
    {
        $recipients = collect();
        foreach (array_filter([$task->approval_requested_by, $task->created_by]) as $id) {
            $recipients->put((int) $id, 'requester');
        }
        $requester = $task->catalogSubmissions()->oldest('id')->value('requester_user_id');
        if ($requester) {
            $recipients->put((int) $requester, 'requester');
        }

        return $recipients;
    }

    /** @param  Collection<int, string>  $recipients */
    private function dispatch(ItProvisioningRequest $task, string $event, Collection $recipients): void
    {
        if (! in_array($event, ProvisioningUpdateNotification::EVENTS, true)) {
            return;
        }
        foreach ($recipients->groupBy(fn (string $audience) => $audience, true) as $audience => $ids) {
            $users = User::query()->whereKey($ids->keys()->all())->whereNotNull('approved_at')->get()
                ->filter(fn (User $user) => $this->canReceive($task, $user, ['event' => $event, 'audience' => $audience]));
            if ($users->isEmpty()) {
                continue;
            }
            $this->deliveries->send($users, new ProvisioningUpdateNotification($task, $event, (string) $audience));
        }
    }

    /** Rehydrate a stored delivery for retry. */
    public function forRetry(ItEmailDelivery $delivery): ProvisioningUpdateNotification
    {
        $context = $delivery->notification_context ?? [];
        $task = $delivery->provisioningRequest;
        abort_unless($task !== null, 422);

        return new ProvisioningUpdateNotification($task, (string) ($context['event'] ?? 'launched'), (string) ($context['audience'] ?? 'requester'));
    }
}
