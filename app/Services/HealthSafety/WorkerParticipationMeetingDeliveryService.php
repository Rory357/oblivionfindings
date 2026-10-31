<?php

namespace App\Services\HealthSafety;

use App\Jobs\DispatchWorkerParticipationMeetingDelivery;
use App\Models\HsCommitteeMeeting;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Models\WorkerParticipationMeetingDelivery;
use App\Models\WorkerParticipationMeetingEvent;
use App\Notifications\HealthSafety\WorkerParticipationMeetingNotice;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use LogicException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Durable generic notices with original source epochs and independently current recipient source admission. */
final class WorkerParticipationMeetingDeliveryService
{
    public const EVENT_KEY = 'healthSafety.meeting_update';

    public function __construct(private readonly WorkerParticipationRecipientSourceService $sources) {}

    /** Called after the authorised source mutation, within its write transaction; returns no source model/body. */
    public function recordCurrentEpoch(HsCommitteeMeeting $meeting, User $actor, string $reason): array
    {
        $this->insideTransaction();
        if (! in_array($reason, ['scheduled', 'rescheduled', 'source_changed', 'attendees_added', 'completed', 'cancelled'], true)) {
            throw new LogicException('Use a supported participation source event purpose.');
        }
        return $this->sources->withManagerSource($actor, (int) $meeting->id, function ($current, $reads, $context, $proof, $descriptor, Builder $parent) use ($meeting, $reason): array {
            // Serialize sequence allocation on the actual canonical root, not on an incoming model snapshot.
            abort_unless(DB::table('hs_committee_meetings')->where('id', $meeting->id)->lockForUpdate()->first(['id']), 404);
            $membership = [];
            foreach ($this->sources->candidateRecipients($meeting, true, $context, $descriptor) as $candidate) {
                $recipient = User::query()->whereKey($candidate['recipient_id'])->first(['id']);
                if (! $recipient) { continue; }
                try {
                    $this->sources->withSource($recipient, (int) $meeting->id, $candidate['audience'], $descriptor,
                        function () use (&$membership, $candidate): array { $membership[] = $candidate; return []; });
                } catch (HttpExceptionInterface $error) {
                    if (! in_array($error->getStatusCode(), [403, 404], true)) { throw $error; }
                }
            }
            usort($membership, fn ($a, $b) => [$a['recipient_id'], $a['audience']] <=> [$b['recipient_id'], $b['audience']]);
            $sourceDigest = self::digest($descriptor); $audienceDigest = self::digest($membership);
            $latest = WorkerParticipationMeetingEvent::query()->where('meeting_id', $meeting->id)->orderByDesc('sequence')->lockForUpdate()
                ->first(['id', 'sequence', 'source_epoch_id', 'source_digest', 'audience_digest', 'purpose']);
            if ($latest && $latest->source_digest === $sourceDigest && $latest->audience_digest === $audienceDigest && $latest->purpose === $reason) {
                return ['event_id' => $latest->id, 'source_epoch_id' => $latest->source_epoch_id, 'sequence' => (int) $latest->sequence];
            }
            $id = (string) Str::uuid();
            $epoch = $latest && $latest->source_digest === $sourceDigest ? $latest->source_epoch_id : $id;
            $sequence = $latest ? (int) $latest->sequence + 1 : 1;
            $values = ['id' => $id, 'meeting_id' => (int) $meeting->id, 'sequence' => $sequence, 'source_epoch_id' => $epoch,
                'committee_id' => $descriptor['committee_id'], 'site_id' => $descriptor['site_id'], 'initiated_by_user_id' => (int) $current->id,
                'purpose' => $reason, 'source_descriptor' => json_encode($descriptor, JSON_THROW_ON_ERROR), 'source_digest' => $sourceDigest,
                'audience_membership' => json_encode($membership, JSON_THROW_ON_ERROR), 'audience_digest' => $audienceDigest,
                'created_at' => now(), 'updated_at' => now()];
            $this->insertFromSource('worker_participation_meeting_events', $values, $parent);
            if ($latest && $latest->source_epoch_id !== $epoch) { $this->retireOlderEpoch((int) $meeting->id, $epoch, $parent); }
            return ['event_id' => $id, 'source_epoch_id' => $epoch, 'sequence' => $sequence];
        });
    }

    /** Only explicit new attendees and the existing optional workforce trigger produce intents. */
    public function stageToAudience(User $actor, string $eventId, array $newAttendeeIds = [], bool $includeWorkforce = false): array
    {
        $this->insideTransaction();
        foreach ($newAttendeeIds as $id) { if (! is_int($id) || $id <= 0) { throw new LogicException('Attendee delivery identities must be positive integers.'); } }
        $meta = WorkerParticipationMeetingEvent::query()->whereKey($eventId)->first(['id', 'meeting_id']);
        abort_unless($meta, 404);
        return $this->sources->withManagerSource($actor, (int) $meta->meeting_id, function ($current, $reads, $context, $proof, $descriptor, Builder $parent) use ($meta, $eventId, $newAttendeeIds, $includeWorkforce): array {
            $event = $proof->protect($this->eventQuery($eventId, $descriptor, $parent, $reads))->first();
            abort_unless($event && $this->validMembership($event->audience_membership)
                && self::digest($event->audience_membership) === $event->audience_digest, 404);
            $ids = [];
            foreach ($event->audience_membership as $member) {
                if (($member['audience'] === 'attendee' && ! in_array($member['recipient_id'], $newAttendeeIds, true))
                    || ($member['audience'] === 'workforce_notice' && ! $includeWorkforce)) { continue; }
                $recipient = User::query()->whereKey($member['recipient_id'])->first(['id']);
                if (! $recipient) { continue; }
                try {
                    $id = $this->sources->withSource($recipient, (int) $meta->meeting_id, $member['audience'], $descriptor,
                        function ($receiver, $recipientReads, $active, $recipientProof, $original, Builder $source) use ($event, $member): array {
                            $memberSource = $this->eventQuery($event->id, $original, $source, $recipientReads)
                                ->whereJsonContains('audience_membership', ['recipient_id' => (int) $receiver->id, 'audience' => $member['audience']]);
                            $sourceEvent = $recipientProof->protect($memberSource)->first();
                            abort_unless($sourceEvent && self::digest($sourceEvent->audience_membership) === $sourceEvent->audience_digest, 404);
                            $source = $this->reconsume((clone $source)->whereExists((clone $memberSource)->selectRaw('1')->toBase()), $memberSource);
                            $id = self::notificationId($event->id, (int) $receiver->id, $member['audience']);
                            $existing = WorkerParticipationMeetingDelivery::query()->whereKey($id)->first(['id', 'event_id', 'recipient_user_id', 'audience']);
                            if ($existing) {
                                abort_unless($existing->event_id === $event->id && (int) $existing->recipient_user_id === (int) $receiver->id
                                    && $existing->audience === $member['audience'], 404);
                            } else {
                                $this->insertFromSource('worker_participation_meeting_deliveries', ['id' => $id, 'event_id' => $event->id,
                                    'recipient_user_id' => (int) $receiver->id, 'audience' => $member['audience'], 'database_state' => 'pending',
                                    'mail_state' => $member['audience'] === 'attendee' ? 'pending' : 'not_requested',
                                    'database_attempts' => 0, 'mail_attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()], $source);
                                DB::afterCommit(fn () => $this->queue($id));
                            }
                            return ['id' => $id];
                        });
                    $ids[] = $id['id'];
                } catch (HttpExceptionInterface $error) {
                    if (! in_array($error->getStatusCode(), [403, 404], true)) { throw $error; }
                }
            }
            return $ids;
        });
    }

    public static function digest(array $value): string
    {
        $canonical = static function (mixed $item) use (&$canonical): mixed {
            if (! is_array($item)) { return $item; }
            if (! array_is_list($item)) { ksort($item); }
            return array_map($canonical, $item);
        };
        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function notificationId(string $eventId, int $recipientId, string $audience): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'oblivionfindings:participation:'.$eventId.':'.$recipientId.':'.$audience)->toString();
    }

    public function queue(string $id): void
    {
        try { DispatchWorkerParticipationMeetingDelivery::dispatch($id); }
        catch (Throwable $error) {
            Log::warning('Participation delivery queue unavailable', ['delivery_id' => $id, 'exception_type' => $error::class]);
            WorkerParticipationMeetingDelivery::query()->whereKey($id)->whereIn('database_state', ['pending', 'failed'])
                ->whereIn('mail_state', ['pending', 'failed', 'not_requested'])
                ->update(['failure_code' => 'queue_unavailable', 'next_attempt_at' => now()->addMinute()]);
        }
    }

    /** External mail requires its durable claim to have committed independently of any caller transaction. */
    public function deliver(string $id): void
    {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Participation mail delivery requires an outermost transaction boundary.'); }
        $this->deliverDatabase($id);
        $this->deliverMail($id);
    }

    public function deliverDatabase(string $id): void
    {
        $meta = $this->deliveryMetadata($id);
        if (! $meta || ! in_array($meta->database_state, ['pending', 'failed'], true) || $meta->next_attempt_at?->isFuture()) { return; }
        try {
            $this->withDeliverySource($meta, function ($current, $reads, $context, $proof, $descriptor, Builder $parent, $event) use ($meta): array {
                $intent = $this->receiptQuery($meta, $parent)->where('database_state', $meta->database_state)
                    ->where('database_attempts', $meta->database_attempts)->lockForUpdate()
                    ->first(['id', 'event_id', 'recipient_user_id', 'audience', 'database_state', 'database_attempts']);
                if (! $intent || ! in_array($intent->database_state, ['pending', 'failed'], true)) { return []; }
                $enabled = $this->preference($current, $reads, $proof, 'channel_inapp');
                if (! $enabled) { $this->updateReceipt($intent, $parent, ['database_state' => 'suppressed', 'next_attempt_at' => now()]); return []; }
                $notice = new WorkerParticipationMeetingNotice($this->envelope($intent, $event));
                $payload = json_encode($notice->toArray($current), JSON_THROW_ON_ERROR);
                // Owned mutable receipt rows are not added to the immutable source replay collector.
                $existing = $this->reconsume(DB::table('notifications')->where('id', $intent->id)
                    ->where('notifiable_type', $current->getMorphClass())->where('notifiable_id', $current->id)
                    ->whereExists((clone $parent)->selectRaw('1')->toBase()), $parent)->first(['id', 'type', 'data']);
                if ($existing && ($existing->type !== WorkerParticipationMeetingNotice::class || json_decode($existing->data, true, 512, JSON_THROW_ON_ERROR) !== $notice->toArray($current))) {
                    throw new RuntimeException('Participation notification identity collision.');
                }
                if (! $existing) {
                    if (DB::table('notifications')->where('id', $intent->id)->exists()) { throw new RuntimeException('Participation notification identity collision.'); }
                    $this->insertFromSource('notifications', ['id' => $intent->id, 'type' => WorkerParticipationMeetingNotice::class,
                        'notifiable_type' => $current->getMorphClass(), 'notifiable_id' => (int) $current->id,
                        'data' => $payload, 'read_at' => null, 'created_at' => now(), 'updated_at' => now()], $parent);
                }
                $this->updateReceipt($intent, $parent, ['database_state' => 'delivered', 'database_delivered_at' => now(),
                    'database_attempts' => DB::raw('database_attempts + 1'), 'failure_code' => null, 'next_attempt_at' => now()]);
                return [];
            });
        } catch (Throwable $error) { $this->failure($meta, 'database', $error); }
    }

    private function deliverMail(string $id): void
    {
        $meta = $this->deliveryMetadata($id);
        if (! $meta || ! in_array($meta->database_state, ['delivered', 'suppressed'], true)
            || ! in_array($meta->mail_state, ['pending', 'failed'], true) || $meta->next_attempt_at?->isFuture()) { return; }
        $attempted = false;
        $claimId = null;
        try {
            $claim = $this->withDeliverySource($meta, function ($current, $reads, $context, $proof, $descriptor, Builder $parent) use ($meta): array {
                $intent = $this->receiptQuery($meta, $parent)->where('mail_state', $meta->mail_state)
                    ->where('mail_attempts', $meta->mail_attempts)->where('mail_claim_id', $meta->mail_claim_id)->lockForUpdate()
                    ->first(['id', 'event_id', 'recipient_user_id', 'audience', 'mail_state', 'mail_attempts', 'mail_claim_id']);
                if (! $intent || ! in_array($intent->mail_state, ['pending', 'failed'], true)) { return ['claimed' => false]; }
                if (! $this->preference($current, $reads, $proof, 'channel_email')) {
                    $this->updateReceipt($intent, $parent, ['mail_state' => 'suppressed', 'next_attempt_at' => null]); return ['claimed' => false];
                }
                $claimId = (string) Str::uuid();
                $this->updateReceipt($intent, $parent, ['mail_state' => 'sending', 'mail_started_at' => now(),
                    'mail_claim_id' => $claimId, 'mail_attempts' => DB::raw('mail_attempts + 1'), 'failure_code' => null, 'next_attempt_at' => null]);
                return ['claimed' => true, 'claim_id' => $claimId];
            });
            if (! $claim['claimed']) { return; }
            $claimId = $claim['claim_id'];
            // The sending claim is committed before a separate, freshly admitted source callback attempts transport.
            $this->withDeliverySource($meta, function ($current, $reads, $context, $proof, $descriptor, Builder $parent, $event) use ($meta, $claimId, &$attempted): array {
                $intent = $this->receiptQuery($meta, $parent)
                    ->where('mail_state', 'sending')->where('mail_claim_id', $claimId)->lockForUpdate()
                    ->first(['id', 'event_id', 'recipient_user_id', 'audience', 'mail_state', 'mail_claim_id']);
                if (! $intent) { return []; }
                if (! $this->preference($current, $reads, $proof, 'channel_email')) {
                    $this->updateReceipt($intent, $parent, ['mail_state' => 'suppressed', 'next_attempt_at' => null]); return [];
                }
                $notice = new WorkerParticipationMeetingNotice($this->envelope($intent, $event));
                if (! $current->routeNotificationFor('mail', $notice)) {
                    $this->updateReceipt($intent, $parent, ['mail_state' => 'suppressed', 'failure_code' => 'mail_destination_missing', 'next_attempt_at' => null]); return [];
                }
                $proof->assertUnchanged();
                abort_unless((clone $parent)->exists(), 404);
                $attempted = true;
                Notification::sendNow($current, $notice, ['mail']);
                $this->updateReceipt($intent, $parent, ['mail_state' => 'sent', 'mail_delivered_at' => now(), 'failure_code' => null]);
                return [];
            });
        } catch (Throwable $error) {
            if ($attempted) {
                WorkerParticipationMeetingDelivery::query()->whereKey($id)->where('mail_state', 'sending')
                    ->where('mail_claim_id', $claimId)
                    ->update(['mail_state' => 'uncertain', 'failure_code' => 'mail_outcome_uncertain', 'next_attempt_at' => null]);
            } else { $this->failure($meta, 'mail', $error, $claimId); }
        }
    }

    private function withDeliverySource($meta, callable $operation): mixed
    {
        abort_unless(in_array($meta->audience, ['attendee', 'workforce_notice'], true)
            && self::notificationId($meta->event_id, (int) $meta->recipient_user_id, $meta->audience) === $meta->id, 404);
        $event = WorkerParticipationMeetingEvent::query()->whereKey($meta->event_id)->first(['id', 'meeting_id']);
        $recipient = User::query()->whereKey($meta->recipient_user_id)->first(['id']);
        abort_unless($event && $recipient, 404);
        return $this->sources->withSource($recipient, (int) $event->meeting_id, $meta->audience, null,
            function ($current, $reads, $context, $proof, $descriptor, Builder $parent) use ($event, $meta, $operation) {
                $member = ['recipient_id' => (int) $current->id, 'audience' => $meta->audience];
                $eventSource = $this->eventQuery($event->id, $descriptor, $parent, $reads)->whereJsonContains('audience_membership', $member);
                $original = $proof->protect($eventSource)->first();
                abort_unless($original && self::digest($original->source_descriptor) === $original->source_digest
                    && self::digest($original->audience_membership) === $original->audience_digest
                    && $this->validMembership($original->audience_membership)
                    && collect($original->audience_membership)->contains(fn ($row) => $row['recipient_id'] === $member['recipient_id']
                        && $row['audience'] === $member['audience']), 404);
                $retained = $this->reconsume((clone $parent)->whereExists((clone $eventSource)->selectRaw('1')->toBase()), $eventSource);
                return $operation($current, $reads, $context, $proof, $descriptor, $retained, $original);
            });
    }

    private function eventQuery(string $id, array $descriptor, Builder $parent, CurrentAuthorizationReads $reads): Builder
    {
        $query = WorkerParticipationMeetingEvent::query()->whereKey($id)->where('meeting_id', $descriptor['meeting_id'])
            ->where('committee_id', $descriptor['committee_id'])->where('site_id', $descriptor['site_id'])
            ->where('source_digest', self::digest($descriptor))
            ->whereNotExists($reads->query(WorkerParticipationMeetingEvent::query()->from('worker_participation_meeting_events as newer')
                ->selectRaw('1')->whereColumn('newer.meeting_id', 'worker_participation_meeting_events.meeting_id')
                ->whereColumn('newer.sequence', '>', 'worker_participation_meeting_events.sequence')
                ->whereColumn('newer.source_epoch_id', '!=', 'worker_participation_meeting_events.source_epoch_id'))->toBase())
            ->whereRaw('JSON_LENGTH(source_descriptor) = 7');
        foreach ($descriptor as $key => $value) {
            $path = '$.'.$key;
            if ($value === null) { $query->whereRaw("JSON_TYPE(JSON_EXTRACT(source_descriptor, ?)) = 'NULL'", [$path]); }
            else {
                $query->whereRaw('JSON_TYPE(JSON_EXTRACT(source_descriptor, ?)) = ?', [$path, is_int($value) ? 'INTEGER' : 'STRING'])
                    ->whereRaw('BINARY JSON_UNQUOTE(JSON_EXTRACT(source_descriptor, ?)) = BINARY ?', [$path, (string) $value]);
            }
        }
        return $this->reconsume($reads->query($query->whereExists((clone $parent)->selectRaw('1')->toBase())), $parent);
    }

    private function receiptQuery($observed, Builder $parent): Builder
    {
        return $this->reconsume(WorkerParticipationMeetingDelivery::query()->whereKey($observed->id)
            ->where('event_id', $observed->event_id)->where('recipient_user_id', $observed->recipient_user_id)
            ->where('audience', $observed->audience)->whereExists((clone $parent)->selectRaw('1')->toBase()), $parent);
    }

    private function validMembership(array $members): bool
    {
        if (! array_is_list($members)) { return false; }
        $seen = [];
        foreach ($members as $member) {
            if (! is_array($member)) { return false; }
            $keys = array_keys($member); sort($keys);
            if ($keys !== ['audience', 'recipient_id'] || ! is_int($member['recipient_id']) || $member['recipient_id'] <= 0
                || ! is_string($member['audience']) || ! in_array($member['audience'], ['attendee', 'workforce_notice'], true)) { return false; }
            $key = $member['recipient_id'].':'.$member['audience'];
            if (isset($seen[$key])) { return false; }
            $seen[$key] = true;
        }
        return true;
    }

    private function envelope($intent, WorkerParticipationMeetingEvent $event): array
    {
        return ['delivery_id' => $intent->id, 'event_id' => $event->id, 'source_epoch_id' => $event->source_epoch_id,
            'meeting_id' => (int) $event->meeting_id, 'committee_id' => (int) $event->committee_id,
            'site_id' => $event->site_id === null ? null : (int) $event->site_id, 'audience' => $intent->audience, 'source_digest' => $event->source_digest];
    }

    private function insertFromSource(string $table, array $values, Builder $parent): void
    {
        $source = (clone $parent)->reorder()->select([])->selectRaw(implode(', ', array_fill(0, count($values), '?')), array_values($values));
        abort_unless(DB::table($table)->insertUsing(array_keys($values), $source) === 1, 404, 'Participation source changed before persistence.');
    }

    private function updateReceipt($intent, Builder $parent, array $values): void
    {
        $query = WorkerParticipationMeetingDelivery::query()->whereKey($intent->id)->where('event_id', $intent->event_id)
            ->where('recipient_user_id', $intent->recipient_user_id)->where('audience', $intent->audience)
            ->whereExists((clone $parent)->selectRaw('1')->toBase());
        if (isset($intent->database_state)) { $query->where('database_state', $intent->database_state); }
        if (isset($intent->database_attempts)) { $query->where('database_attempts', $intent->database_attempts); }
        if (isset($intent->mail_state)) { $query->where('mail_state', $intent->mail_state); }
        if (isset($intent->mail_attempts)) { $query->where('mail_attempts', $intent->mail_attempts); }
        if (isset($intent->mail_claim_id)) { $query->where('mail_claim_id', $intent->mail_claim_id); }
        abort_unless($this->reconsume($query, $parent)->update($values) === 1, 404);
    }

    private function retireOlderEpoch(int $meetingId, string $epoch, Builder $parent): void
    {
        $older = fn (): Builder => WorkerParticipationMeetingDelivery::query()
            ->whereHas('event', fn (Builder $event) => $event->where('meeting_id', $meetingId)->where('source_epoch_id', '!=', $epoch))
            ->whereExists((clone $parent)->selectRaw('1')->toBase());
        $this->reconsume($older()->whereIn('database_state', ['pending', 'failed']), $parent)
            ->update(['database_state' => 'skipped', 'failure_code' => 'source_changed', 'next_attempt_at' => null]);
        $this->reconsume($older()->whereIn('mail_state', ['pending', 'failed']), $parent)
            ->update(['mail_state' => 'skipped', 'failure_code' => 'source_changed', 'next_attempt_at' => null]);
        $this->reconsume($older()->where('mail_state', 'sending'), $parent)
            ->update(['mail_state' => 'uncertain', 'failure_code' => 'mail_outcome_uncertain', 'next_attempt_at' => null]);
    }

    private function reconsume($query, Builder $parent)
    {
        return $query->whereRaw(new class($parent) extends Expression {
            public function __construct(private Builder $parent) { parent::__construct('1 = 1'); }
            public function getValue(\Illuminate\Database\Grammar $grammar) { (clone $this->parent)->toSql(); return parent::getValue($grammar); }
        });
    }

    private function preference(User $current, CurrentAuthorizationReads $reads, $proof, string $channel): bool
    {
        $override = $proof->protect($reads->query(UserNotificationPreference::query()->where('user_id', $current->id)->where('key', self::EVENT_KEY)))->first();
        $roles = $current->roles->pluck('id')->all();
        if ($roles === [] && filled($current->role)) {
            $roles = $proof->protect($reads->query(Role::query()->where('name', $current->role)))->get(['id'])->pluck('id')->all();
        }
        $defaults = $proof->protect($reads->query(RoleNotificationPreference::query()->whereIn('role_id', $roles)->where('key', self::EVENT_KEY)))->get();
        if ($override) { return (bool) $override->enabled && (bool) $override->{$channel}; }
        return $defaults->isEmpty() || $defaults->contains(fn ($row) => (bool) $row->enabled && (bool) $row->{$channel});
    }

    private function deliveryMetadata(string $id)
    {
        return WorkerParticipationMeetingDelivery::query()->whereKey($id)->first(['id', 'event_id', 'recipient_user_id', 'audience', 'database_state', 'mail_state', 'database_attempts', 'mail_attempts', 'mail_claim_id', 'next_attempt_at']);
    }

    private function failure($observed, string $channel, Throwable $error, ?string $claimId = null): void
    {
        $unavailable = $error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 404], true);
        $state = $channel.'_state'; $attempts = $channel.'_attempts';
        $query = WorkerParticipationMeetingDelivery::query()->whereKey($observed->id)->where('event_id', $observed->event_id)
            ->where('recipient_user_id', $observed->recipient_user_id)->where('audience', $observed->audience);
        $values = [$state => 'failed', 'failure_code' => $unavailable ? 'source_unavailable' : 'delivery_failed', 'next_attempt_at' => now()->addMinute()];
        if ($channel === 'mail' && $claimId !== null) {
            $query->where('mail_state', 'sending')->where('mail_claim_id', $claimId)->where('mail_attempts', (int) $observed->mail_attempts + 1);
        } else {
            if (! in_array($observed->{$state}, ['pending', 'failed'], true)) { return; }
            $query->where($state, $observed->{$state})->where($attempts, (int) $observed->{$attempts});
            if ($channel === 'mail') { $query->where('mail_claim_id', $observed->mail_claim_id); }
            $values[$attempts] = DB::raw($attempts.' + 1');
        }
        $query->update($values);
        Log::warning('Participation delivery attempt withheld', ['delivery_id' => $observed->id, 'channel' => $channel, 'exception_type' => $error::class]);
    }

    public function recoverPending(int $limit = 30): int
    {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Participation recovery requires an outermost transaction boundary.'); }
        // A crashed sending attempt is unresolved, never a reason to resend automatically.
        WorkerParticipationMeetingDelivery::query()->where('mail_state', 'sending')->where('mail_started_at', '<=', now()->subMinutes(10))
            ->update(['mail_state' => 'uncertain', 'failure_code' => 'mail_outcome_uncertain', 'next_attempt_at' => null]);
        $ids = WorkerParticipationMeetingDelivery::query()->where('next_attempt_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereIn('database_state', ['pending', 'failed'])->orWhereIn('mail_state', ['pending', 'failed']))
            ->orderBy('next_attempt_at')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($ids as $id) { $this->deliver($id); }
        return $ids->count();
    }

    private function insideTransaction(): void
    {
        if (DB::transactionLevel() < 1) { throw new LogicException('Participation delivery intent belongs in its source write transaction.'); }
    }
}
