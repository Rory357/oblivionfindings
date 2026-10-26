<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureHsProjection;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Job 3 (Stephan, 26 Oct): the system delivers each saved exposure to the H&S register straight after the save, so the
 * event and its WorkSafe review never wait for a manager. It checks the record, not a person: the delivery belongs to
 * the current revision, every accepted version, receipt and audit anchor is intact, the original incident context still
 * matches, and the event still matches its last marker. It writes the same projection, response floor, marker and
 * audit as Codex's manager delivery, under its own audit action, with the command's actor recorded.
 *
 * A failed attempt is kept: lock clashes and errors retry 30 s doubling to 15 min; an integrity refusal stops and waits
 * for a manager's retry. Follow-up notices stay off (Stephan), so only Health and Safety rows are delivered here.
 */
final class ChemicalExposureSystemDeliveryService
{
    /** A pending delivery older than this counts as stuck even before it fails. */
    public const STUCK_AFTER_MINUTES = 10;

    private const RETRY_BASE_SECONDS = 30;
    private const RETRY_CAP_SECONDS = 900;
    /** Recovery leaves a just-saved row alone this long so it never races the inline delivery. */
    private const INLINE_GRACE_SECONDS = 30;

    public function __construct(private readonly ChemicalExposureLedger $ledger,
        private readonly ChemicalExposureSourceService $source, private readonly HsEventReviewFlagService $flags) {}

    public function enabled(): bool
    {
        return (bool) config('health_safety.exposure_automatic_delivery', true);
    }

    /** Straight after the exposure's save commits. Newest first, so older revisions are simply superseded. Never throws. */
    public function afterCommit(int $exposureId): void
    {
        if (! $this->enabled()) { return; }
        $ids = ChemicalExposureSourceDelivery::query()->where('exposure_id', $exposureId)->where('kind', 'health_safety')
            ->where('state', 'pending')->where('needs_manager', false)->orderByDesc('id')->pluck('id');
        foreach ($ids as $id) { $this->attempt((int) $id); }
    }

    /** Scheduled every minute: rows whose retry is due, and rows the inline delivery never reached. */
    public function recoverDue(int $limit = 30): array
    {
        $outcomes = [];
        if (! $this->enabled()) { return $outcomes; }
        $now = now();
        $ids = ChemicalExposureSourceDelivery::query()->where('state', 'pending')->where('kind', 'health_safety')
            ->where('needs_manager', false)
            ->where(fn (Builder $due) => $due->where('next_attempt_at', '<=', $now)
                ->orWhere(fn (Builder $never) => $never->whereNull('next_attempt_at')
                    ->where('created_at', '<=', $now->copy()->subSeconds(self::INLINE_GRACE_SECONDS))))
            ->orderByDesc('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) { $outcomes[(int) $id] = $this->attempt((int) $id); }
        return $outcomes;
    }

    /** One attempt in its own transaction. Returns delivered, superseded, failed or the row's settled state. */
    public function attempt(int $deliveryId): string
    {
        try {
            return DB::transaction(fn (): string => $this->deliverOnce($deliveryId));
        } catch (Throwable $error) {
            $this->recordFailure($deliveryId, $error);
            return 'failed';
        }
    }

    /** The backoff after the nth consecutive failure: 30 s, 1, 2, 4, 8 min, then every 15 min. */
    public static function backoffSeconds(int $failures): int
    {
        return min(self::RETRY_CAP_SECONDS, self::RETRY_BASE_SECONDS * (2 ** min(5, max(0, $failures - 1))));
    }

    /** Stuck = a failed attempt, or still undelivered after 10 minutes. Superseded and delivered rows never count. */
    public static function stuckQuery(): Builder
    {
        return ChemicalExposureSourceDelivery::query()->where('kind', 'health_safety')->where('state', 'pending')
            ->where(fn (Builder $stuck) => $stuck->where('failed_attempts', '>', 0)
                ->orWhere('created_at', '<=', now()->subMinutes(self::STUCK_AFTER_MINUTES)));
    }

    private function deliverOnce(int $id): string
    {
        $delivery = ChemicalExposureSourceDelivery::query()->whereKey($id)->lock('for update nowait')->first();
        if ($delivery === null) { throw new ModelNotFoundException('Exposure delivery is unavailable.'); }
        if ($delivery->kind !== 'health_safety' || $delivery->recipient_id !== null) {
            throw new LogicException('Only Health and Safety deliveries are automatic.');
        }
        if ($delivery->state !== 'pending') { return (string) $delivery->state; }
        $root = SubstanceExposureRecord::query()->whereKey($delivery->exposure_id)->lock('for update nowait')->first();
        if ($root === null) { throw new LogicException('Exposure delivery source is unavailable.'); }
        if ((int) $root->source_schema_version !== 1 || (int) $root->source_revision < 1) {
            throw new LogicException('Exposure delivery source has no governed provenance.');
        }
        $versions = ChemicalExposureSourceVersion::query()->where('exposure_id', $root->id)->orderBy('aggregate_revision')->get()->all();
        $latest = $versions === [] ? null : $versions[count($versions) - 1];
        if ($latest === null || $latest->aggregate_revision !== (int) $root->source_revision) {
            throw new LogicException('Exposure current source version is missing.');
        }
        $membership = ['exposure_id' => (int) $root->id, 'substance_id' => (int) $root->hazardous_substance_id,
            'worker_id' => (int) $root->user_id, 'site_id' => (int) $root->site_id, 'source_revision' => (int) $root->source_revision,
            'source_version_id' => (int) $latest->id, 'original_context' => $root->original_context];
        if (ChemicalCommandValues::digest($membership) !== ChemicalCommandValues::digest($delivery->membership)
            || $delivery->source_version_id !== (int) $latest->id) {
            // A later save carries this delivery's facts forward; the older revision is never sent.
            $delivery->forceFill(['state' => 'superseded', 'next_attempt_at' => null, 'needs_manager' => false]);
            $this->source->save($delivery);
            return 'superseded';
        }
        if ($delivery->actor_id !== $latest->actor_id) { throw new LogicException('Exposure delivery original actor is damaged.'); }
        $history = $this->acceptedHistory($root, $versions);
        if (ChemicalCommandValues::digest($latest->content['root_after'] ?? null) !== ChemicalCommandValues::digest($this->ledger->snapshot($root))) {
            throw new LogicException('Exposure current source facts differ from the immutable acceptance.');
        }
        $this->assertOriginalContext($root);
        $floor = $this->responseFloor($root, $latest, $history);
        $first = $history[0];
        $desired = ChemicalExposureHsDestinationRules::projection($latest, $first);

        $markers = ChemicalExposureHsProjection::query()->where('exposure_id', $root->id);
        $previous = (clone $markers)->orderByDesc('source_revision')->first();
        $events = HsEvent::withTrashed()->where(function (Builder $scope) use ($root, $desired): void {
            $scope->where('idempotency_key', $desired['idempotency_key'])->orWhere(fn (Builder $source) => $source
                ->whereIn('source_type', $this->sourceTypes())->where('source_id', $root->id)
                ->where('event_category', HsEvent::CATEGORY_EXPOSURE));
        })->lock('for update nowait')->get();
        if ($events->count() > 1) { throw new LogicException('Exposure has conflicting retained H&S event identities.'); }
        $event = $events->first();
        $review = null;
        if ($previous === null) {
            if ($event !== null) { throw new LogicException('An unproved legacy H&S copy cannot be adopted or overwritten.'); }
            $event = new HsEvent([...$desired, 'reference_number' => HsEvent::generateReferenceNumber(),
                'severity' => $floor, 'status' => HsEvent::STATUS_OPEN,
                'worksafe_notifiable' => null, 'worksafe_status' => null,
                'investigation_required' => in_array($floor, [HsEvent::SEVERITY_HIGH, HsEvent::SEVERITY_CRITICAL], true),
                'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED, 'created_by' => $first->actor_id]);
        } else {
            if ($event === null || $event->trashed() || (int) $event->id !== $previous->hs_event_id) {
                throw new LogicException('The retained exposure destination is missing or changed.');
            }
            $this->assertMarker($previous, $this->versionFromHistory($history, $previous->source_version_id), $first);
            if (ChemicalCommandValues::digest(ChemicalExposureHsDestinationRules::eventProjection($event)) !== $previous->projection_digest) {
                throw new LogicException('The H&S source tuple changed outside its governed exposure projection.');
            }
            if ($previous->source_revision > $latest->aggregate_revision) {
                throw new LogicException('The retained destination is ahead of this accepted source.');
            }
            if ($previous->source_revision === $latest->aggregate_revision) {
                // Already delivered for this revision. A destination below its floor needs a manager's audited reconcile.
                if (! ChemicalExposureHsDestinationRules::meetsSeverityFloor($event, $floor)) {
                    throw new LogicException('The H&S destination is below its response floor.');
                }
                return $this->delivered($delivery, $event);
            }
            $review = [clone $event, (int) $previous->source_revision];
            $event->forceFill($desired);
            // Severity and the investigation flag may rise, never fall. Owner, status, handover and WorkSafe are kept.
            ChemicalExposureHsDestinationRules::raiseResponseFloor($event, $floor);
        }
        if (! $event->save()) { throw new LogicException('The required H&S destination save was vetoed.'); }
        $stored = $this->exactEventQuery((int) $event->id, $desired)->firstOrFail();
        if (ChemicalCommandValues::digest(ChemicalExposureHsDestinationRules::eventProjection($stored)) !== ChemicalCommandValues::digest($desired)) {
            throw new LogicException('The exact H&S source projection was not retained.');
        }
        if (! ChemicalExposureHsDestinationRules::meetsSeverityFloor($stored, $floor)) {
            throw new LogicException('The operational source response floor was not retained on its H&S destination.');
        }
        $this->writeMarker($root, $latest, $first, $stored, $desired, (int) $delivery->actor_id,
            ChemicalExposureHsDestinationRules::AUTOMATIC_AUDIT_ACTION);
        if ($review !== null) {
            [$before, $previousRevision] = $review;
            $this->flags->afterSourceChange($before, $root, (int) $latest->aggregate_revision, $stored->severity,
                ChemicalExposureHsDestinationRules::correctedAfterDecision($before, $history, $previousRevision, $latest));
        }
        return $this->delivered($delivery, $stored);
    }

    /**
     * One-off (job 3, Stephan 26 Oct): adopt the H&S event the restored observer created for a governed exposure before
     * the automatic delivery existed, so later corrections reach it. Only an exact match is adopted: one live event
     * whose source, Site, worker, incident context and times equal the exposure's first accepted version, at or above
     * its response floor. Anything else is listed for repair and left untouched. Then the newest revision is delivered.
     *
     * @return array{state: string, hs_event_id?: int, reasons?: list<string>}
     */
    public function adopt(int $exposureId, bool $dryRun): array
    {
        $result = DB::transaction(function () use ($exposureId, $dryRun): array {
            $root = SubstanceExposureRecord::query()->whereKey($exposureId)->lock('for update nowait')->first();
            if ($root === null || (int) $root->source_schema_version !== 1) { return ['state' => 'not_governed']; }
            if (ChemicalExposureHsProjection::query()->where('exposure_id', $root->id)->exists()) { return ['state' => 'not_needed']; }
            $events = HsEvent::withTrashed()->where(function (Builder $scope) use ($root): void {
                $scope->where('idempotency_key', HsEvent::buildIdempotencyKey(SubstanceExposureRecord::class, $root->id, HsEvent::CATEGORY_EXPOSURE))
                    ->orWhere(fn (Builder $source) => $source->whereIn('source_type', $this->sourceTypes())
                        ->where('source_id', $root->id)->where('event_category', HsEvent::CATEGORY_EXPOSURE));
            })->lock('for update nowait')->get();
            if ($events->isEmpty()) { return ['state' => 'not_needed']; }
            if ($events->count() > 1) { return ['state' => 'needs_repair', 'reasons' => ['more than one H&S event for this exposure']]; }
            $event = $events->first();
            if ($event->trashed()) { return ['state' => 'needs_repair', 'hs_event_id' => (int) $event->id, 'reasons' => ['the H&S event is deleted']]; }
            try {
                $versions = ChemicalExposureSourceVersion::query()->where('exposure_id', $root->id)->orderBy('aggregate_revision')->get()->all();
                $history = $this->acceptedHistory($root, $versions);
                $latest = $history[count($history) - 1];
                if ($latest->aggregate_revision !== (int) $root->source_revision
                    || ChemicalCommandValues::digest($latest->content['root_after'] ?? null) !== ChemicalCommandValues::digest($this->ledger->snapshot($root))) {
                    throw new LogicException('the exposure differs from its last accepted version');
                }
                $this->assertOriginalContext($root);
            } catch (LogicException $damaged) {
                return ['state' => 'needs_repair', 'hs_event_id' => (int) $event->id, 'reasons' => [$damaged->getMessage()]];
            }
            $first = $history[0];
            $expected = ChemicalExposureHsDestinationRules::projection($first, $first);
            $actual = ChemicalExposureHsDestinationRules::eventProjection($event);
            $differs = array_values(array_filter(array_keys($expected),
                fn (string $key) => ChemicalCommandValues::digest($actual[$key] ?? null) !== ChemicalCommandValues::digest($expected[$key])));
            if ($differs !== []) {
                return ['state' => 'needs_repair', 'hs_event_id' => (int) $event->id, 'reasons' => ['differs from the first saved version: '.implode(', ', $differs)]];
            }
            $tuple = ['exposure_id' => (int) $first->exposure_id, 'worker_id' => (int) $first->worker_id,
                'site_id' => (int) $first->site_id, 'substance_id' => (int) $first->substance_id];
            $contract = $first->content['facts']['reported_facts'] ?? ChemicalExposureReportedFacts::legacy($first->content['root_after']);
            $floor = ChemicalExposureReportedFacts::response($contract, $tuple)['response_floor'];
            if (! ChemicalExposureHsDestinationRules::meetsSeverityFloor($event, $floor)) {
                return ['state' => 'needs_repair', 'hs_event_id' => (int) $event->id, 'reasons' => ["severity is below the first saved treatment's {$floor}"]];
            }
            if ($dryRun) { return ['state' => 'would_adopt', 'hs_event_id' => (int) $event->id]; }
            $this->writeMarker($root, $first, $first, $event, $expected, (int) $first->actor_id,
                ChemicalExposureHsDestinationRules::ADOPTION_AUDIT_ACTION);
            // Deliveries that stopped on the unproved copy can now go; the newest revision is delivered next.
            DB::table('chemical_exposure_source_deliveries')->where('exposure_id', $root->id)->where('kind', 'health_safety')
                ->where('state', 'pending')->update(['needs_manager' => false, 'failure_code' => null, 'next_attempt_at' => null,
                    'updated_at' => Carbon::now()->format('Y-m-d H:i:s.u')]);
            return ['state' => 'adopted', 'hs_event_id' => (int) $event->id];
        });
        if ($result['state'] === 'adopted') { $this->afterCommit($exposureId); }
        return $result;
    }

    /** The marker and its audit, exactly as Codex's delivery writes them; the action says who delivered it. */
    private function writeMarker(SubstanceExposureRecord $root, ChemicalExposureSourceVersion $version,
        ChemicalExposureSourceVersion $first, HsEvent $event, array $projection, int $actorId, string $action): void
    {
        $marker = new ChemicalExposureHsProjection(['exposure_id' => (int) $root->id, 'hs_event_id' => (int) $event->id,
            'source_version_id' => (int) $version->id, 'source_revision' => (int) $version->aggregate_revision,
            'source_content_digest' => $version->content_digest, 'projection' => $projection,
            'projection_digest' => ChemicalCommandValues::digest($projection), 'recorded_by' => $actorId,
            'recorded_at' => now()->utc()]);
        $meta = ChemicalExposureHsDestinationRules::markerAuditMeta($marker, $version);
        // The audit names the person whose save this delivers; the action says the system delivered it.
        $request = Request::create('/');
        $request->setUserResolver(fn () => (new User)->forceFill(['id' => $actorId]));
        AuditLogger::logOrFail($action, $root, $meta, $request);
        $audits = AuditLog::query()->where('user_id', $actorId)->where('action', $action)
            ->where('auditable_type', $root->getMorphClass())->where('auditable_id', $root->id)
            ->where('meta->source_version_id', $version->id)->lockForUpdate()->get();
        if ($audits->count() !== 1
            || ChemicalCommandValues::digest(array_intersect_key($audits[0]->meta ?? [], $meta)) !== ChemicalCommandValues::digest($meta)) {
            throw new LogicException('The exact exposure destination audit was not retained.');
        }
        $marker->audit_log_id = (int) $audits[0]->id;
        $this->source->save($marker);
        $this->assertMarker($marker, $version, $first);
    }

    private function delivered(ChemicalExposureSourceDelivery $delivery, HsEvent $event): string
    {
        $delivery->forceFill(['state' => 'delivered', 'attempts' => $delivery->attempts + 1, 'hs_event_id' => (int) $event->id,
            'failure_code' => null, 'needs_manager' => false, 'next_attempt_at' => null, 'last_attempted_at' => now()]);
        $this->source->save($delivery);
        return 'delivered';
    }

    /** Every accepted aggregate advance stays connected and anchored, exactly as Codex's delivery requires. */
    private function acceptedHistory(SubstanceExposureRecord $root, array $versions): array
    {
        $history = [];
        $before = null;
        foreach ($versions as $version) {
            $this->ledger->assertDigest($version);
            $receipt = ChemicalExposureCommandReceipt::query()->where('exposure_id', $root->id)
                ->where('source_version_id', $version->id)->first();
            $intent = $receipt === null ? null : ChemicalCommandIntent::query()->where('source_type', 'exposure')
                ->whereKey($receipt->intent_id)->first();
            if ($receipt === null || $intent === null) { throw new LogicException('Exposure delivery history has no exact acceptance.'); }
            $this->assertAcceptance($receipt, $intent, $version, $root);
            if ($version->aggregate_revision !== count($history) + 1
                || ($before === null && ($version->command !== 'create_exposure' || $version->expected_version !== null
                    || ($version->content['root_before'] ?? null) !== null))
                || ($before !== null && ($version->expected_version !== $before->aggregate_revision
                    || ChemicalCommandValues::digest($version->content['root_before'] ?? null)
                        !== ChemicalCommandValues::digest($before->content['root_after'] ?? null)))) {
                throw new LogicException('Exposure delivery history is incomplete or has a substituted original context.');
            }
            $history[] = $version;
            $before = $version;
        }
        if ($history === []) { throw new LogicException('Exposure delivery cannot confirm an omitted accepted revision.'); }
        return $history;
    }

    /** The ledger's acceptance checks without a person's current Site admission: receipt, intent, audit anchor, version. */
    private function assertAcceptance(ChemicalExposureCommandReceipt $r, ChemicalCommandIntent $intent,
        ChemicalExposureSourceVersion $version, SubstanceExposureRecord $root): void
    {
        $binding = $r->source_tuple;
        if (! is_array($binding) || array_diff(array_keys($binding), ['before', 'after']) !== []
            || array_diff(['before', 'after'], array_keys($binding)) !== [] || ! is_array($binding['after'])
            || ! is_string($r->source_tuple_digest) || ! hash_equals($r->source_tuple_digest, ChemicalCommandValues::digest($binding))) {
            throw new LogicException('Exposure original receipt source metadata is damaged.');
        }
        $result = $r->accepted_result;
        if (! is_array($result) || array_diff(ChemicalExposureLedger::RECEIPT_KEYS, array_keys($result)) !== []
            || $r->intent_id !== (int) $intent->id || $intent->source_type !== 'exposure'
            || $intent->actor_id !== $r->actor_id || $intent->command !== $r->command
            || $intent->idempotency_key !== $r->idempotency_key || $intent->expected_version !== $r->expected_version
            || ! hash_equals($r->fingerprint, $intent->fingerprint) || ($result['accepted'] ?? null) !== true
            || ($result['changed'] ?? null) !== true || ! is_int($result['version'] ?? null)) {
            throw new LogicException('Exposure retained acceptance identity is unconfirmed.');
        }
        $identity = ['actor_id' => $r->actor_id, 'command' => $r->command, 'idempotency_key' => $r->idempotency_key,
            'root_type' => 'exposure', 'root_id' => $r->exposure_id, 'source_type' => $r->source_type, 'source_id' => $r->source_id,
            'expected_version' => $r->expected_version, 'source_version_id' => $r->source_version_id];
        foreach ($identity as $key => $value) {
            if ($result[$key] !== $value) { throw new LogicException('Exposure receipt identity was altered.'); }
        }
        $meta = [...array_diff_key(array_intersect_key($result, array_flip(ChemicalExposureLedger::RECEIPT_KEYS)), ['accepted' => true, 'command' => true]),
            'source_content_digest' => $r->source_content_digest, 'source_tuple_digest' => $r->source_tuple_digest];
        $audit = AuditLog::query()->whereKey($r->audit_log_id)->first();
        if (! $audit || (int) $audit->user_id !== $r->actor_id || $audit->action !== 'chemical.exposure.'.$r->command
            || $audit->auditable_type !== $root->getMorphClass() || (int) $audit->auditable_id !== $r->exposure_id
            || ChemicalCommandValues::digest(array_intersect_key($audit->meta ?? [], $meta)) !== ChemicalCommandValues::digest($meta)) {
            throw new LogicException('Exposure acceptance audit is missing or damaged.');
        }
        if ($result['version'] !== ($r->expected_version === null ? 1 : $r->expected_version + 1)
            || $r->source_version_id !== (int) $version->id || $r->exposure_id !== (int) $root->id
            || ! is_string($r->source_content_digest) || ! hash_equals($r->source_content_digest, $version->content_digest)
            || $version->exposure_id !== $r->exposure_id || $version->source_type !== $r->source_type
            || $version->source_id !== $r->source_id || $version->aggregate_revision !== $result['version']
            || $version->actor_id !== $r->actor_id || $version->intent_id !== $intent->id
            || $version->command !== $r->command || $version->idempotency_key !== $r->idempotency_key
            || $version->expected_version !== $r->expected_version
            || ($version->content['intent_fingerprint'] ?? null) !== $intent->fingerprint) {
            throw new LogicException('Exposure acceptance version is unconfirmed.');
        }
        $tuple = fn (array $snapshot): array => ['exposure_id' => $snapshot['id'], 'substance_id' => $snapshot['hazardous_substance_id'],
            'worker_id' => $snapshot['user_id'], 'site_id' => $snapshot['site_id'],
            'related_incident_id' => $snapshot['related_incident_id'] ?? null, 'original_context' => $snapshot['original_context'] ?? null];
        $accepted = ['before' => is_array($version->content['root_before'] ?? null) ? $tuple($version->content['root_before']) : null,
            'after' => $tuple($version->content['root_after'])];
        if (ChemicalCommandValues::digest($accepted) !== $r->source_tuple_digest) {
            throw new LogicException('Exposure receipt original tuple is not bound to its accepted version.');
        }
    }

    /** The original incident context is still the incident's own, at the exposure's Site. No person's access is read. */
    private function assertOriginalContext(SubstanceExposureRecord $root): void
    {
        $context = $root->original_context;
        $keys = ['schema_version', 'site_id', 'incident_id', 'client_id', 'shift_id', 'service_context_id', 'stay_id'];
        if (! is_array($context) || array_diff(array_keys($context), $keys) !== [] || array_diff($keys, array_keys($context)) !== []
            || $context['schema_version'] !== 1 || $context['site_id'] !== (int) $root->site_id
            || $context['incident_id'] !== ($root->related_incident_id === null ? null : (int) $root->related_incident_id)) {
            throw new LogicException('Exposure original context metadata is malformed.');
        }
        if ($context['incident_id'] === null) {
            if ($context['client_id'] !== null || $context['shift_id'] !== null || $context['service_context_id'] !== null || $context['stay_id'] !== null) {
                throw new LogicException('Exposure original context has no incident for its people.');
            }
            return;
        }
        $incident = ClientIncident::query()->whereKey($context['incident_id'])->where('site_id', $context['site_id'])
            ->first(['id', 'client_id', 'site_id', 'shift_id', 'service_context_id', 'respite_stay_id']);
        $actual = $incident === null ? null : ['client_id' => (int) $incident->client_id,
            'shift_id' => $incident->shift_id === null ? null : (int) $incident->shift_id,
            'service_context_id' => $incident->service_context_id === null ? null : (int) $incident->service_context_id,
            'stay_id' => $incident->respite_stay_id === null ? null : (int) $incident->respite_stay_id];
        $retained = array_intersect_key($context, array_flip(['client_id', 'shift_id', 'service_context_id', 'stay_id']));
        if ($actual === null || ChemicalCommandValues::digest($actual) !== ChemicalCommandValues::digest($retained)) {
            throw new LogicException('Exposure original context differs from its current canonical source.');
        }
    }

    /** Codex's reported-fact proof: each reported answer belongs to its original accepted actor, tuple and value. */
    private function responseFloor(SubstanceExposureRecord $root, ChemicalExposureSourceVersion $latest, array $history): string
    {
        $facts = $latest->content['facts'] ?? [];
        if (! is_array($facts)) { throw new LogicException('Exposure reported-fact container is damaged.'); }
        if (! array_key_exists('reported_facts', $facts)) {
            foreach ($history as $version) {
                if (is_array($version->content['facts'] ?? null) && array_key_exists('reported_facts', $version->content['facts'])) {
                    throw new LogicException('Retained reported facts cannot be downgraded to an unconfirmed legacy map.');
                }
            }
            return ChemicalExposureHsDestinationRules::responseFloor($root, $latest);
        }
        $contract = $facts['reported_facts'];
        if (! is_array($contract)) { throw new LogicException('Exposure reported-fact contract is damaged.'); }
        ChemicalExposureReportedFacts::assertContract($contract, $latest->content['root_after']);
        foreach (ChemicalExposureReportedFacts::FIELDS as $field) {
            $entry = $contract[$field];
            $evidence = $entry['reported_in'];
            if ($evidence === null) { continue; }
            $revision = $evidence['revision'] ?? null;
            if (($evidence['exposure_id'] ?? null) !== (int) $root->id || ! is_int($revision) || $revision < 1
                || $revision > $latest->aggregate_revision) {
                throw new LogicException('Reported fact does not belong to this accepted source history.');
            }
            $original = $history[$revision - 1];
            if (! in_array($original->command, ['create_exposure', 'correct_exposure'], true)) {
                throw new LogicException('A child command cannot claim an original reported answer.');
            }
            $originalContract = $original->content['facts']['reported_facts'] ?? null;
            if (! is_array($originalContract)) { throw new LogicException('Reported fact original field contract is missing.'); }
            ChemicalExposureReportedFacts::assertContract($originalContract, $original->content['root_after']);
            $originalIdentity = ['intent_id' => (int) $original->intent_id, 'revision' => (int) $original->aggregate_revision,
                'actor_id' => (int) $original->actor_id, 'exposure_id' => (int) $original->exposure_id,
                'worker_id' => (int) $original->worker_id, 'site_id' => (int) $original->site_id, 'substance_id' => (int) $original->substance_id];
            if (ChemicalCommandValues::digest($evidence) !== ChemicalCommandValues::digest($originalIdentity)
                || ChemicalCommandValues::digest($entry) !== ChemicalCommandValues::digest($originalContract[$field])) {
                throw new LogicException('Reported field differs from its ORIGINAL accepted actor, tuple or value.');
            }
        }
        return ChemicalExposureHsDestinationRules::responseFloor($root, $latest);
    }

    private function assertMarker(ChemicalExposureHsProjection $marker, ChemicalExposureSourceVersion $version,
        ChemicalExposureSourceVersion $first): void
    {
        $expected = ChemicalExposureHsDestinationRules::projection($version, $first);
        if ($marker->exposure_id !== $version->exposure_id || $marker->source_version_id !== (int) $version->id
            || $marker->source_revision !== $version->aggregate_revision || $marker->source_content_digest !== $version->content_digest
            || ChemicalCommandValues::digest($marker->projection) !== ChemicalCommandValues::digest($expected)
            || $marker->projection_digest !== ChemicalCommandValues::digest($expected)) {
            throw new LogicException('Exposure destination provenance is damaged or rebound.');
        }
        $meta = ChemicalExposureHsDestinationRules::markerAuditMeta($marker, $version);
        $audit = AuditLog::query()->whereKey($marker->audit_log_id)->first();
        if ($audit === null || (int) $audit->user_id !== $marker->recorded_by
            || ! in_array($audit->action, ChemicalExposureHsDestinationRules::MARKER_AUDIT_ACTIONS, true)
            || $audit->auditable_type !== (new SubstanceExposureRecord)->getMorphClass()
            || (int) $audit->auditable_id !== $marker->exposure_id
            || ChemicalCommandValues::digest(array_intersect_key($audit->meta ?? [], $meta)) !== ChemicalCommandValues::digest($meta)) {
            throw new LogicException('Exposure destination acceptance audit is missing or changed.');
        }
    }

    private function versionFromHistory(array $history, int $id): ChemicalExposureSourceVersion
    {
        foreach ($history as $version) { if ((int) $version->id === $id) { return $version; } }
        throw new LogicException('Exposure destination has no retained original source acceptance.');
    }

    private function exactEventQuery(int $id, array $projection): Builder
    {
        $query = HsEvent::query()->whereKey($id);
        foreach ($projection as $field => $value) { $query->where($field, $value); }
        return $query;
    }

    private function sourceTypes(): array
    {
        return array_values(array_unique([SubstanceExposureRecord::class, (new SubstanceExposureRecord)->getMorphClass()]));
    }

    private function recordFailure(int $id, Throwable $error): void
    {
        $code = $this->failureCode($error);
        // Retrying cannot repair damaged or unproved provenance; a manager must look.
        $needsManager = in_array($code, ['integrity', 'unavailable'], true);
        try {
            $row = ChemicalExposureSourceDelivery::query()->whereKey($id)->first(['id', 'exposure_id', 'state', 'failed_attempts']);
            if ($row === null || $row->state !== 'pending') { return; }
            $failures = (int) $row->failed_attempts + 1;
            DB::table('chemical_exposure_source_deliveries')->where('id', $id)->where('state', 'pending')->update([
                'failed_attempts' => $failures, 'failure_code' => $code, 'needs_manager' => $needsManager,
                'last_attempted_at' => Carbon::now()->format('Y-m-d H:i:s.u'),
                'next_attempt_at' => $needsManager ? null : Carbon::now()->addSeconds(self::backoffSeconds($failures))->format('Y-m-d H:i:s.u'),
                'updated_at' => Carbon::now()->format('Y-m-d H:i:s.u'),
            ]);
            // Codes and ids only: a database error message can carry private bound values.
            Log::warning('Chemical exposure H&S delivery failed', ['delivery_id' => $id, 'exposure_id' => (int) $row->exposure_id,
                'failure' => $code, 'failed_attempts' => $failures, 'needs_manager' => $needsManager,
                'reason' => $error instanceof LogicException ? $error->getMessage() : $error::class]);
        } catch (Throwable $recording) {
            report($recording);
        }
    }

    private function failureCode(Throwable $error): string
    {
        if ($error instanceof QueryException && in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) { return 'busy'; }
        if ($error instanceof ModelNotFoundException) { return 'unavailable'; }
        if ($error instanceof LogicException) { return 'integrity'; }
        return 'error';
    }
}
