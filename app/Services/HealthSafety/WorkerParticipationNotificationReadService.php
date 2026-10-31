<?php

namespace App\Services\HealthSafety;

use App\Models\User;
use App\Notifications\HealthSafety\CommitteeMeetingScheduled;
use App\Notifications\HealthSafety\WorkerParticipationMeetingNotice;
use App\Support\SchemaCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use LogicException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use WeakMap;
use WeakReference;

/** Original own-notice evidence, applied before count/body SQL and before Eloquent hydration. */
final class WorkerParticipationNotificationReadService
{
    private WeakMap $frames;

    /** Presence-only original source-admission evidence, never a receiver grant/cache. */
    private static ?WeakMap $admittedRequests = null;

    public function __construct(private readonly WorkerParticipationRecipientSourceService $recipients)
    {
        $this->frames = new WeakMap;
    }

    /** Called before other notification families perform their metadata reads. */
    public function prepare(MorphMany $notifications, User $viewer, ?HealthSafetyReadContext $context = null): void
    {
        $this->assertOwnRelation($notifications, $viewer);
        if (isset($this->frames[$notifications])) { throw new LogicException('An inbox source frame cannot be prepared twice.'); }
        $context?->assertViewer($viewer);
        $connection = $notifications->getQuery()->getQuery()->getConnection();
        $frame = ['viewer_id' => (int) $viewer->id, 'morph' => $viewer->getMorphClass(), 'connection' => $connection,
            'request' => WeakReference::create(request()), 'context' => $context, 'private_ids' => [], 'copies' => [], 'delivery_schema' => false];
        $eligible = false;
        $preparedContext = $context === null || HealthSafetyReadMaterialisationProof::current($context) !== null;
        if ($preparedContext && $context !== null) {
            try { $context->consumeSourceViewer(WorkerParticipationRecipientSourceService::PERMISSIONS, static fn () => null); }
            catch (LogicException) { $preparedContext = false; }
        }
        // Programmatic ordinary-family queries retain their existing API. Private notices require
        // the exact authenticated request receiver; a detached User is not an invitation grant.
        if ($preparedContext && (int) (request()->user()?->id ?? 0) === (int) $viewer->id && (int) (auth()->id() ?? 0) === (int) $viewer->id) {
            $admissions = self::$admittedRequests;
            $originalSourceAdmitted = $admissions !== null && isset($admissions[request()]);
            try {
                $this->recipients->prepareReceiver($viewer, $context);
                $eligible = true;
            } catch (HttpExceptionInterface $denial) {
                if ($originalSourceAdmitted || ! in_array($denial->getStatusCode(), [403, 404], true)) { throw $denial; }
            }
        }
        $frame['delivery_schema'] = SchemaCache::hasTable('worker_participation_meeting_deliveries');
        $sourceSchema = $frame['delivery_schema'] && SchemaCache::hasTable('worker_participation_meeting_events');

        // All own private identities, including already-read notices, are remembered before
        // later filters. Changing both type and marker cannot turn an original private copy ordinary.
        $identities = $this->ownQuery($frame)->where(fn (QueryBuilder $private) => $this->privateMarker($private, $frame['delivery_schema']));
        $frame['private_ids'] = $identities->pluck('notifications.id')->all();
        if ($eligible && $sourceSchema && $frame['private_ids'] !== []) {
            $metadata = $connection->table('worker_participation_meeting_deliveries as notice_delivery')
                ->lock('for share nowait')
                ->join('worker_participation_meeting_events as notice_event', 'notice_event.id', '=', 'notice_delivery.event_id')
                ->whereIn('notice_delivery.id', $frame['private_ids'])->where('notice_delivery.recipient_user_id', $frame['viewer_id'])
                ->where('notice_delivery.database_state', 'delivered')->whereNotNull('notice_delivery.database_delivered_at')
                ->get(['notice_delivery.id', 'notice_delivery.event_id', 'notice_delivery.recipient_user_id', 'notice_delivery.audience', 'notice_delivery.database_delivered_at',
                    'notice_event.meeting_id', 'notice_event.committee_id', 'notice_event.site_id', 'notice_event.source_epoch_id', 'notice_event.source_digest']);
            $sourceCandidates = [];
            foreach ($metadata as $candidate) {
                if ($this->validCandidate($candidate)) { $sourceCandidates[(int) $candidate->meeting_id.':'.$candidate->audience] = ['meeting_id' => (int) $candidate->meeting_id, 'audience' => $candidate->audience]; }
            }
            $this->assertPhysicalConnection($frame);
            $origins = $this->recipients->captureOrigins($viewer, array_values($sourceCandidates), $context);
            if ($origins !== []) {
                // captureOrigins already replays the same earliest actor/source proof.
                // A later transient inbox reader must not conceal that withdrawal.
                self::$admittedRequests ??= new WeakMap;
                self::$admittedRequests[request()] = true;
            }
            foreach ($metadata as $candidate) {
                if (! $this->validCandidate($candidate)) { continue; }
                $this->assertPhysicalConnection($frame);
                $key = (int) $candidate->meeting_id.':'.$candidate->audience;
                $origin = $origins[$key] ?? null;
                if ($origin === null) { continue; }
                $payload = (new WorkerParticipationMeetingNotice([
                    'delivery_id' => $candidate->id, 'event_id' => $candidate->event_id,
                    'source_epoch_id' => $candidate->source_epoch_id, 'meeting_id' => (int) $candidate->meeting_id,
                    'committee_id' => (int) $candidate->committee_id, 'site_id' => $candidate->site_id === null ? null : (int) $candidate->site_id,
                    'audience' => $candidate->audience, 'source_digest' => $candidate->source_digest,
                ]))->toArray($viewer);
                $originalCopyMatches = $this->copyQuery($frame, ['candidate' => $candidate, 'origin' => $origin, 'payload' => $payload, 'event' => null])->exists();
                // A malformed initial copy may be withheld; withdrawal of the source
                // already admitted by captureOrigins must not become an empty inbox.
                $origin->assertOriginal();
                if (! $originalCopyMatches) { continue; }
                $eventQuery = $connection->table('worker_participation_meeting_events as notice_event')
                    ->lock('for share nowait')
                    ->where('notice_event.id', $candidate->event_id)->whereExists($this->sourceBlock($candidate, $origin));
                $event = $eventQuery->first(); // The copied private location/descriptor is behind its original source predicate.
                $origin->assertOriginal();
                abort_unless($event !== null, 404, 'Original meeting delivery evidence changed.');
                try {
                    $descriptor = json_decode($event->source_descriptor, true, 512, JSON_THROW_ON_ERROR);
                    $membership = json_decode($event->audience_membership, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) { continue; }
                if (! $this->validEvent($event, $descriptor, $membership, $candidate)) { continue; }
                $copy = ['candidate' => $candidate, 'event' => (array) $event, 'origin' => $origin, 'payload' => $payload];
                abort_unless($this->sourceBlock($candidate, $origin, $copy['event'])->exists(), 404, 'Original meeting delivery evidence changed.');
                abort_unless($this->copyQuery($frame, $copy)->exists(), 404, 'Original meeting notice evidence changed.');
                $frame['copies'][] = $copy;
            }
        }
        $this->assertOriginal($frame);
        $this->frames[$notifications] = $frame;
    }

    /** Add an intersection; never replace the canonical own relation or other-family predicates. */
    public function scope(MorphMany $notifications, User $viewer, ?HealthSafetyReadContext $context = null): MorphMany
    {
        $this->assertOwnRelation($notifications, $viewer);
        $frame = $this->frames[$notifications] ?? throw new LogicException('Inbox source evidence must be prepared before other metadata.');
        if ($frame['viewer_id'] !== (int) $viewer->id || $frame['context'] !== $context) { throw new LogicException('Inbox source evidence cannot transfer.'); }
        $notifications->where(function (Builder $visible) use ($frame): void {
            $visible->where(function (Builder $ordinary) use ($frame): void {
                if ($frame['private_ids'] !== []) { $ordinary->whereNotIn('notifications.id', $frame['private_ids']); }
                $ordinary->whereNot(fn (Builder $private) => $this->privateMarker($private->getQuery(), $frame['delivery_schema']));
            });
            foreach ($frame['copies'] as $copy) {
                $visible->orWhere(function (Builder $private) use ($frame, $copy): void {
                    $this->copyPredicates($private->getQuery(), $frame, $copy);
                });
            }
        });
        if ($frame['copies'] !== []) {
            $actual = $notifications->getQuery()->getQuery();
            $actual->lock('for share nowait');
            $actual->beforeQuery(fn (QueryBuilder $query) => $this->assertOriginal($frame, $query));
            $actual->whereRaw(new class($this, $frame) extends Expression {
                public function __construct(private readonly WorkerParticipationNotificationReadService $owner, private readonly array $frame) { parent::__construct('1 = 1'); }
                public function getValue(\Illuminate\Database\Grammar $grammar) { $this->owner->assertRequest($this->frame); return parent::getValue($grammar); }
            });
            $actual->afterQuery(function ($rows) use ($frame) { $this->assertOriginal($frame); return $rows; });
        }
        if ($context !== null) {
            $context->query($notifications->getQuery());
            HealthSafetyReadMaterialisationProof::current($context)?->protect($notifications->getQuery());
        }
        return $notifications;
    }

    /** Public only for the compiler Expression; this is an original identity check, not admission. */
    public function assertRequest(array $frame): void
    {
        if ($frame['request']->get() !== request() || (int) (request()->user()?->id ?? 0) !== $frame['viewer_id']
            || (int) (auth()->id() ?? 0) !== $frame['viewer_id'] || DB::connection() !== $frame['connection']) {
            throw new LogicException('Private inbox evidence belongs to its original receiver and physical connection.');
        }
        $frame['context']?->assertActive();
    }

    private function assertPhysicalConnection(array $frame): void
    {
        if (DB::connection() !== $frame['connection']) { throw new LogicException('Private notification preparation cannot change physical connection.'); }
        $frame['context']?->assertActive();
    }

    private function assertOriginal(array $frame, ?QueryBuilder $actual = null): void
    {
        if ($frame['copies'] === []) { return; }
        $this->assertRequest($frame);
        if ($actual !== null && ($actual->getConnection() !== $frame['connection'] || $actual->from !== 'notifications')) {
            throw new LogicException('Private inbox evidence requires the canonical own notification statement.');
        }
        // One exact conjunction replays every admitted identity/source/copy, rather than
        // comparing only a count or issuing a new authority decision for each saved row.
        $barrier = $frame['connection']->query()->selectRaw('1')->lock('for share nowait');
        foreach ($frame['copies'] as $copy) { $barrier->whereExists($this->copyQuery($frame, $copy)->selectRaw('1')); }
        abort_unless($barrier->exists(), 404, 'Original meeting notice evidence changed.');
    }

    private function assertOwnRelation(MorphMany $notifications, User $viewer): void
    {
        $parent = $notifications->getParent();
        if (! $parent instanceof User || ! $notifications->getRelated() instanceof DatabaseNotification
            || (int) $parent->id !== (int) $viewer->id || $parent->getMorphClass() !== $viewer->getMorphClass()
            || $notifications->getQuery()->getQuery()->from !== 'notifications'
            || $notifications->getQuery()->getQuery()->getConnection() !== DB::connection()) {
            throw new LogicException('Participation notices require the canonical own database notification relation.');
        }
    }

    private function ownQuery(array $frame): QueryBuilder
    {
        return $frame['connection']->table('notifications')->lock('for share nowait')->where('notifications.notifiable_type', $frame['morph'])
            ->where('notifications.notifiable_id', $frame['viewer_id']);
    }

    private function privateMarker(QueryBuilder $query, bool $deliverySchema): void
    {
        $data = "CASE WHEN JSON_VALID(notifications.data) THEN notifications.data ELSE '{}' END";
        $query->whereIn('notifications.type', [CommitteeMeetingScheduled::class, WorkerParticipationMeetingNotice::class])
            ->orWhereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT($data, '$.type')), '') = ?", ['hs_committee_meeting'])
            ->orWhereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT($data, '$.kind')), '') = ?", [WorkerParticipationMeetingNotice::KIND]);
        if ($deliverySchema) {
            $query->orWhereExists(fn (QueryBuilder $delivery) => $delivery->selectRaw('1')->lock('for share nowait')->from('worker_participation_meeting_deliveries as private_delivery')
                ->whereColumn('private_delivery.id', 'notifications.id'));
        }
    }

    private function copyQuery(array $frame, array $copy): QueryBuilder
    {
        $query = $this->ownQuery($frame);
        $this->copyPredicates($query, $frame, $copy);
        return $query;
    }

    private function copyPredicates(QueryBuilder $query, array $frame, array $copy): void
    {
        $candidate = $copy['candidate'];
        $query->where('notifications.id', $candidate->id)->where('notifications.notifiable_id', $frame['viewer_id'])
            ->whereRaw('BINARY notifications.notifiable_type = BINARY ?', [$frame['morph']])
            ->whereRaw('BINARY notifications.type = BINARY ?', [WorkerParticipationMeetingNotice::class]);
        $this->payload($query, $copy['payload']);
        $query->whereExists($this->sourceBlock($candidate, $copy['origin'], $copy['event']));
        if ($frame['context'] !== null) {
            $authority = $frame['context']->originalSourceAuthorityQuery(WorkerParticipationRecipientSourceService::PERMISSIONS, [])->selectRaw('1');
            // Grammar compilation of an embedded EXISTS does not invoke its beforeQuery
            // callbacks. Set its original current-read locks now and retain the Builder,
            // including its original compiler lifetime/date Expression, in the actual SQL.
            $authority->toSql();
            $query->whereExists($authority->toBase());
        }
    }

    /** The origin is applied only inside its canonical meeting block, correlated to immutable delivery evidence. */
    private function sourceBlock(object $candidate, WorkerParticipationRecipientSourceOrigin $origin, ?array $event = null): QueryBuilder
    {
        $source = DB::table('hs_committee_meetings')->selectRaw('1')->lock('for share nowait')
            ->join('hs_committees as source_committee', 'source_committee.id', '=', 'hs_committee_meetings.hs_committee_id')
            ->join('worker_participation_meeting_events as source_event', 'source_event.meeting_id', '=', 'hs_committee_meetings.id')
            ->join('worker_participation_meeting_deliveries as source_delivery', 'source_delivery.event_id', '=', 'source_event.id')
            ->where('source_delivery.id', $candidate->id)->where('source_delivery.recipient_user_id', (int) $candidate->recipient_user_id)
            ->where('source_delivery.audience', $candidate->audience)->where('source_delivery.database_state', 'delivered')->whereNotNull('source_delivery.database_delivered_at')
            ->where('source_delivery.database_delivered_at', $candidate->database_delivered_at)
            ->where('source_event.id', $candidate->event_id)->where('source_event.source_epoch_id', $candidate->source_epoch_id)
            ->where('source_event.source_digest', $candidate->source_digest)->where('source_event.committee_id', (int) $candidate->committee_id)
            ->whereColumn('source_event.committee_id', 'source_committee.id')
            ->whereRaw('source_event.site_id <=> source_committee.site_id')
            // Each negative/current epoch block is a locking read, including standalone
            // inbox readers running inside an existing repeatable-read transaction.
            ->whereExists(fn (QueryBuilder $latest) => $latest->selectRaw('1')->lock('for share nowait')
                ->from('worker_participation_meeting_events as current_epoch')
                ->whereColumn('current_epoch.meeting_id', 'source_event.meeting_id')
                ->whereColumn('current_epoch.source_epoch_id', 'source_event.source_epoch_id')
                ->whereNotExists(fn (QueryBuilder $newer) => $newer->selectRaw('1')->lock('for share nowait')
                    ->from('worker_participation_meeting_events as newer_epoch')
                    ->whereColumn('newer_epoch.meeting_id', 'current_epoch.meeting_id')
                    ->whereColumn('newer_epoch.sequence', '>', 'current_epoch.sequence')))
            ->whereExists(fn (QueryBuilder $epoch) => $epoch->selectRaw('1')->lock('for share nowait')->from('worker_participation_meeting_events as source_epoch')
                ->whereColumn('source_epoch.id', 'source_event.source_epoch_id')->whereColumn('source_epoch.source_epoch_id', 'source_epoch.id')
                ->whereColumn('source_epoch.meeting_id', 'source_event.meeting_id')->whereColumn('source_epoch.source_digest', 'source_event.source_digest'))
            ->whereRaw("JSON_LENGTH(source_event.source_descriptor) = 7 AND JSON_TYPE(source_event.source_descriptor) = 'OBJECT'");
        $candidate->site_id === null ? $source->whereNull('source_event.site_id') : $source->where('source_event.site_id', (int) $candidate->site_id);
        foreach (['meeting_id' => 'hs_committee_meetings.id', 'committee_id' => 'source_committee.id', 'site_id' => 'source_committee.site_id',
            'scheduled_at' => 'hs_committee_meetings.scheduled_at', 'meeting_status' => 'hs_committee_meetings.status',
            'committee_status' => 'source_committee.status', 'location' => 'hs_committee_meetings.location'] as $key => $column) {
            $value = "JSON_EXTRACT(source_event.source_descriptor, '$.$key')";
            $source->whereRaw("((JSON_TYPE($value) = 'NULL' AND $column IS NULL) OR (JSON_TYPE($value) = ? AND BINARY JSON_UNQUOTE($value) = BINARY CAST($column AS CHAR)))", [in_array($key, ['meeting_id', 'committee_id', 'site_id'], true) ? 'INTEGER' : 'STRING']);
        }
        if ($event !== null) {
            foreach ($event as $column => $value) {
                if ($value === null) { $source->whereNull('source_event.'.$column); }
                elseif (is_string($value)) { $source->whereRaw('BINARY '.$source->getGrammar()->wrap('source_event.'.$column).' = BINARY ?', [$value]); }
                else { $source->where('source_event.'.$column, $value); }
            }
        }
        return $origin->applySourcePredicate($source);
    }

    private function payload(QueryBuilder $query, array $payload): void
    {
        $data = "CASE WHEN JSON_VALID(notifications.data) THEN notifications.data ELSE '{}' END";
        $query->whereRaw("JSON_VALID(notifications.data) AND JSON_TYPE($data) = 'OBJECT' AND JSON_LENGTH($data) = ?", [count($payload)]);
        foreach ($payload as $key => $value) {
            $json = "JSON_EXTRACT($data, '$.$key')";
            if ($value === null) { $query->whereRaw("JSON_TYPE($json) = 'NULL'"); }
            elseif (is_int($value)) { $query->whereRaw("JSON_TYPE($json) = 'INTEGER' AND $json = CAST(? AS JSON)", [json_encode($value, JSON_THROW_ON_ERROR)]); }
            else { $query->whereRaw("JSON_TYPE($json) = 'STRING' AND BINARY JSON_UNQUOTE($json) = BINARY ?", [$value]); }
        }
    }

    private function validEvent(object $event, mixed $descriptor, mixed $membership, object $candidate): bool
    {
        $keys = ['meeting_id', 'committee_id', 'site_id', 'scheduled_at', 'meeting_status', 'committee_status', 'location'];
        if (! is_array($descriptor) || ! is_array($membership) || ! array_is_list($membership)
            || count($descriptor) !== count($keys) || array_diff($keys, array_keys($descriptor)) !== []
            || ! in_array($event->purpose, ['scheduled', 'rescheduled', 'attendees_added'], true)
            || ! preg_match('/^[a-f0-9]{64}$/D', $event->source_digest)
            || ! hash_equals($event->source_digest, WorkerParticipationMeetingDeliveryService::digest($descriptor))
            || ! hash_equals($event->audience_digest, WorkerParticipationMeetingDeliveryService::digest($membership))) { return false; }
        $matches = false; $previous = null;
        foreach ($membership as $member) {
            if (! is_array($member) || count($member) !== 2 || ! isset($member['recipient_id'], $member['audience'])
                || ! is_int($member['recipient_id']) || $member['recipient_id'] <= 0
                || ! in_array($member['audience'], ['attendee', 'workforce_notice'], true)) { return false; }
            $identity = [$member['recipient_id'], $member['audience']];
            if ($previous !== null && $identity <= $previous) { return false; }
            $previous = $identity;
            $matches = $matches || ($member['recipient_id'] === (int) $candidate->recipient_user_id && $member['audience'] === $candidate->audience);
        }
        return $matches;
    }

    private function validCandidate(object $candidate): bool
    {
        return (int) $candidate->meeting_id > 0 && (int) $candidate->committee_id > 0
            && in_array($candidate->audience, ['attendee', 'workforce_notice'], true)
            && Uuid::isValid($candidate->id) && Uuid::isValid($candidate->event_id) && Uuid::isValid($candidate->source_epoch_id)
            && preg_match('/^[a-f0-9]{64}$/D', $candidate->source_digest) === 1
            && WorkerParticipationMeetingDeliveryService::notificationId($candidate->event_id, (int) $candidate->recipient_user_id, $candidate->audience) === $candidate->id;
    }
}
