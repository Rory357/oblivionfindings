<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\HttpTransport;
use App\Domain\Monitoring\Models\MonitoringExternalHeartbeatState;
use Illuminate\Support\Carbon;
use Throwable;

final class ExternalMonitoringHeartbeatService
{
    private const REQUIRED_LISTENERS = ['snmp_traps', 'syslog', 'flow'];

    public function __construct(
        private readonly MonitoringRuntimeHealthService $runtime,
        private readonly ListenerHeartbeatReporter $listeners,
        private readonly EgressPolicy $egress,
        private readonly HttpTransport $transport,
    ) {}

    public function send(): MonitoringExternalHeartbeatState
    {
        $now = now()->utc()->startOfSecond();
        if (! (bool) config('monitoring.external_heartbeat.enabled', false)) {
            return $this->record([
                'state' => MonitoringExternalHeartbeatState::STATE_DISABLED,
                'reason_code' => 'disabled',
                'last_evaluated_at' => $now,
            ]);
        }

        try {
            $target = $this->egress->authoriseExternalHttps(
                (string) config('monitoring.external_heartbeat.url', ''),
                (array) config('monitoring.external_heartbeat.allowed_hosts', []),
            );
        } catch (Throwable) {
            return $this->suppressed($now, 'configuration_invalid');
        }

        $workers = collect($this->runtime->workerStates());
        if ($workers->count() !== 8
            || $workers->contains(fn (array $worker): bool => ($worker['state'] ?? null) !== 'available')) {
            return $this->suppressed($now, 'worker_unavailable', $target->url());
        }

        $listenerStaleSeconds = max(15, min(
            300,
            (int) config('monitoring.external_heartbeat.listener_stale_seconds', 30),
        ));
        foreach (self::REQUIRED_LISTENERS as $listener) {
            try {
                $snapshot = $this->listeners->snapshot($listener);
                $lastSeen = isset($snapshot['last_seen_at']) && is_string($snapshot['last_seen_at'])
                    ? Carbon::parse($snapshot['last_seen_at'])
                    : null;
            } catch (Throwable) {
                $lastSeen = null;
            }

            if ($lastSeen === null || $lastSeen->lt($now->copy()->subSeconds($listenerStaleSeconds))) {
                return $this->suppressed($now, 'listener_unavailable', $target->url());
            }
        }

        try {
            $response = $this->transport->request($target);
        } catch (Throwable) {
            return $this->failed($now, 'transport_failure', $target->url());
        }

        if ($response->truncated) {
            return $this->failed($now, 'response_too_large', $target->url(), $response->status);
        }
        if ($response->status < 200 || $response->status >= 300) {
            return $this->failed($now, 'non_success_response', $target->url(), $response->status);
        }

        return $this->record([
            'state' => MonitoringExternalHeartbeatState::STATE_SENT,
            'reason_code' => null,
            'endpoint_fingerprint' => hash('sha256', $target->url()),
            'response_status' => $response->status,
            'last_evaluated_at' => $now,
            'last_attempted_at' => $now,
            'last_sent_at' => $now,
        ]);
    }

    private function suppressed(Carbon $now, string $reason, ?string $url = null): MonitoringExternalHeartbeatState
    {
        return $this->record([
            'state' => MonitoringExternalHeartbeatState::STATE_SUPPRESSED,
            'reason_code' => $reason,
            'endpoint_fingerprint' => $url === null ? null : hash('sha256', $url),
            'response_status' => null,
            'last_evaluated_at' => $now,
            'last_suppressed_at' => $now,
        ]);
    }

    private function failed(
        Carbon $now,
        string $reason,
        string $url,
        ?int $status = null,
    ): MonitoringExternalHeartbeatState {
        return $this->record([
            'state' => MonitoringExternalHeartbeatState::STATE_FAILED,
            'reason_code' => $reason,
            'endpoint_fingerprint' => hash('sha256', $url),
            'response_status' => $status,
            'last_evaluated_at' => $now,
            'last_attempted_at' => $now,
            'last_failed_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function record(array $attributes): MonitoringExternalHeartbeatState
    {
        return MonitoringExternalHeartbeatState::query()->updateOrCreate(
            ['key' => MonitoringExternalHeartbeatState::KEY_CENTRAL_RUNTIME],
            $attributes,
        );
    }
}
