<?php

namespace App\Domain\Monitoring\Handlers;

use App\Domain\Monitoring\Contracts\RuntimeEnvelopeHandler;
use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Exceptions\RuntimeSiteScopeViolation;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;

final class TopologyProjectionEnvelopeHandler implements RuntimeEnvelopeHandler
{
    public function handle(RuntimeEnvelope $envelope, ?int $trustedSiteId = null): void
    {
        $payload = $envelope->payload;
        $siteId = $payload['site_id'] ?? null;
        $snapshotId = $payload['snapshot_id'] ?? null;
        if (($payload['projection_family'] ?? null) !== 'topology_snapshot'
            || ! is_int($siteId) || $siteId < 1
            || ! is_int($snapshotId) || $snapshotId < 1
            || ! is_string($payload['snapshot_uuid'] ?? null)
            || ! is_string($payload['source'] ?? null)
            || ! is_string($payload['checkpoint_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $payload['checkpoint_hash']) !== 1
            || ! is_int($payload['node_count'] ?? null)
            || ! is_int($payload['edge_count'] ?? null)
            || ! is_int($payload['change_count'] ?? null)) {
            throw new RuntimePayloadInvalid('Topology projection envelope payload is invalid.');
        }
        if ($trustedSiteId !== null && $trustedSiteId !== $siteId) {
            throw new RuntimeSiteScopeViolation('Topology projection Site does not match trusted routing context.');
        }

        $snapshot = TopologySnapshot::query()
            ->whereKey($snapshotId)
            ->where('site_id', $siteId)
            ->where('status', 'completed')
            ->first();
        if ($snapshot === null
            || $snapshot->snapshot_uuid !== $payload['snapshot_uuid']
            || $snapshot->source !== $payload['source']
            || ! hash_equals($snapshot->source_checkpoint_hash, $payload['checkpoint_hash'])
            || $snapshot->node_count !== $payload['node_count']
            || $snapshot->edge_count !== $payload['edge_count']
            || $snapshot->change_count !== $payload['change_count']) {
            throw new RuntimeSiteScopeViolation('Topology projection does not match its canonical snapshot.');
        }
    }
}
