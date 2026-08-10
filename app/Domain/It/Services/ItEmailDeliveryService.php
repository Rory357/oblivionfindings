<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Notifications\ItProvisioningCancelledNotification;
use App\Domain\It\Contracts\TracksItEmailDelivery;
use App\Models\ItEmailDelivery;
use App\Models\User;
use App\Notifications\It\TicketApprovalNotification;
use App\Notifications\It\TicketAssignedNotification;
use App\Notifications\It\TicketCreatedNotification;
use App\Notifications\It\TicketReopenedNotification;
use App\Notifications\It\TicketRepliedNotification;
use App\Notifications\It\TicketResolvedNotification;
use App\Notifications\It\TicketSlaNotification;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use Throwable;

class ItEmailDeliveryService
{
    public function __construct(private readonly ItWorkAccessService $workAccess) {}

    /**
     * Queue one notification per recipient so provider callbacks have a stable,
     * unique delivery identity. The notification channel remains canonical.
     */
    public function send(iterable|User $recipients, Notification&TracksItEmailDelivery $notification): void
    {
        $users = $recipients instanceof User ? collect([$recipients]) : collect($recipients);
        foreach ($users->filter(fn ($recipient) => $recipient instanceof User) as $recipient) {
            $copy = clone $notification;
            $copy->id = (string) Str::uuid();
            $delivery = $this->createDelivery($recipient, $copy);
            try {
                NotificationFacade::send($recipient, $copy);
            } catch (Throwable $exception) {
                $this->markDispatchFailed($delivery, $exception);

                throw $exception;
            }
        }
    }

    public function recordNotificationEvent(NotificationSending|NotificationSent|NotificationFailed $event): void
    {
        if ($event->channel !== 'mail' || ! $event->notification instanceof TracksItEmailDelivery) {
            return;
        }

        $delivery = $this->deliveryFor($event->notification, $event->notifiable);
        if (! $delivery && $event instanceof NotificationSending && $event->notifiable instanceof User) {
            $delivery = $this->createDelivery($event->notifiable, $event->notification);
        }
        if (! $delivery) {
            return;
        }

        if ($event instanceof NotificationSending) {
            if ($delivery->status !== 'queued') {
                return;
            }
            $delivery->forceFill([
                'status' => 'sending',
                'sending_at' => now(),
                'attempt_count' => $delivery->attempt_count + 1,
            ])->save();

            return;
        }

        if ($event instanceof NotificationSent) {
            $messageId = is_object($event->response) && method_exists($event->response, 'getMessageId')
                ? $event->response->getMessageId()
                : null;
            // The provider callback can race ahead of Laravel's local event.
            // Make acceptance conditional so it can never regress a final
            // provider state recorded in the meantime.
            ItEmailDelivery::query()
                ->whereKey($delivery->id)
                ->whereNull('provider_status_at')
                ->whereIn('status', ['queued', 'sending', 'accepted'])
                ->update([
                    'status' => 'accepted',
                    'provider_message_id' => $messageId ?: $delivery->provider_message_id,
                    'accepted_at' => now(),
                    'updated_at' => now(),
                ]);

            return;
        }

        $exception = $event->data['exception'] ?? null;
        $this->markLocalDeliveryFailed(
            $delivery,
            $exception?->getMessage() ?? 'Mail delivery failed.',
        );
    }

    public function recordProviderStatus(
        string $notificationUuid,
        string $status,
        ?string $error = null,
        ?string $providerMessageId = null,
        CarbonInterface|string|null $occurredAt = null,
    ): ItEmailDelivery {
        if (! in_array($status, ['delivered', 'failed', 'bounced'], true)) {
            throw new DomainException('Unsupported provider delivery status.');
        }
        $eventAt = $occurredAt instanceof CarbonInterface
            ? $occurredAt
            : ($occurredAt ? Carbon::parse($occurredAt) : now());

        return DB::transaction(function () use ($notificationUuid, $status, $error, $providerMessageId, $eventAt): ItEmailDelivery {
            $delivery = ItEmailDelivery::query()
                ->where('notification_uuid', $notificationUuid)
                ->lockForUpdate()
                ->firstOrFail();

            // A retry is an immutable terminal marker for the original
            // attempt. Provider callbacks belong on the new attempt instead.
            if ($delivery->status === 'retried') {
                return $delivery;
            }
            // Bounces are terminal. Otherwise the provider's event timestamp,
            // not callback arrival order, decides the latest state.
            if ($delivery->status === 'bounced') {
                return $delivery;
            }
            if ($delivery->provider_status_at) {
                if ($eventAt->lessThan($delivery->provider_status_at)) {
                    return $delivery;
                }

                if ($eventAt->equalTo($delivery->provider_status_at)) {
                    $precedence = ['failed' => 1, 'delivered' => 2, 'bounced' => 3];
                    if (($precedence[$status] ?? 0) <= ($precedence[$delivery->status] ?? 0)) {
                        return $delivery;
                    }
                }
            }

            $delivery->forceFill([
                'status' => $status,
                'provider_message_id' => $providerMessageId ?: $delivery->provider_message_id,
                'provider_status_at' => $eventAt,
                'last_error' => $status === 'delivered' ? null : Str::limit((string) $error, 2000, ''),
                'delivered_at' => $status === 'delivered' ? $eventAt : $delivery->delivered_at,
                'failed_at' => $status === 'failed' ? $eventAt : $delivery->failed_at,
                'bounced_at' => $status === 'bounced' ? $eventAt : $delivery->bounced_at,
            ])->save();

            return $delivery->fresh();
        });
    }

    public function canRetryDelivery(ItEmailDelivery $delivery, User $actor): bool
    {
        $canonical = ItEmailDelivery::query()
            ->with(['ticket', 'provisioningRequest.employeeProfile', 'provisioningRequest.responsibleTeam'])
            ->whereKey($delivery->getKey())
            ->first();

        return $canonical !== null && $this->actorCanRetryLoadedDelivery($canonical, $actor);
    }

    /** @return Builder<ItEmailDelivery> */
    public function visibleQuery(User $actor): Builder
    {
        $approvedSiteIds = $this->workAccess->approvedSiteIds($actor);
        $actorId = (int) $actor->id;

        return ItEmailDelivery::query()->where(function (Builder $visible) use ($actor, $actorId, $approvedSiteIds): void {
            $visible->whereHas('ticket', function (Builder $tickets) use ($actor): void {
                $this->workAccess->applyViewScope($tickets, $actor);
            })->orWhereHas('provisioningRequest', function (Builder $provisioning) use ($actorId, $approvedSiteIds): void {
                $provisioning
                    ->whereHas('employeeProfile', function (Builder $profiles): void {
                        $profiles->where('is_active', true)
                            ->where(fn (Builder $dates): Builder => $dates
                                ->whereNull('start_date')
                                ->orWhereDate('start_date', '<=', today()))
                            ->where(fn (Builder $dates): Builder => $dates
                                ->whereNull('end_date')
                                ->orWhereDate('end_date', '>=', today()));
                    })
                    ->where(function (Builder $responsibility) use ($actorId, $approvedSiteIds): void {
                        $responsibility->where('assigned_to_user_id', $actorId)
                            ->orWhereHas('responsibleTeam', function (Builder $team) use ($actorId): void {
                                $team->where('is_active', true)
                                    ->where(function (Builder $membership) use ($actorId): void {
                                        $membership->where('manager_user_id', $actorId)
                                            ->orWhereHas('members', fn (Builder $members): Builder => $members->whereKey($actorId));
                                    });
                            });

                        if ($approvedSiteIds !== []) {
                            $responsibility->orWhereHas(
                                'employeeProfile',
                                fn (Builder $profile): Builder => $profile->whereIn('primary_site_id', $approvedSiteIds),
                            );
                        }
                    });
            });
        });
    }

    public function retry(
        ItEmailDelivery $delivery,
        User $actor,
    ): ItEmailDelivery {
        $retry = DB::transaction(function () use ($delivery, $actor): ItEmailDelivery {
            $original = ItEmailDelivery::query()
                ->with([
                    'ticket',
                    'provisioningRequest.employeeProfile',
                    'provisioningRequest.responsibleTeam',
                    'recipient',
                    'retryAttempt',
                ])
                ->lockForUpdate()
                ->whereKey($delivery->id)
                ->firstOrFail();
            if (! $this->actorCanRetryLoadedDelivery($original, $actor)) {
                throw new DomainException('This email delivery is not accessible to the current actor.');
            }
            if (! in_array($original->status, ['failed', 'bounced'], true)) {
                throw new DomainException('Only failed or bounced email can be retried.');
            }
            if ($original->retryAttempt) {
                throw new DomainException('This failed delivery has already been retried.');
            }
            if (! $original->recipient) {
                throw new DomainException('The recipient is no longer available.');
            }
            if (! $this->recipientCanReceiveLoadedDelivery($original)) {
                throw new DomainException('The recipient is no longer entitled to this email.');
            }

            $retry = ItEmailDelivery::query()->create([
                'notification_uuid' => (string) Str::uuid(),
                'retry_of_delivery_id' => $original->id,
                'it_ticket_id' => $original->it_ticket_id,
                'it_provisioning_request_id' => $original->it_provisioning_request_id,
                'it_ticket_comment_id' => $original->it_ticket_comment_id,
                'recipient_user_id' => $original->recipient_user_id,
                'recipient_email' => $original->recipient_email,
                'notification_type' => $original->notification_type,
                'notification_context' => $original->notification_context,
                'audience' => $original->audience,
                'subject' => $original->subject,
                'status' => 'queued',
                'retry_count' => $original->retry_count + 1,
                'queued_at' => now(),
            ]);
            $original->forceFill([
                'status' => 'retried',
                'last_retried_by_user_id' => $actor->id,
            ])->save();
            AuditLogger::logOrFail('it.email.delivery.retried', $retry, [
                'ticket_id' => $original->it_ticket_id,
                'site_id' => $original->ticket?->site_id
                    ?? $original->provisioningRequest?->employeeProfile?->primary_site_id,
                'retry_of_delivery_id' => $original->id,
                'retry_count' => $retry->retry_count,
            ]);

            return $retry;
        });

        $retry->loadMissing(['ticket', 'provisioningRequest', 'recipient']);
        if (! $this->recipientCanReceiveLoadedDelivery($retry)) {
            $exception = new DomainException('The recipient is no longer entitled to this email.');
            $this->markDispatchFailed($retry, $exception);

            throw $exception;
        }
        $notification = $this->notificationForRetry($retry);
        $notification->id = $retry->notification_uuid;
        try {
            NotificationFacade::send($retry->recipient, $notification);
        } catch (Throwable $exception) {
            $this->markDispatchFailed($retry, $exception);

            throw $exception;
        }

        return $retry->fresh();
    }

    private function actorCanRetryLoadedDelivery(ItEmailDelivery $delivery, User $actor): bool
    {
        if ($actor->approved_at === null || ! $actor->canDo('it.manage')) {
            return false;
        }

        if ($delivery->ticket) {
            return $this->workAccess->canWork($actor, $delivery->ticket);
        }

        $provisioning = $delivery->provisioningRequest;
        if (! $provisioning) {
            return false;
        }

        $profile = $provisioning->employeeProfile;
        $currentProfile = $profile !== null
            && $profile->is_active
            && ($profile->start_date === null || $profile->start_date->lte(today()))
            && ($profile->end_date === null || $profile->end_date->gte(today()));
        if (! $currentProfile) {
            return false;
        }

        if ((int) $provisioning->assigned_to_user_id === (int) $actor->id) {
            return true;
        }

        $team = $provisioning->responsibleTeam;
        if ($team?->is_active && (
            (int) $team->manager_user_id === (int) $actor->id
            || $team->members()->whereKey($actor->id)->exists()
        )) {
            return true;
        }

        return $profile->primary_site_id !== null
            && in_array(
                (int) $profile->primary_site_id,
                $this->workAccess->approvedSiteIds($actor),
                true,
            );
    }

    private function recipientCanReceiveLoadedDelivery(ItEmailDelivery $delivery): bool
    {
        $recipient = $delivery->recipient;
        if (! $recipient || $recipient->approved_at === null) {
            return false;
        }

        if ($delivery->ticket) {
            return $this->workAccess->canView($recipient, $delivery->ticket);
        }

        return $delivery->provisioningRequest !== null
            && $this->actorCanRetryLoadedDelivery($delivery, $recipient);
    }

    private function createDelivery(
        User $recipient,
        Notification&TracksItEmailDelivery $notification,
    ): ItEmailDelivery {
        $context = $notification->itEmailDeliveryContext();

        return ItEmailDelivery::query()->firstOrCreate(
            [
                'notification_uuid' => $notification->id,
                'recipient_user_id' => $recipient->id,
            ],
            [
                'it_ticket_id' => $context['ticket_id'] ?? null,
                'it_provisioning_request_id' => $context['provisioning_request_id'] ?? null,
                'it_ticket_comment_id' => $context['comment_id'] ?? null,
                'recipient_email' => $recipient->email,
                'notification_type' => $context['type'],
                'notification_context' => $context['retry_context'] ?? [],
                'audience' => $context['audience'] ?? null,
                'subject' => $context['subject'],
                'status' => 'queued',
                'queued_at' => now(),
            ],
        );
    }

    private function notificationForRetry(ItEmailDelivery $delivery): Notification&TracksItEmailDelivery
    {
        $context = $delivery->notification_context ?? [];

        if ($delivery->notification_type === 'it_provisioning_cancelled') {
            $provisioning = $delivery->provisioningRequest;
            $task = HrOnboardingTask::query()->find($context['task_id'] ?? null);
            if (! $provisioning || ! $task) {
                throw new DomainException('The provisioning request or onboarding task is no longer available.');
            }

            return new ItProvisioningCancelledNotification(
                $provisioning,
                $task,
                $context['reason'] ?? null,
            );
        }

        $ticket = $delivery->ticket;
        if (! $ticket) {
            throw new DomainException('The ticket is no longer available.');
        }

        return match ($delivery->notification_type) {
            'ticket_approval' => new TicketApprovalNotification($ticket, (string) ($context['event'] ?? 'requested')),
            'ticket_assigned' => new TicketAssignedNotification($ticket),
            'ticket_created' => new TicketCreatedNotification($ticket, (string) ($context['audience'] ?? 'receipt')),
            'ticket_reopened' => new TicketReopenedNotification($ticket),
            'ticket_replied' => new TicketRepliedNotification($ticket, (string) ($context['audience'] ?? 'requester'), $delivery->it_ticket_comment_id),
            'ticket_resolved' => new TicketResolvedNotification($ticket, (string) ($context['audience'] ?? 'requester')),
            'ticket_sla' => new TicketSlaNotification($ticket, (string) ($context['transition'] ?? 'at_risk'), $context['clock'] ?? null),
            default => throw new DomainException('This notification type cannot be retried safely.'),
        };
    }

    private function markDispatchFailed(ItEmailDelivery $delivery, Throwable $exception): void
    {
        $this->markLocalDeliveryFailed($delivery, $exception->getMessage());
    }

    private function markLocalDeliveryFailed(ItEmailDelivery $delivery, string $message): void
    {
        $delivery->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'last_error' => Str::limit($message, 2000, ''),
        ])->save();
    }

    private function deliveryFor(TracksItEmailDelivery $notification, mixed $notifiable): ?ItEmailDelivery
    {
        $query = ItEmailDelivery::query()->where('notification_uuid', $notification->id);
        if ($notifiable instanceof User) {
            $query->where('recipient_user_id', $notifiable->id);
        }

        return $query->first();
    }
}
