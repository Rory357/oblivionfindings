<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** Durable source acceptance is distinct from downstream delivery. The required consumer owns its canonical destination. */
final class ChemicalExposureDeliveryService
{
    public function __construct(private readonly ChemicalExposureCommandAccess $access,
        private readonly ChemicalExposureSourceService $source) {}

    public function deliverForViewer(User $viewer, int $id, callable $consumer, ?int $expectedExposureId = null): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($id, $consumer, $expectedExposureId) {
            $this->access->assertOperation($current, $reads, 'manage');
            // Read exact minimal identity before private body, then admit original source and obtain update lock.
            $identity = $reads->query(ChemicalExposureSourceDelivery::query())->select(['id', 'exposure_id'])->when($expectedExposureId !== null, fn ($q) => $q->where('exposure_id', $expectedExposureId))->whereKey($id)->firstOrFail();
            $root = $this->access->source((int) $identity->exposure_id, $current, $reads, $sites);
            $historicalProof = $this->source->sourceProofForAuditSubject(ChemicalExposureSourceDelivery::class,
                (int) $identity->id, $current, $reads, $sites);
            if (($historicalProof['attribution_state'] ?? null) !== 'recorded') {
                throw new LogicException('Exposure delivery original acceptance or membership is damaged.');
            }
            $query = $reads->query(ChemicalExposureSourceDelivery::query())->where('exposure_id', $root->id)->whereKey($id);
            $query->getQuery()->beforeQuery(fn ($compiled) => $compiled->lock('for update nowait'));
            $delivery = $query->firstOrFail();
            $proof = $this->source->sourceProof($root, $current, $reads, $sites);
            if ($proof['attribution_state'] !== 'recorded') { throw new LogicException('Exposure delivery needs exact governed source proof.'); }
            $membership = ['exposure_id' => (int) $root->id, 'substance_id' => (int) $root->hazardous_substance_id,
                'worker_id' => (int) $root->user_id, 'site_id' => (int) $root->site_id, 'source_revision' => (int) $root->source_revision,
                'source_version_id' => (int) $proof['source_version_id'], 'original_context' => $root->original_context];
            if (ChemicalCommandValues::digest($membership) !== ChemicalCommandValues::digest($delivery->membership)
                || $delivery->source_version_id !== (int) $proof['source_version_id']) {
                $delivery->state = 'superseded'; $this->source->save($delivery);
                return $this->dto($delivery);
            }
            if (! in_array($delivery->kind, ['health_safety', 'followup'], true)) { throw new LogicException('Unknown exposure delivery purpose.'); }
            if ($delivery->kind === 'followup') {
                $this->source->assertPrivateReceiver((int) $delivery->recipient_id, (int) $root->site_id, $reads, true);
                $followupIdentity = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)
                    ->where('source_revision', $delivery->membership['source_revision'])->where('assigned_to', $delivery->recipient_id)
                    ->firstOrFail(['id', 'exposure_id', 'source_schema_version', 'source_revision']);
                $this->source->assertSourceIdentityBeforeBody($root, $followupIdentity, 'followup', $reads, $current, $sites);
                $followup = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)
                    ->whereKey($followupIdentity->id)->where('assigned_to', $delivery->recipient_id)
                    ->where('source_schema_version', $followupIdentity->source_schema_version)
                    ->where('source_revision', $followupIdentity->source_revision)->firstOrFail();
                $this->source->currentVersion($root, $followup, 'followup', $reads, $current, $sites);
                if ($followup->completed_at !== null) {
                    $delivery->state = 'superseded'; $this->source->save($delivery); return $this->dto($delivery);
                }
            }
            // Replays verify the concrete destination again; the stored delivered flag is never sufficient.
            $result = $consumer($root, $proof, $current, $reads, $sites, $delivery->membership, $delivery->kind, $delivery->recipient_id);
            if (! is_array($result) || ($result['source_revision'] ?? null) !== (int) $root->source_revision
                || ($result['source_version_id'] ?? null) !== (int) $proof['source_version_id']
                || ($delivery->kind === 'health_safety' && (! is_int($result['hs_event_id'] ?? null) || $result['hs_event_id'] < 1))
                || ($delivery->kind === 'followup' && (($result['delivered'] ?? null) !== true
                    || ($result['recipient_id'] ?? null) !== $delivery->recipient_id))) {
                throw new LogicException('Exposure destination did not confirm this exact source revision and purpose.');
            }
            $delivery->forceFill(['state' => 'delivered', 'attempts' => $delivery->attempts + 1,
                'hs_event_id' => $delivery->kind === 'health_safety' ? $result['hs_event_id'] : null]);
            $this->source->save($delivery);
            return $this->dto($delivery);
        });
    }

    public function attemptsForViewer(User $viewer, int $exposureId): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($exposureId) {
            $root = $this->access->source($exposureId, $current, $reads, $sites, 'read');
            $proof = $this->source->sourceProof($root, $current, $reads, $sites);
            if ($proof['attribution_state'] === 'repair_required') { return ['state' => 'repair_required', 'attempts' => null]; }
            $identities = $reads->query(ChemicalExposureSourceDelivery::query())->where('exposure_id', $root->id)
                ->orderByDesc('id')->limit(25)->get(['id']);
            $items = [];
            foreach ($identities as $identity) {
                $subject = $this->source->sourceProofForAuditSubject(ChemicalExposureSourceDelivery::class,
                    (int) $identity->id, $current, $reads, $sites);
                if (($subject['attribution_state'] ?? null) !== 'recorded') { return ['state' => 'repair_required', 'attempts' => null]; }
                $row = $reads->query(ChemicalExposureSourceDelivery::query())->where('exposure_id', $root->id)->whereKey($identity->id)->firstOrFail();
                $items[] = $this->dto($row);
            }
            return ['state' => $proof['attribution_state'] === 'legacy_unknown' ? 'unconfirmed' : 'recorded', 'attempts' => $items];
        });
    }

    /** Called by an after-commit observer; it never guesses a callable destination or marks a source delivered. */
    public function sourceCommitted(SubstanceExposureRecord $source): void
    {
        if (DB::transactionLevel() !== 0) { throw new LogicException('Exposure commit observation requires the physical root commit.'); }
        // Prospective commands stage the outbox before commit. Genuine legacy writes have no verified version and are not auto-certified.
        // Destination execution is an explicit current-authorised consumer operation, mounted by the module integration owner.
    }

    private function dto(ChemicalExposureSourceDelivery $row): array
    {
        return ['id' => (int) $row->id, 'state' => $row->state, 'source_revision' => (int) ($row->membership['source_revision'] ?? 0),
            'kind' => $row->kind, 'attempts' => (int) $row->attempts, 'can_retry' => $row->state !== 'superseded'];
    }
}
