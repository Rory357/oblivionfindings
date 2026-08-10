<?php

namespace App\Domain\Monitoring\Adapters;

use App\Domain\Monitoring\Contracts\ProbeAdapter;
use App\Domain\Monitoring\Contracts\TcpTransport;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\ProtocolObservation;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use Carbon\CarbonImmutable;
use LogicException;
use Throwable;

final class TcpProbeAdapter implements ProbeAdapter
{
    public function __construct(private readonly TcpTransport $transport) {}

    public function kind(): MonitorKind
    {
        return MonitorKind::Tcp;
    }

    public function probe(AuthorisedProbeContext $context): ProtocolObservation
    {
        if ($context->kind !== $this->kind() || $context->target->scheme !== 'tcp') {
            throw new LogicException('TCP probe context does not match its adapter.');
        }

        $observedAt = CarbonImmutable::now();
        try {
            $result = $this->transport->probe($context->target);
        } catch (Throwable) {
            return new ProtocolObservation(MonitorState::Unknown, $observedAt, null, 'ms', null, 'probe_error', []);
        }

        $connected = $result->connected && $result->latencyMs !== null && $result->latencyMs >= 0;
        $reason = $connected
            ? 'connected'
            : (in_array($result->reasonCode, ['connection_refused', 'timeout', 'unreachable'], true)
                ? $result->reasonCode
                : 'probe_failed');

        return new ProtocolObservation(
            $connected ? MonitorState::Healthy : MonitorState::Failed,
            $observedAt,
            $connected ? $result->latencyMs : null,
            'ms',
            $connected ? $result->latencyMs : null,
            $reason,
            ['port' => $context->target->port],
        );
    }
}
