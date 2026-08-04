<?php

namespace App\Domain\Monitoring\Adapters;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Contracts\ProbeAdapter;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\ProtocolObservation;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Protocols\RemoteInventory\InventoryQuery;
use App\Domain\Monitoring\Protocols\RemoteInventory\InventoryResult;
use App\Domain\Monitoring\Protocols\RemoteInventory\WinRmInventoryTransport;
use Carbon\CarbonImmutable;
use Throwable;

final class WinRmInventoryProbeAdapter implements ProbeAdapter
{
    public function __construct(
        private readonly CredentialLeaseProvider $credentials,
        private readonly WinRmInventoryTransport $transport,
    ) {}

    public function kind(): MonitorKind
    {
        return MonitorKind::WinRmInventory;
    }

    public function probe(AuthorisedProbeContext $context): ProtocolObservation
    {
        if ($context->kind !== $this->kind() || $context->target->scheme !== 'winrm') {
            return $this->invalidConfiguration();
        }
        $profile = $context->config['profile'] ?? null;
        $reference = $context->config['credential_reference'] ?? null;
        if (! is_string($profile) || ! is_string($reference) || ! $this->reference($reference)) {
            return $this->invalidConfiguration();
        }

        try {
            $query = InventoryQuery::fromProfile($profile);
            if ($query->platform !== 'windows') {
                return $this->invalidConfiguration();
            }
            $lease = $this->credentials->acquire(
                $context->siteId,
                $reference,
                ['inventory:winrm:read_only'],
            );
            $result = $this->transport->collect($context->target, $lease, $query);
        } catch (Throwable) {
            return new ProtocolObservation(
                MonitorState::Unknown,
                CarbonImmutable::now('UTC'),
                null,
                'facts',
                null,
                'winrm_inventory_unavailable',
                ['inventory_profile' => $profile],
            );
        }

        return $this->observation($result, $profile);
    }

    private function invalidConfiguration(): ProtocolObservation
    {
        return new ProtocolObservation(
            MonitorState::Unknown,
            CarbonImmutable::now('UTC'),
            null,
            'facts',
            null,
            'invalid_configuration',
            [],
        );
    }

    private function reference(string $reference): bool
    {
        return preg_match('/^[a-z][a-z0-9._-]{1,31}:[A-Za-z0-9._\/:@-]{1,158}$/', $reference) === 1
            && ! str_contains($reference, '://');
    }

    private function observation(InventoryResult $result, string $profile): ProtocolObservation
    {
        $state = match ($result->status) {
            'ok' => MonitorState::Healthy,
            'partial' => MonitorState::Degraded,
            'transport_unavailable' => MonitorState::Unknown,
            default => MonitorState::Failed,
        };

        return new ProtocolObservation(
            state: $state,
            observedAt: CarbonImmutable::now('UTC'),
            value: count($result->facts),
            unit: 'facts',
            latencyMs: $result->latencyMs,
            reasonCode: "winrm_inventory_{$result->status}",
            evidence: [
                ...$result->facts,
                'inventory_profile' => $profile,
                'inventory_status' => $result->status,
                'completed_operations' => $result->completedOperations,
                'failed_operations' => $result->failedOperations,
            ],
        );
    }
}
