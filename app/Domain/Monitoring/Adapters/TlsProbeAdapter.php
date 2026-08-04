<?php

namespace App\Domain\Monitoring\Adapters;

use App\Domain\Monitoring\Contracts\ProbeAdapter;
use App\Domain\Monitoring\Contracts\TlsTransport;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\ProtocolObservation;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use Carbon\CarbonImmutable;
use LogicException;
use Throwable;

final class TlsProbeAdapter implements ProbeAdapter
{
    public function __construct(private readonly TlsTransport $transport) {}

    public function kind(): MonitorKind
    {
        return MonitorKind::Tls;
    }

    public function probe(AuthorisedProbeContext $context): ProtocolObservation
    {
        if ($context->kind !== $this->kind() || $context->target->scheme !== 'tls') {
            throw new LogicException('TLS probe context does not match its adapter.');
        }

        $observedAt = CarbonImmutable::now();
        $warnDays = $context->config['warn_days'] ?? 30;
        if (! is_int($warnDays) || $warnDays < 1 || $warnDays > 365) {
            return new ProtocolObservation(MonitorState::Unknown, $observedAt, null, 'days', null, 'invalid_configuration', []);
        }

        try {
            $result = $this->transport->probe($context->target);
        } catch (Throwable) {
            return new ProtocolObservation(MonitorState::Unknown, $observedAt, null, 'days', null, 'probe_error', []);
        }

        if (! $result->verified || ! $result->sanMatches) {
            $reason = in_array($result->reasonCode, ['hostname_mismatch', 'certificate_expired', 'tls_verification_failed', 'timeout', 'connection_failed'], true)
                ? $result->reasonCode
                : 'tls_verification_failed';

            return new ProtocolObservation(
                MonitorState::Failed,
                $observedAt,
                null,
                'days',
                $result->latencyMs,
                $reason,
                ['san_matched' => $result->sanMatches],
            );
        }
        if ($result->validTo === null) {
            return new ProtocolObservation(MonitorState::Unknown, $observedAt, null, 'days', $result->latencyMs, 'validity_unavailable', []);
        }

        $days = (int) floor($observedAt->diffInDays($result->validTo, false));
        $state = match (true) {
            $days < 0 => MonitorState::Failed,
            $days <= $warnDays => MonitorState::Degraded,
            default => MonitorState::Healthy,
        };
        $reason = match ($state) {
            MonitorState::Failed => 'certificate_expired',
            MonitorState::Degraded => 'certificate_expiring',
            default => 'certificate_valid',
        };

        return new ProtocolObservation(
            $state,
            $observedAt,
            $days,
            'days',
            $result->latencyMs,
            $reason,
            [
                'days_remaining' => $days,
                'issuer_hash' => $this->safeIssuerHash($result->issuerHash),
                'peer_fingerprint_sha256' => $this->safeFingerprint($result->peerFingerprintSha256),
                'san_matched' => true,
                'protocol' => $this->safeProtocol($result->protocol),
            ],
        );
    }

    private function safeIssuerHash(?string $hash): ?string
    {
        return is_string($hash) && preg_match('/^[a-f0-9-]{1,128}$/i', $hash) === 1 ? $hash : null;
    }

    private function safeProtocol(?string $protocol): ?string
    {
        return is_string($protocol) && preg_match('/^TLSv1\.[23]$/', $protocol) === 1 ? $protocol : null;
    }

    private function safeFingerprint(?string $fingerprint): ?string
    {
        return is_string($fingerprint) && preg_match('/^[a-f0-9]{64}$/i', $fingerprint) === 1
            ? strtolower($fingerprint)
            : null;
    }
}
