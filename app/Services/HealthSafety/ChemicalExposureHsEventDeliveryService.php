<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalCommandIntent;
use App\Models\AuditLog;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureHsProjection;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\HsEvent;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use LogicException;

/** Destination consumer only. The private outbox and current source admission own delivery execution. */
final class ChemicalExposureHsEventDeliveryService
{
    public function __construct(private readonly ChemicalExposureDeliveryService $delivery,
        private readonly ChemicalExposureSourceService $source, private readonly ChemicalExposureAccessService $access,
        private readonly ChemicalExposureLedger $ledger) {}

    public function deliver(User $viewer, int $deliveryId, ?int $expectedExposureId = null): array
    {
        return $this->delivery->deliverForViewer($viewer, $deliveryId, $this->consume(...), $expectedExposureId);
    }

    /** Read-only concrete confirmation in the consuming caller's active evidence. Never dispatches. */
    public function confirmationInContext(User $viewer, int $exposureId, int $deliveryId, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeViewer(ChemicalExposureCommandAccess::PERMISSIONS, function ($current, $reads, $sites) use ($exposureId, $deliveryId): array {
            $this->access->assertRead($current, $reads);
            $identity = $reads->query(\App\Models\ChemicalExposureSourceDelivery::query())->where('exposure_id', $exposureId)
                ->where('kind', 'health_safety')->whereKey($deliveryId)->firstOrFail(['id','exposure_id']);
            $root = $this->access->assertExposure($exposureId, $current, $reads, $sites);
            $proof = $this->source->sourceProofForAuditSubject(\App\Models\ChemicalExposureSourceDelivery::class, (int) $identity->id, $current, $reads, $sites);
            if (($proof['attribution_state'] ?? null) !== 'recorded') { throw new LogicException('Exposure attempt acceptance is unconfirmed.'); }
            $row = $reads->query(\App\Models\ChemicalExposureSourceDelivery::query())->where('exposure_id', $exposureId)
                ->where('kind', 'health_safety')->whereKey($deliveryId)->firstOrFail(['id','source_version_id','membership']);
            $this->assertInspectionRoot($root, $current, $reads, $sites);
            $version = $this->source->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
            $empty = ['href' => null, 'hs_event_id' => null, 'external_delivery' => 'not_attempted'];
            if ($version === null) { throw new LogicException('Exposure has no accepted destination source version.'); }
            if ($row->source_version_id !== (int) $version->id || ($row->membership['source_revision'] ?? null) !== $version->aggregate_revision) {
                return [...$empty, 'destination_confirmation' => 'superseded', 'can_deliver' => false, 'blocked_reason' => 'source_advanced'];
            }
            $history = $this->acceptedHistory($root, $version, $current, $reads, $sites);
            $desired = $this->projection($version, $history[0]);
            $previousIdentity = $reads->query(ChemicalExposureHsProjection::query())->where('exposure_id', $exposureId)
                ->orderByDesc('source_revision')->first(['id']);
            $events = $reads->query(HsEvent::withTrashed())->where(function (Builder $scope) use ($exposureId, $desired): void {
                $scope->where('idempotency_key', $desired['idempotency_key'])->orWhere(fn (Builder $source) => $source
                    ->whereIn('source_type', $this->sourceTypes())->where('source_id', $exposureId)->where('event_category', HsEvent::CATEGORY_EXPOSURE));
            })->get(['id','deleted_at',...array_keys($desired)]);
            if ($events->count() > 1) { throw new LogicException('Exposure has conflicting retained destinations.'); }
            $event = $events->first();
            if ($previousIdentity === null) {
                if ($event !== null) { throw new LogicException('Unproved legacy destination cannot be certified or adopted.'); }
                $this->assertInspectionRoot($root, $current, $reads, $sites);
                return [...$empty, 'destination_confirmation' => 'unconfirmed', 'can_deliver' => true, 'blocked_reason' => null];
            }
            $marker = $reads->query(ChemicalExposureHsProjection::query())->where('exposure_id', $exposureId)->whereKey($previousIdentity->id)->firstOrFail();
            $this->assertMarker($marker, $this->versionFromHistory($history, $marker->source_version_id), $history[0], $reads);
            if ($event === null || $event->trashed() || (int) $event->id !== $marker->hs_event_id
                || ChemicalCommandValues::digest($this->eventProjection($event)) !== $marker->projection_digest) {
                throw new LogicException('The exact retained H&S destination is unavailable or changed.');
            }
            if ($marker->source_revision > $version->aggregate_revision) { throw new LogicException('Destination is ahead of accepted source.'); }
            if ($marker->source_revision < $version->aggregate_revision) {
                $this->assertInspectionRoot($root, $current, $reads, $sites);
                return [...$empty, 'destination_confirmation' => 'unconfirmed', 'can_deliver' => true, 'blocked_reason' => null];
            }
            // Independent response fields are read only after the exact canonical source/time tuple is proved.
            $stored = $this->exactEventQuery((int) $event->id, $desired, $reads)->firstOrFail();
            $floor = $this->responseFloor($root, $current, $reads, $sites);
            if (! $this->meetsSeverityFloor($stored, $floor)) {
                $this->assertInspectionRoot($root, $current, $reads, $sites);
                return [...$empty, 'destination_confirmation' => 'unconfirmed', 'can_deliver' => true,
                    'blocked_reason' => 'response_floor_pending'];
            }
            $this->assertInspectionRoot($root, $current, $reads, $sites);
            return ['destination_confirmation' => 'hs_event_present', 'hs_event_id' => (int) $stored->id,
                'href' => null, 'href_reason' => 'hse_read_composition_unmounted', 'can_deliver' => true, 'blocked_reason' => null, 'external_delivery' => 'not_attempted'];
        });
    }

    /** A private marker audit proves this subject inside the caller's current callback, without returning models. */
    public function sourceProofForAuditSubject(int $id, User $current, CurrentAuthorizationReads $reads, array $sites): ?array
    {
        $this->access->assertRead($current, $reads);
        $identity = $reads->query(ChemicalExposureHsProjection::query())->select(['id', 'exposure_id'])
            ->whereIn('exposure_id', $this->access->readableQuery($current, $reads, $sites)->select('substance_exposure_records.id'))
            ->whereKey($id)->first();
        if ($identity === null) { return null; }
        $root = $this->access->assertExposure((int) $identity->exposure_id, $current, $reads, $sites);
        $proof = $this->source->sourceProof($root, $current, $reads, $sites);
        if (($proof['attribution_state'] ?? null) !== 'recorded' || ($proof['context_binding'] ?? null) !== 'recorded') {
            return ['attribution_state' => 'repair_required', 'source_id' => (int) $root->id];
        }
        try {
            $latest = $this->source->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
            if ($latest === null) { throw new LogicException('Exposure marker lacks a current source acceptance.'); }
            $history = $this->acceptedHistory($root, $latest, $current, $reads, $sites);
            $marker = $reads->query(ChemicalExposureHsProjection::query())->where('exposure_id', $root->id)->whereKey($id)->firstOrFail();
            $this->assertMarker($marker, $this->versionFromHistory($history, $marker->source_version_id), $history[0], $reads);
            $event = $this->exactEventQuery((int) $marker->hs_event_id, $marker->projection, $reads)
                ->first(['id', 'deleted_at', ...array_keys($marker->projection)]);
            if ($event === null || $event->trashed()
                || ChemicalCommandValues::digest($this->eventProjection($event)) !== $marker->projection_digest) {
                throw new LogicException('Exposure marker does not prove its current retained destination.');
            }
            return $proof;
        } catch (LogicException) {
            return ['attribution_state' => 'repair_required', 'source_id' => (int) $root->id];
        }
    }

    /** This callback never changes independent owner, closure, acceptance or signed WorkSafe decisions. */
    private function consume(SubstanceExposureRecord $root, array $suppliedProof, User $current,
        CurrentAuthorizationReads $reads, array $sites, array $membership, string $kind, ?int $recipientId): array
    {
        $reads->assertActive();
        $this->access->assertRead($current, $reads);
        abort_unless($current->canDo('hazards.manage'), 403);
        if ($kind !== 'health_safety' || $recipientId !== null) {
            throw new LogicException('This consumer confirms only Health and Safety delivery.');
        }
        $proof = $this->source->sourceProof((int) $root->id, $current, $reads, $sites);
        if (($proof['attribution_state'] ?? null) !== 'recorded' || ($proof['context_binding'] ?? null) !== 'recorded'
            || ChemicalCommandValues::digest($proof) !== ChemicalCommandValues::digest($suppliedProof)) {
            throw new LogicException('Exposure delivery requires independently proved original context.');
        }
        $version = $this->source->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
        $expectedMembership = ['exposure_id' => (int) $root->id, 'substance_id' => (int) $root->hazardous_substance_id,
            'worker_id' => (int) $root->user_id, 'site_id' => (int) $root->site_id,
            'source_revision' => (int) $root->source_revision, 'source_version_id' => $version?->id,
            'original_context' => $root->original_context];
        if ($version === null || ChemicalCommandValues::digest($membership) !== ChemicalCommandValues::digest($expectedMembership)) {
            throw new LogicException('This destination does not own the supplied source membership.');
        }

        // Reported facts authenticate THIS model in the same active current source evidence.
        $floor = $this->responseFloor($root, $current, $reads, $sites);
        // All metadata SQL below follows the current private grant and exact original source Site admission.
        $markers = $reads->query(ChemicalExposureHsProjection::query())->where('exposure_id', $root->id);
        $previousIdentity = (clone $markers)->orderByDesc('source_revision')->first(['id', 'exposure_id']);
        $history = $this->acceptedHistory($root, $version, $current, $reads, $sites);
        $previous = $previousIdentity === null ? null : (clone $markers)->whereKey($previousIdentity->id)->firstOrFail();
        $first = $history[0];
        $desired = $this->projection($version, $first);
        $key = $desired['idempotency_key'];
        $events = $reads->query(HsEvent::withTrashed())->where(function (Builder $scope) use ($root, $key): void {
            $scope->where('idempotency_key', $key)->orWhere(fn (Builder $source) => $source
                ->whereIn('source_type', $this->sourceTypes())->where('source_id', $root->id)
                ->where('event_category', HsEvent::CATEGORY_EXPOSURE));
        });
        $events->getQuery()->beforeQuery(fn ($compiled) => $compiled->lock('for update nowait'));
        // Classify only destination identity/time before any copied governance narrative.
        $rows = $events->get(['id', 'deleted_at', ...array_keys($desired)]);
        if ($rows->count() > 1) { throw new LogicException('Exposure has conflicting retained H&S event identities.'); }
        $event = $rows->first();
        if ($previous === null) {
            if ($event !== null) { throw new LogicException('An unproved legacy H&S copy cannot be adopted or overwritten.'); }
            $event = new HsEvent([...$desired, 'reference_number' => HsEvent::generateReferenceNumber(),
                'severity' => $floor, 'status' => HsEvent::STATUS_OPEN,
                'worksafe_notifiable' => null, 'worksafe_status' => null,
                // Retain the existing response-floor rule. A care-review flag is NOT an investigation decision.
                'investigation_required' => in_array($floor, [HsEvent::SEVERITY_HIGH, HsEvent::SEVERITY_CRITICAL], true),
                'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED, 'created_by' => $first->actor_id]);
        } else {
            if ($event === null || $event->trashed() || (int) $event->id !== $previous->hs_event_id) {
                throw new LogicException('The retained exposure destination is missing or changed.');
            }
            $old = $this->versionFromHistory($history, $previous->source_version_id);
            $this->assertMarker($previous, $old, $first, $reads);
            if (ChemicalCommandValues::digest($this->eventProjection($event)) !== $previous->projection_digest) {
                throw new LogicException('The H&S source tuple changed outside its governed exposure projection.');
            }
            $admitted = $this->exactEventQuery((int) $event->id, $previous->projection, $reads);
            $admitted->getQuery()->beforeQuery(fn ($compiled) => $compiled->lock('for update nowait'));
            $event = $admitted->firstOrFail();
            if ($previous->source_revision === $version->aggregate_revision) {
                // The immutable marker proves identity/time, not a clinical or response assessment.
                // Reconcile a deficient response under current authority without rewriting that marker.
                $this->reconcileResponse($event, $root, $version, $floor, $current, $reads);
                $this->assertInspectionRoot($root, $current, $reads, $sites);
                return $this->confirmation($event, $version);
            }
            if ($previous->source_revision > $version->aggregate_revision) {
                throw new LogicException('The retained destination is ahead of this accepted source.');
            }
            $before = clone $event;
            $event->forceFill($desired);
            // Operational response may rise; independently recorded severity and investigation are retained.
            $this->raiseResponseFloor($event, $floor);
            $review = [$before, $previous->source_revision];
        }
        if (! $event->save()) { throw new LogicException('The required H&S destination save was vetoed.'); }
        $stored = $this->exactEventQuery((int) $event->id, $desired, $reads)->firstOrFail();
        if (ChemicalCommandValues::digest($this->eventProjection($stored)) !== ChemicalCommandValues::digest($desired)) {
            throw new LogicException('The exact H&S source projection was not retained.');
        }
        $this->assertSeverityFloor($stored, $floor);
        $this->assertInspectionRoot($root, $current, $reads, $sites);
        $marker = new ChemicalExposureHsProjection(['exposure_id' => (int) $root->id, 'hs_event_id' => (int) $stored->id,
            'source_version_id' => (int) $version->id, 'source_revision' => (int) $version->aggregate_revision,
            'source_content_digest' => $version->content_digest, 'projection' => $desired,
            'projection_digest' => ChemicalCommandValues::digest($desired), 'recorded_by' => (int) $current->id,
            'recorded_at' => now()->utc()]);
        $meta = $this->markerAuditMeta($marker, $version);
        $request = Request::create('/'); $request->setUserResolver(fn () => $current);
        AuditLogger::logOrFail('chemical.exposure.deliver_health_safety', $root, $meta, $request);
        $audits = $reads->query(AuditLog::query())->where('user_id', $current->id)
            ->where('action', 'chemical.exposure.deliver_health_safety')
            ->where('auditable_type', $root->getMorphClass())->where('auditable_id', $root->id)
            ->where('meta->source_version_id', $version->id)->lockForUpdate()->get();
        if ($audits->count() !== 1
            || ChemicalCommandValues::digest(array_intersect_key($audits[0]->meta ?? [], $meta)) !== ChemicalCommandValues::digest($meta)) {
            throw new LogicException('The exact exposure destination audit was not retained.');
        }
        $marker->audit_log_id = (int) $audits[0]->id;
        $this->source->save($marker);
        $this->assertMarker($marker, $version, $first, $reads);
        if (isset($review)) {
            app(HsEventReviewFlagService::class)->afterSourceChange($review[0], $root, (int) $version->aggregate_revision,
                $stored->severity, ChemicalExposureHsDestinationRules::correctedAfterDecision($review[0], $history, $review[1], $version));
        }
        return $this->confirmation($stored, $version);
    }

    /** Every accepted aggregate advance remains connected; a rehashed private version is not sufficient proof. */
    private function acceptedHistory(SubstanceExposureRecord $root, ChemicalExposureSourceVersion $latest,
        User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $identities = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
            ->where('aggregate_revision', '<=', $latest->aggregate_revision)->orderBy('aggregate_revision')
            ->get(['id', 'aggregate_revision']);
        $history = [];
        $before = null;
        foreach ($identities as $identity) {
            $admitted = $this->source->admitVersionMetadata((int) $identity->id, $current, $reads, $sites);
            $version = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
                ->where('site_id', $admitted['site_id'])->whereKey($identity->id)->firstOrFail();
            $this->source->assertAdmittedMetadata($version, $admitted);
            $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->where('exposure_id', $root->id)
                ->where('source_version_id', $version->id)->first();
            $intent = $receipt === null ? null : $reads->query(ChemicalCommandIntent::query())
                ->where('source_type', 'exposure')->whereKey($receipt->intent_id)->first();
            if ($receipt === null || $intent === null) { throw new LogicException('Exposure delivery history has no exact acceptance.'); }
            $this->ledger->assertAcceptance($receipt, $intent, $root->getMorphClass(), $reads, $current, $sites);
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
        if ($before === null || (int) $before->id !== (int) $latest->id) {
            throw new LogicException('Exposure delivery cannot confirm an omitted accepted revision.');
        }
        return $history;
    }

    private function projection(ChemicalExposureSourceVersion $version, ChemicalExposureSourceVersion $first): array
    {
        return ChemicalExposureHsDestinationRules::projection($version, $first);
    }

    private function eventProjection(HsEvent $event): array
    {
        return ChemicalExposureHsDestinationRules::eventProjection($event);
    }

    private function exactEventQuery(int $id, array $projection, CurrentAuthorizationReads $reads): Builder
    {
        $query = HsEvent::query()->whereKey($id);
        foreach ($projection as $field => $value) { $query->where($field, $value); }
        return $reads->query($query);
    }

    private function assertMarker(ChemicalExposureHsProjection $marker, ChemicalExposureSourceVersion $version,
        ChemicalExposureSourceVersion $first, CurrentAuthorizationReads $reads): void
    {
        $expected = $this->projection($version, $first);
        if ($marker->exposure_id !== $version->exposure_id || $marker->source_version_id !== (int) $version->id
            || $marker->source_revision !== $version->aggregate_revision || $marker->source_content_digest !== $version->content_digest
            || ChemicalCommandValues::digest($marker->projection) !== ChemicalCommandValues::digest($expected)
            || $marker->projection_digest !== ChemicalCommandValues::digest($expected)) {
            throw new LogicException('Exposure destination provenance is damaged or rebound.');
        }
        $meta = $this->markerAuditMeta($marker, $version);
        $audit = $reads->query(AuditLog::query())->whereKey($marker->audit_log_id)->first();
        if ($audit === null || (int) $audit->user_id !== $marker->recorded_by
            || ! in_array($audit->action, ChemicalExposureHsDestinationRules::MARKER_AUDIT_ACTIONS, true)
            || $audit->auditable_type !== (new SubstanceExposureRecord)->getMorphClass()
            || (int) $audit->auditable_id !== $marker->exposure_id
            || ChemicalCommandValues::digest(array_intersect_key($audit->meta ?? [], $meta)) !== ChemicalCommandValues::digest($meta)) {
            throw new LogicException('Exposure destination acceptance audit is missing or changed.');
        }
    }

    private function markerAuditMeta(ChemicalExposureHsProjection $marker, ChemicalExposureSourceVersion $version): array
    {
        return ChemicalExposureHsDestinationRules::markerAuditMeta($marker, $version);
    }

    private function versionFromHistory(array $history, int $id): ChemicalExposureSourceVersion
    {
        foreach ($history as $version) { if ((int) $version->id === $id) { return $version; } }
        throw new LogicException('Exposure destination has no retained original source acceptance.');
    }

    private function confirmation(HsEvent $event, ChemicalExposureSourceVersion $version): array
    {
        return ['hs_event_id' => (int) $event->id, 'source_revision' => (int) $version->aggregate_revision,
            'source_version_id' => (int) $version->id];
    }

    /** The proved helper owns the selected operational policy; raw retained categories are not current answers. */
    private function responseFloor(SubstanceExposureRecord $root, User $current, CurrentAuthorizationReads $reads, array $sites): string
    {
        $facts = $this->source->reportedFactsForSnapshot($root, $current, $reads, $sites);
        $response = $facts['response'] ?? null;
        $floor = is_array($response) ? ($response['response_floor'] ?? null) : null;
        if (($response['harm_assessment'] ?? null) !== 'unassessed'
            || ! in_array($floor, [HsEvent::SEVERITY_LOW, HsEvent::SEVERITY_MEDIUM, HsEvent::SEVERITY_HIGH, HsEvent::SEVERITY_CRITICAL], true)) {
            throw new LogicException('Exposure operational response lacks exact reported-fact proof.');
        }
        return $floor;
    }

    private function meetsSeverityFloor(HsEvent $event, string $floor): bool
    {
        return ChemicalExposureHsDestinationRules::meetsSeverityFloor($event, $floor);
    }

    private function assertSeverityFloor(HsEvent $event, string $floor): void
    {
        if (! $this->meetsSeverityFloor($event, $floor)) {
            throw new LogicException('The operational source response floor was not retained on its H&S destination.');
        }
    }

    private function raiseResponseFloor(HsEvent $event, string $floor): void
    {
        ChemicalExposureHsDestinationRules::raiseResponseFloor($event, $floor);
    }

    /** Same-version policy reconciliation is strictly audited; it creates no new source or projection acceptance. */
    private function reconcileResponse(HsEvent $event, SubstanceExposureRecord $root, ChemicalExposureSourceVersion $version,
        string $floor, User $current, CurrentAuthorizationReads $reads): void
    {
        if ($this->meetsSeverityFloor($event, $floor)) { return; }
        $beforeProjection = $this->eventProjection($event);
        $independent = array_diff_key($event->getAttributes(), array_flip(['severity', 'investigation_required', 'updated_at']));
        $this->raiseResponseFloor($event, $floor);
        if (! $event->save()) { throw new LogicException('The H&S operational response reconciliation was vetoed.'); }
        $stored = $this->exactEventQuery((int) $event->id, $beforeProjection, $reads)->firstOrFail();
        $this->assertSeverityFloor($stored, $floor);
        if (ChemicalCommandValues::digest($independent) !== ChemicalCommandValues::digest(array_diff_key($stored->getAttributes(),
            array_flip(['severity', 'investigation_required', 'updated_at'])))) {
            throw new LogicException('The response reconciliation changed independent H&S workflow.');
        }
        $meta = ['actor_id' => (int) $current->id, 'root_type' => 'exposure', 'root_id' => (int) $root->id,
            'source_type' => 'exposure', 'source_id' => (int) $root->id, 'expected_version' => $version->expected_version,
            'version' => (int) $version->aggregate_revision, 'source_version_id' => (int) $version->id,
            'idempotency_key' => $version->idempotency_key, 'changed' => true, 'source_content_digest' => $version->content_digest,
            'source_tuple_digest' => ChemicalCommandValues::digest(['hs_event_id' => (int) $stored->id,
                'projection' => $beforeProjection, 'response_floor' => $floor, 'severity' => $stored->severity,
                'investigation_required' => $stored->investigation_required, 'recorded_at' => now()->utc()->format('Y-m-d H:i:s.u')])];
        $request = Request::create('/'); $request->setUserResolver(fn () => $current);
        AuditLogger::logOrFail('chemical.exposure.reconcile_health_safety_response', $root, $meta, $request);
        $audits = $reads->query(AuditLog::query())->where('user_id', $current->id)
            ->where('action', 'chemical.exposure.reconcile_health_safety_response')
            ->where('auditable_type', $root->getMorphClass())->where('auditable_id', $root->id)
            ->where('meta->source_version_id', $version->id)->where('meta->source_tuple_digest', $meta['source_tuple_digest'])->get();
        if ($audits->count() !== 1
            || ChemicalCommandValues::digest(array_intersect_key($audits[0]->meta ?? [], $meta)) !== ChemicalCommandValues::digest($meta)) {
            throw new LogicException('The exact H&S operational response audit was not retained.');
        }
        // Hand the same verified response back to the consumer; its outbox write remains in this transaction.
        $event->setRawAttributes($stored->getAttributes(), true);
    }

    private function sourceTypes(): array
    {
        return array_values(array_unique([SubstanceExposureRecord::class, (new SubstanceExposureRecord)->getMorphClass()]));
    }
    /** Reuse the exact immutable snapshot seam; a separately refreshed proof cannot bless an older model. */
    private function assertInspectionRoot(SubstanceExposureRecord $root, User $current, CurrentAuthorizationReads $reads, array $sites): void
    {
        $fresh = $this->access->assertExposure((int) $root->id, $current, $reads, $sites);
        if (ChemicalCommandValues::digest($this->ledger->snapshot($fresh)) !== ChemicalCommandValues::digest($this->ledger->snapshot($root))) {
            throw new LogicException('Exposure changed while proving its destination.');
        }
        $this->source->currentVersion($fresh, $fresh, 'exposure', $reads, $current, $sites);
        $this->source->assertOriginalContext($fresh->original_context, $current, $reads, (int) $fresh->site_id);
        $exact = SubstanceExposureRecord::query()->whereKey($root->id);
        foreach (array_diff_key($root->getAttributes(), ['created_at' => true,'updated_at' => true]) as $column => $value) {
            if ($column === 'original_context' && $value !== null) { $exact->whereRaw('CAST(`original_context` AS CHAR) = ?', [$value]); }
            else { $exact->where($column, $value); }
        }
        if (! $reads->query($exact)->exists()) { throw new LogicException('Exposure changed after exact destination proof.'); }
    }

}
