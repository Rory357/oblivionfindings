<?php

namespace App\Domain\Monitoring\Adapters;

use App\Domain\Monitoring\Contracts\IcmpTransport;
use App\Domain\Monitoring\Contracts\ProbeAdapter;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\ProtocolObservation;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use Carbon\CarbonImmutable;
use LogicException;
use Throwable;

final class IcmpProbeAdapter implements ProbeAdapter
{
    public function __construct(private readonly IcmpTransport $transport) {}

    public function kind(): MonitorKind
    {
        return MonitorKind::Icmp;
    }

    public function probe(AuthorisedProbeContext $context): ProtocolObservation
    {
        $this->assertContext($context);
        $observedAt = CarbonImmutable::now();

        try {
            $result = $this->transport->probe($context->target);
        } catch (Throwable) {
            return new ProtocolObservation(
                MonitorState::Unknown,
                $observedAt,
                null,
                'ms',
                null,
                'probe_error',
                ['packet_loss_percent' => 100.0],
            );
        }

        $loss = max(0.0, min(100.0, $result->packetLossPercent));
        $reachable = $result->reachable && $result->latencyMs !== null && $result->latencyMs >= 0;

        return new ProtocolObservation(
            $reachable ? MonitorState::Healthy : MonitorState::Failed,
            $observedAt,
            $reachable ? $result->latencyMs : null,
            'ms',
            $reachable ? $result->latencyMs : null,
            $reachable ? 'reply' : $this->failureReason($result->reasonCode),
            ['packet_loss_percent' => $loss],
        );
    }

    private function assertContext(AuthorisedProbeContext $context): void
    {
        if ($context->kind !== $this->kind() || $context->target->scheme !== 'icmp') {
            throw new LogicException('ICMP probe context does not match its adapter.');
        }
    }

    private function failureReason(string $reason): string
    {
        return in_array($reason, ['packet_loss', 'timeout', 'unreachable', 'probe_unavailable'], true)
            ? $reason
            : 'probe_failed';
    }
}
