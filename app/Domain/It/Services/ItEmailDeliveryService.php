<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Notifications\ItProvisioningCancelledNotification;
use App\Domain\It\Contracts\TracksItEmailDelivery;
use App\Jobs\DispatchItTicketNotifications;
use App\Mail\MailNotSubmitted;
use App\Mail\MailSubmissionRejected;
use App\Models\AppSetting;
use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\User;
use App\Notifications\It\EmailConfigurationTestNotification;
use App\Notifications\It\TicketApprovalNotification;
use App\Notifications\It\TicketAssignedNotification;
use App\Notifications\It\TicketCreatedNotification;
use App\Notifications\It\TicketReopenedNotification;
use App\Notifications\It\TicketRepliedNotification;
use App\Notifications\It\TicketResolvedNotification;
use App\Notifications\It\TicketSlaNotification;
use App\Services\AuditLogger;
use App\Services\EmailConfiguration;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\SentMessage as LaravelSentMessage;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ItEmailDeliveryService
{
    public const int MAX_PROVIDER_FUTURE_SKEW_SECONDS = 300;

    private const string UNKNOWN_DELIVERY_ERROR = 'Delivery outcome is unknown. Reconcile the provider result before retrying.';

    private const string REVOKED_UNCERTAIN_DELIVERY_ERROR = 'Further delivery stopped because the recipient no longer has access. The earlier sending outcome is unknown; reconcile the provider result before retrying.';

    public function __construct(private readonly ItWorkAccessService $workAccess) {}

    /** Record the submitted RFC identity before invoking the transport. */
    public function prepareMessageIdentity(MessageSending $event): void
    {
        $uuid = $event->data['__laravel_notification_id'] ?? null;
        if (! is_string($uuid) || ! Str::isUuid($uuid)) {
            throw new DomainException('IT mail requires its recorded notification identity.');
        }
        $recipients = array_map(fn ($address) => $address->getAddress(), $event->message->getTo());
        $id = DB::transaction(function () use ($uuid, $recipients, $event): string {
            $rows = ItEmailDelivery::query()->where('notification_uuid', $uuid)
                ->whereIn('recipient_email', $recipients)->lockForUpdate()->limit(2)->get();
            if ($rows->count() !== 1) {
                throw new DomainException('IT mail must identify one recorded recipient delivery.');
            }
            $delivery = $rows->sole();
            if (! in_array($delivery->status, ['sending', 'accepted', 'delivered'], true)
                || ! $this->recipientCanReceiveLoadedDelivery($delivery)) {
                throw new DomainException('IT mail delivery is no longer authorized.');
            }
            if (array_key_exists('__it_email_submission_snapshot', $event->data)) {
                // The canonical channel provides only nonsecret attempt facts.
                // Final headers are available here, after the mailer applied
                // its actual sender. Reserve them with the RFC identity before
                // transport, and never reinterpret an earlier attempt later.
                if ($delivery->status !== 'sending' || $delivery->provider_status_at !== null
                    || $delivery->rfc_message_id !== null
                    || isset($delivery->notification_context['submission_snapshot'])) {
                    throw new DomainException('This email attempt already has submission evidence. Reconcile its recorded outcome.');
                }
                $snapshot = $event->data['__it_email_submission_snapshot'];
                $snapshot['from'] = array_map(fn ($address) => ['address' => $address->getAddress(), 'name' => $address->getName()], $event->message->getFrom());
                $snapshot['reply_to'] = array_map(fn ($address) => ['address' => $address->getAddress(), 'name' => $address->getName()], $event->message->getReplyTo());
                $delivery->forceFill([
                    'provider' => $snapshot['transport'],
                    'notification_context' => array_merge($delivery->notification_context ?? [], ['submission_snapshot' => $snapshot]),
                ]);
            }
            if ($delivery->rfc_message_id !== null) {
                return $delivery->rfc_message_id;
            }
            $headers = $event->message->getHeaders();
            $parser = new ItEmailMessageIdentifiers;
            $id = $parser->messageId($headers->get('Message-ID')?->getBodyAsString()
                ?? '<'.$event->message->generateMessageId().'>');
            if ($id === null) {
                throw new DomainException('IT mail requires a valid outgoing message identity.');
            }
            $delivery->forceFill(['rfc_message_id' => $id, 'rfc_message_id_hash' => $parser->hash($id),
                'rfc_message_id_recorded_at' => now()])->save();
            AuditLogger::logOrFail('it.email.message_identity_recorded', $delivery, [
                'ticket_id' => $delivery->it_ticket_id, 'recipient_user_id' => $delivery->recipient_user_id,
            ]);

            return $id;
        });
        $event->message->getHeaders()->remove('Message-ID');
        $event->message->getHeaders()->addIdHeader('Message-ID', substr($id, 1, -1));
    }

    /** Persist inside the originating command transaction; do no provider work. */
    public function prepare(iterable|User $recipients, Notification&TracksItEmailDelivery $notification): void
    {
        $this->prepareDeliveries($recipients, $notification);
    }

    /** @return list<int> */
    private function prepareDeliveries(iterable|User $recipients, Notification&TracksItEmailDelivery $notification): array
    {
        return DB::transaction(function () use ($recipients, $notification): array {
            $users = $recipients instanceof User ? collect([$recipients]) : collect($recipients);
            $ids = [];
            foreach ($users->filter(fn ($recipient) => $recipient instanceof User)->unique('id') as $recipient) {
                $copy = clone $notification;
                $copy->id = (string) Str::uuid();
                $delivery = $this->createDelivery($recipient, $copy);
                $delivery->forceFill(['dispatch_requested_at' => now()])->save();
                $ids[] = (int) $delivery->id;
            }

            return $ids;
        });
    }

    /** Called under the ticket/user locks after membership is removed. */
    public function stopRemovedWatcherDeliveries(ItTicket $ticket, int $watcherId): void
    {
        ItEmailDelivery::query()->where('it_ticket_id', $ticket->id)
            ->where('recipient_user_id', $watcherId)
            ->where('status', 'queued')->whereNull('sending_at')->whereNull('provider_status_at')
            ->where(function (Builder $query): void {
                $query->where('notification_type', 'ticket_reopened')
                    ->orWhere(fn (Builder $reply) => $reply->where('notification_type', 'ticket_replied')->where('audience', 'agent_side'))
                    ->orWhere(fn (Builder $resolution) => $resolution->where('notification_type', 'ticket_resolved')->where('audience', 'watcher'));
            })
            ->orderBy('id')->get()->each(function (ItEmailDelivery $delivery): void {
                if (! $this->recipientCanReceiveLoadedDelivery($delivery)) {
                    // This conditional row lock races safely with the mail
                    // claim. A claimed or accepted send is never called unsent.
                    $this->stopInaccessibleDelivery($delivery);
                }
            });
    }

    /**
     * Recover committed intents even if PHP died before post-response dispatch.
     * A connection-owned lock serializes workers without an expiring lease that
     * could permit a second sender while a slow first sender is still active.
     */
    public function dispatchPending(int $limit = 50, ?int $ticketId = null, ?int $deliveryId = null): int
    {
        $ids = ItEmailDelivery::query()->whereNotNull('dispatch_requested_at')
            ->whereNull('dispatch_finished_at')
            ->when($ticketId !== null, fn (Builder $query) => $query->where('it_ticket_id', $ticketId))
            ->when($deliveryId !== null, fn (Builder $query) => $query->whereKey($deliveryId))
            ->orderBy('id')->limit(max(1, min($limit, 100)))->pluck('id');
        $processed = 0;
        foreach ($ids as $id) {
            $connection = DB::connection();
            $lock = 'it-mail:'.substr(hash('sha256', $connection->getDatabaseName().':'.$id), 0, 48);
            if ((int) $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock], false)->acquired !== 1) {
                continue;
            }
            try {
                $delivery = ItEmailDelivery::query()->findOrFail($id);
                if ($delivery->dispatch_finished_at !== null) {
                    continue;
                }
                if (! $this->recipientCanReceiveLoadedDelivery($delivery)) {
                    $this->stopInaccessibleDelivery($delivery);
                } elseif ($delivery->status === 'queued') {
                    try {
                        $notification = $this->notificationForRetry($delivery);
                        $notification->id = $delivery->notification_uuid;
                        // The outbox is the durable queue. Send both channels now
                        // under its lock, using a current recipient and record.
                        NotificationFacade::sendNow(User::query()->findOrFail($delivery->recipient_user_id), $notification);
                    } catch (Throwable $exception) {
                        // Conditional writes preserve a provider callback that
                        // raced with this exception. Sending is never failed
                        // locally: provider acceptance may already have happened.
                        $this->markDispatchFailed($delivery, $exception);
                    }
                }
                ItEmailDelivery::query()->whereKey($id)->update(['dispatch_finished_at' => now()]);
                $processed++;
            } finally {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock], false);
            }
        }

        return $processed;
    }

    /** Reuses the canonical outbox and recipient identity for an explicit settings-owner test. */
    public function prepareConfigurationTest(Request $request, string $uuid, int $version): ItEmailDelivery
    {
        return DB::transaction(function () use ($request, $uuid, $version): ItEmailDelivery {
            $recipient = $request->user()?->fresh();
            $settings = app(EmailConfiguration::class);
            abort_unless($settings->canManage($recipient), 403);
            AppSetting::query()->where('key', EmailConfiguration::KEY)->lockForUpdate()->first();
            $existing = ItEmailDelivery::query()->where('notification_uuid', $uuid)->where('recipient_user_id', $recipient->id)->first();
            if ($existing) {
                abort_unless($existing->notification_type === 'it_email_configuration_test'
                    && ($existing->notification_context['configuration_version'] ?? null) === $version, 409,
                    'This test request belongs to different settings. Review its existing outcome.');

                return $existing;
            }
            $configuration = $settings->current();
            abort_unless($configuration['configuration_version'] === $version, 409, 'Email settings changed. Review the saved configuration before testing.');
            abort_unless($configuration['support_enabled'], 422, 'Enable and save the support email settings before testing them.');
            try {
                $settings->supportConnection($configuration);
            } catch (DomainException) {
                abort(422, 'Review the saved support mailbox before testing.');
            }
            $capture = app(ItOutboundMailer::class)->captureMode();
            abort_if(ItEmailDelivery::query()->where('recipient_user_id', $recipient->id)
                ->where('notification_type', 'it_email_configuration_test')->whereIn('status', ['queued', 'sending'])->exists(), 409,
                'An earlier test is queued or its outcome is uncertain. Review that delivery before sending another test.');
            $notification = new EmailConfigurationTestNotification($version, $capture);
            $notification->id = $uuid;
            $delivery = $this->createDelivery($recipient, $notification);
            $delivery->forceFill(['dispatch_requested_at' => now()])->save();
            AuditLogger::logOrFail('settings.email.test_requested', $delivery, ['configuration_version' => $version], $request);

            return $delivery;
        });
    }

    /**
     * Compatibility entry point for older callers. Persist through the same
     * outbox and defer dispatch until the originating transaction has committed.
     */
    public function send(iterable|User $recipients, Notification&TracksItEmailDelivery $notification): void
    {
        foreach ($this->prepareDeliveries($recipients, $notification) as $id) {
            $delivery = ItEmailDelivery::query()->findOrFail($id);
            if (! $this->recipientCanReceiveLoadedDelivery($delivery)) {
                $this->stopInaccessibleDelivery($delivery);

                continue;
            }
            // Exact IDs also cover provisioning messages without a ticket.
            // If PHP stops before this callback/response, the persisted intent
            // remains discoverable by the existing scheduled recovery drain.
            DB::afterCommit(static fn () => DispatchItTicketNotifications::dispatchAfterResponse(null, $id));
        }
    }

    public function recordNotificationEvent(NotificationSending|NotificationSent|NotificationFailed $event): ?bool
    {
        if (! $event->notification instanceof TracksItEmailDelivery) {
            return null;
        }

        $delivery = $this->deliveryFor($event->notification, $event->notifiable);
        if (! $delivery && $event instanceof NotificationSending && $event->notifiable instanceof User) {
            $delivery = $this->createDelivery($event->notifiable, $event->notification);
        }
        if (! $delivery) {
            // Tracked IT messages must always identify a current authorized
            // recipient. Fail closed for unsupported anonymous notifiables.
            return $event instanceof NotificationSending ? false : null;
        }

        // Queued database and mail channels execute independently. Recheck
        // access here as well as at dispatch: a cached/serialized actor or
        // an earlier successful channel is not proof of current permission.
        if ($event instanceof NotificationSending
            && ! $this->recipientCanReceiveLoadedDelivery($delivery)) {
            $this->stopInaccessibleDelivery($delivery);

            return false;
        }
        if ($event->channel !== 'mail') {
            if ($event instanceof NotificationSending && $event->channel === 'database'
                && $event->notifiable instanceof User
                && $event->notifiable->notifications()->whereKey($event->notification->id)->exists()) {
                return false;
            }

            return null;
        }

        if ($event instanceof NotificationSending) {
            $claimed = ItEmailDelivery::query()->whereKey($delivery->id)->where('status', 'queued')
                ->whereNull('provider_status_at')->whereNull('sending_at')
                ->update([
                    'status' => 'sending',
                    'sending_at' => now(),
                    'attempt_count' => DB::raw('attempt_count + 1'),
                ]);
            if ($claimed !== 1) {
                return false;
            }

            return null;
        }

        if ($event instanceof NotificationSent) {
            // Laravel forwards getMessageId dynamically, so method_exists on
            // its wrapper misses real provider IDs. Read the underlying result.
            $response = $event->response instanceof LaravelSentMessage
                ? $event->response->getSymfonySentMessage() : $event->response;
            $messageId = is_object($response) && method_exists($response, 'getMessageId')
                ? $response->getMessageId()
                : null;
            // Symfony defaults to the submitted RFC ID when a transport returns
            // no provider ID (including Graph's empty 202). Do not relabel it.
            if (! is_string($messageId) || $messageId === '' || strlen($messageId) > 255
                || preg_match('/[\x00-\x20\x7f]/', $messageId)
                || in_array($delivery->rfc_message_id, [$messageId, '<'.$messageId.'>'], true)) {
                $messageId = null;
            }
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
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            return null;
        }

        $exception = $event->data['exception'] ?? null;
        if ($exception instanceof MailNotSubmitted || $exception instanceof MailSubmissionRejected) {
            $this->markKnownUnacceptedDelivery($delivery, $exception);

            return null;
        }
        $this->markLocalDeliveryFailed($delivery);

        return null;
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

        $receivedAt = now()->toImmutable()->utc();
        $eventAt = self::resolveProviderEventAt($occurredAt, $receivedAt);

        return DB::transaction(function () use ($notificationUuid, $status, $providerMessageId, $eventAt): ItEmailDelivery {
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
                'last_error' => $status === 'delivered' ? null : ItEmailDeliveryFailure::MESSAGES[
                    $status === 'bounced' ? 'provider_bounced' : 'provider_failed'
                ],
                'delivered_at' => $status === 'delivered' ? $eventAt : $delivery->delivered_at,
                'failed_at' => $status === 'failed' ? $eventAt : $delivery->failed_at,
                'bounced_at' => $status === 'bounced' ? $eventAt : $delivery->bounced_at,
            ])->save();

            return $delivery->fresh();
        });
    }

    public static function resolveProviderEventAt(
        CarbonInterface|string|null $occurredAt,
        CarbonInterface $receivedAt,
    ): CarbonImmutable {
        $receipt = CarbonImmutable::instance($receivedAt)->utc();

        if ($occurredAt === null) {
            return $receipt;
        }

        if ($occurredAt instanceof CarbonInterface) {
            $eventAt = CarbonImmutable::instance($occurredAt)->utc();
        } else {
            $matched = preg_match(
                '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:\d{2})$/D',
                $occurredAt,
                $matches,
            ) === 1;

            if (! $matched) {
                throw new DomainException('The provider delivery timestamp must be an absolute ISO 8601 timestamp.');
            }

            $normalized = $matches[1]
                .'.'.str_pad($matches[2] ?? '', 6, '0')
                .$matches[3];
            $parsed = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.uP',
                $normalized,
            );
            $parseErrors = DateTimeImmutable::getLastErrors();
            if (
                $parsed === false
                || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
            ) {
                throw new DomainException('The provider delivery timestamp must be a valid absolute ISO 8601 timestamp.');
            }

            $eventAt = CarbonImmutable::instance($parsed)->utc();
        }

        if ($eventAt->greaterThan($receipt->addSeconds(self::MAX_PROVIDER_FUTURE_SKEW_SECONDS))) {
            throw new DomainException('The provider delivery timestamp is too far in the future.');
        }

        return $eventAt;
    }

    public function canRetryDelivery(ItEmailDelivery $delivery, User $actor): bool
    {
        $canonical = ItEmailDelivery::query()
            ->with(['ticket', 'provisioningRequest.employeeProfile', 'provisioningRequest.responsibleTeam'])
            ->whereKey($delivery->getKey())
            ->first();

        return $canonical !== null && $this->actorCanRetryLoadedDelivery($canonical, $actor);
    }

    /** UI eligibility only; retry() repeats these checks under its row lock. */
    public function canOfferRetry(ItEmailDelivery $delivery, User $actor): bool
    {
        $canonical = ItEmailDelivery::query()->with('retryAttempt')->find($delivery->getKey());

        return $canonical !== null
            && in_array($canonical->status, ['failed', 'bounced'], true)
            && $canonical->retryAttempt === null
            && $this->actorCanRetryLoadedDelivery($canonical, $actor)
            && $this->recipientCanReceiveLoadedDelivery($canonical);
    }

    /** Read the same authorized delivery set as the Operations register, without a row limit. */
    public function operationsHealth(User $viewer): array
    {
        $available = Schema::hasTable('it_email_deliveries');
        $summary = $available ? $this->visibleQuery($viewer)->toBase()->selectRaw(
            "SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued,
             SUM(CASE WHEN status = 'sending' THEN 1 ELSE 0 END) AS sending,
             SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS accepted,
             MIN(CASE WHEN status = 'queued' THEN queued_at END) AS oldest_queued_at,
             MIN(CASE WHEN status IN ('sending', 'accepted') THEN queued_at END) AS oldest_unconfirmed_at,
             MAX(CASE WHEN status = 'delivered' THEN delivered_at END) AS last_confirmed_delivery_at"
        )->first() : null;
        $stamp = fn (?string $value): ?string => $value ? CarbonImmutable::parse($value)->toIso8601String() : null;

        return [
            'viewer_user_id' => (int) $viewer->id,
            'available' => $available,
            'checked_at' => now()->toIso8601String(),
            'queued' => $available ? (int) $summary->queued : null,
            'sending' => $available ? (int) $summary->sending : null,
            'accepted' => $available ? (int) $summary->accepted : null,
            'oldest_queued_at' => $stamp($summary?->oldest_queued_at),
            'oldest_unconfirmed_at' => $stamp($summary?->oldest_unconfirmed_at),
            'last_confirmed_delivery_at' => $stamp($summary?->last_confirmed_delivery_at),
        ];
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

            $retryContext = $original->notification_context ?? [];
            unset($retryContext['submission_snapshot']);
            $retry = ItEmailDelivery::query()->create([
                'notification_uuid' => (string) Str::uuid(),
                'retry_of_delivery_id' => $original->id,
                'it_ticket_id' => $original->it_ticket_id,
                'it_provisioning_request_id' => $original->it_provisioning_request_id,
                'it_ticket_comment_id' => $original->it_ticket_comment_id,
                'recipient_user_id' => $original->recipient_user_id,
                'recipient_email' => $original->recipient_email,
                'notification_type' => $original->notification_type,
                'notification_context' => $retryContext,
                'audience' => $original->audience,
                'subject' => $original->subject,
                'status' => 'queued',
                'retry_count' => $original->retry_count + 1,
                'queued_at' => now(),
                'dispatch_requested_at' => now(),
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

        // The row is the durable intent. Sending here could precede an outer
        // transaction's commit, or leave a committed retry stranded if enqueueing
        // failed. The post-response/scheduled drain owns all submission attempts.
        return $retry;
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
        // Never trust relations captured before a role/site/approval change.
        $delivery->load(['ticket', 'provisioningRequest.employeeProfile', 'provisioningRequest.responsibleTeam']);
        $recipient = User::query()->find($delivery->recipient_user_id);
        if (! $recipient || $recipient->approved_at === null) {
            return false;
        }

        if ($delivery->notification_type === 'it_email_configuration_test') {
            $settings = app(EmailConfiguration::class);
            $configuration = $settings->current();

            return $settings->canManage($recipient) && $configuration['support_enabled']
                && ($delivery->notification_context['configuration_version'] ?? null) === $configuration['configuration_version']
                && ($delivery->notification_context['capture_mode'] ?? null) === app(ItOutboundMailer::class)->captureMode();
        }

        if ($delivery->ticket) {
            if ($delivery->notification_type === 'ticket_assigned') {
                return (int) $delivery->ticket->assigned_to_user_id === (int) $recipient->id
                    && $this->workAccess->canReceiveTicketUpdates($recipient, $delivery->ticket);
            }
            if ($delivery->notification_type === 'ticket_replied' && $delivery->it_ticket_comment_id === null
                && ($delivery->notification_context['requires_public_comment'] ?? false)) {
                // ON DELETE SET NULL must not turn a removed reply into a
                // legacy notification that never had a comment reference.
                return false;
            }
            if ($delivery->notification_type === 'ticket_replied' && $delivery->it_ticket_comment_id !== null) {
                $comment = ItTicketComment::query()->find($delivery->it_ticket_comment_id);
                if (! $comment || $comment->is_internal || (int) $comment->ticket_id !== (int) $delivery->ticket->id) {
                    return false;
                }
            }
            if ($delivery->notification_type === 'ticket_approval') {
                if (! $recipient->canDo('it.manage') || ! $this->workAccess->canWork($recipient, $delivery->ticket)) {
                    return false;
                }
                $approvalId = $delivery->notification_context['approval_id'] ?? null;
                if ($approvalId === null) {
                    // Existing unversioned messages retain their current work
                    // audience; no historical generation is invented for them.
                    return true;
                }
                $approval = $delivery->ticket->approvals()->whereKey($approvalId)->first();
                $event = $delivery->notification_context['event'] ?? null;
                if (! $approval) {
                    return false;
                }

                if (in_array($event, ['requested', 'reminder'], true)) {
                    if ($approval->status !== 'pending' || $approval->isPastDeadline()
                        || (int) $approval->requested_by === (int) $recipient->id
                        || $delivery->ticket->isMerged() || ! in_array($delivery->ticket->status, ItTicket::OPEN_STATUSES, true)) {
                        return false;
                    }
                    if ($event === 'reminder' && $approval->reminder_prepared_at === null) {
                        return false;
                    }

                    return $approval->assignment_recorded_at === null ? $event === 'requested'
                        : app(ItTicketApprovalResponsibilityService::class)->decisionBasis($approval, $delivery->ticket, $recipient) !== null;
                }

                return in_array($event, ['approved', 'rejected', 'expired', 'cancelled'], true) && $approval->status === $event
                    && (int) $approval->requested_by === (int) $recipient->id;
            }
            if ($delivery->notification_type === 'ticket_sla') {
                return app(ItTicketRoutingEligibility::class)->agent($recipient->id, $delivery->ticket) !== null;
            }
            if (($delivery->notification_type === 'ticket_replied' && $delivery->audience === 'agent_side')
                || $delivery->notification_type === 'ticket_reopened') {
                return $this->workAccess->canReceiveTicketUpdates($recipient, $delivery->ticket)
                    && ((int) $delivery->ticket->assigned_to_user_id === (int) $recipient->id
                        || $delivery->ticket->watchers()->whereKey($recipient->id)->exists());
            }
            if ($delivery->notification_type === 'ticket_resolved' && $delivery->audience === 'watcher') {
                return $this->workAccess->canReceiveTicketUpdates($recipient, $delivery->ticket)
                    && $delivery->ticket->watchers()->whereKey($recipient->id)->exists();
            }

            return ($recipient->canDo('it.request') || $recipient->canDo('it.view') || $recipient->canDo('it.manage'))
                && $this->workAccess->canView($recipient, $delivery->ticket);
        }

        return $delivery->provisioningRequest !== null
            && $this->actorCanRetryLoadedDelivery($delivery, $recipient);
    }

    private function stopInaccessibleDelivery(ItEmailDelivery $delivery): void
    {
        DB::transaction(function () use ($delivery): void {
            $current = ItEmailDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            // Access suppression cannot disprove a previous send. This row
            // lock also serializes provider callbacks with the audit mutation.
            if ($current->provider_status_at !== null
                || ! in_array($current->status, ['queued', 'sending'], true)) {
                return;
            }
            if ($current->status === 'sending' || $current->sending_at !== null) {
                if ($current->last_error === self::REVOKED_UNCERTAIN_DELIVERY_ERROR) {
                    return;
                }
                $current->forceFill([
                    'status' => 'sending',
                    'failed_at' => null,
                    'last_error' => self::REVOKED_UNCERTAIN_DELIVERY_ERROR,
                ])->save();
            } else {
                $current->forceFill([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'last_error' => 'Delivery stopped because the recipient no longer has access.',
                ])->save();
            }
            AuditLogger::logOrFail('it.email.delivery.access_revoked', $current, [
                'ticket_id' => $current->it_ticket_id,
                'provisioning_request_id' => $current->it_provisioning_request_id,
                'recipient_user_id' => $current->recipient_user_id,
            ]);
        });
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

        if ($delivery->notification_type === 'it_email_configuration_test') {
            return new EmailConfigurationTestNotification((int) ($context['configuration_version'] ?? -1), $context['capture_mode'] ?? null);
        }

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
            'ticket_approval' => new TicketApprovalNotification($ticket, (string) ($context['event'] ?? 'requested'), isset($context['approval_id']) ? (int) $context['approval_id'] : null),
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
        if ($exception instanceof MailNotSubmitted || $exception instanceof MailSubmissionRejected) {
            $this->markKnownUnacceptedDelivery($delivery, $exception);
        } else {
            $this->markLocalDeliveryFailed($delivery);
        }
    }

    private function markKnownUnacceptedDelivery(ItEmailDelivery $delivery, MailNotSubmitted|MailSubmissionRejected $exception): void
    {
        ItEmailDelivery::query()->whereKey($delivery->id)->whereNull('provider_status_at')->whereNull('accepted_at')
            ->whereIn('status', ['queued', 'sending'])->update([
                'status' => 'failed', 'failed_at' => now(),
                'last_error' => ItEmailDeliveryFailure::MESSAGES[
                    $exception instanceof MailNotSubmitted ? 'not_submitted' : 'submission_rejected'
                ],
            ]);
    }

    private function markLocalDeliveryFailed(ItEmailDelivery $delivery): void
    {
        // Each write checks the current database state. A loaded model may
        // precede provider acceptance, a terminal callback or an access change.
        ItEmailDelivery::query()->whereKey($delivery->id)
            ->whereNull('provider_status_at')->whereNull('sending_at')
            ->where('status', 'queued')
            ->update([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error' => ItEmailDeliveryFailure::MESSAGES['dispatch_failed'],
            ]);

        ItEmailDelivery::query()->whereKey($delivery->id)
            ->whereNull('provider_status_at')->whereNotNull('sending_at')
            ->where('status', 'sending')
            ->where(fn (Builder $query) => $query->whereNull('last_error')
                ->orWhere('last_error', '!=', self::REVOKED_UNCERTAIN_DELIVERY_ERROR))
            ->update([
                'failed_at' => null,
                'last_error' => self::UNKNOWN_DELIVERY_ERROR,
            ]);
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
