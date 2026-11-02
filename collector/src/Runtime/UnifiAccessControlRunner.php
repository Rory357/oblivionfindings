<?php

namespace Oblivion\Collector\Runtime;

use Closure;
use DateTimeImmutable;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\ScopeGuard;
use RuntimeException;
use Throwable;

/**
 * S&D Phase H: one governed door or Site change in UniFi Access, from the Site's hub, to the
 * console's trusted key only. Keep a door unlocked for the signed minutes (or lock it again), or
 * set the Site's emergency mode (lockdown, evacuation, or neither). The hub then reads UniFi
 * Access back, so Oblivion shows the console's own answer. A 5xx reply or no answer counts as
 * uncertain and is read back, never resent.
 */
final readonly class UnifiAccessControlRunner
{
    private Closure $delay;

    public function __construct(
        private ScopeGuard $scope,
        private CredentialLeaseDecryptor $credentials,
        private OnvifCameraNetwork $network = new NativeOnvifCameraNetwork,
        ?Closure $delay = null,
        private ?Closure $clock = null,
    ) {
        $this->delay = $delay ?? static fn (int $seconds): int => sleep($seconds);
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    public function run(array $command, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $this->scope->assertCommand($command, $at);
        $startedAt = new DateTimeImmutable('now');
        $material = [];
        $status = 'failed';
        $safeResult = [];
        $failure = null;
        $possibleSideEffect = false;
        $operation = (string) $command['parameters']['operation'];

        try {
            $material = $this->credentials->open($command, $at);
            $token = $this->token($material);
            $possibleSideEffect = true;
            [$path, $body] = $this->change($command, $operation);
            $response = $this->send($command, 'PUT', $path, $token, $body);
            $code = $this->code($response['body']);
            if ($response['status'] === 0 || $response['status'] >= 500) {
                // A server error may still have changed the doors: read back, never resend.
                $status = 'uncertain';
                $failure = 'The hub did not receive a confirmed answer from UniFi Access. Check the doors before trying again.';
            } elseif ($response['status'] !== 200 || $code !== 'SUCCESS') {
                $possibleSideEffect = false;
                $failure = match (true) {
                    in_array($response['status'], [401, 403], true) => 'UniFi Access rejected the saved login.',
                    $response['status'] === 404 => 'UniFi Access doesn’t know that door.',
                    in_array($response['status'], [400, 409, 422, 423], true) => 'UniFi Access refused the change in its current state.',
                    default => 'UniFi Access did not accept the change.',
                };
            } else {
                $status = 'succeeded';
                $safeResult = ['operation' => $operation, 'provider_state' => 'accepted'];
            }
        } catch (Throwable) {
            $failure ??= 'The hub could not complete the checks before sending the change.';
            if ($possibleSideEffect) {
                $status = 'uncertain';
                $failure = 'The hub did not receive a confirmed answer from UniFi Access. Check the doors before trying again.';
            }
        }

        $reconciliation = null;
        if ($possibleSideEffect) {
            ($this->delay)(2);
            $reconciliation = $this->reconcile($command, $material, $operation);
        }
        $completedAt = new DateTimeImmutable('now');
        $this->erase($material);

        return $this->result($command, $status, $safeResult, $failure, $at, $startedAt, $completedAt, $reconciliation);
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    public function interrupted(array $command, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $this->scope->assertCommand($command, $at);

        return $this->result($command, 'uncertain', [], 'The hub restarted after starting the change without a final answer. It was not sent again.', $at, $at, $at, [
            'outcome' => 'uncertain',
            'observed_state' => null,
            'observation_reference' => null,
            'safe_evidence_summary' => 'Fresh state is required before any new attempt.',
            'observed_at' => $at->format(DATE_ATOM),
        ]);
    }

    /** @param array<string, mixed> $command @return array{0: string, 1: array<string, mixed>} */
    private function change(array $command, string $operation): array
    {
        $door = $command['endpoint']['door_id'];

        return match ($operation) {
            'keep_unlocked' => ['/api/v1/developer/doors/'.rawurlencode((string) $door).'/lock_rule', ['type' => 'custom', 'interval' => (int) $command['parameters']['minutes']]],
            'keep_unlocked_end' => ['/api/v1/developer/doors/'.rawurlencode((string) $door).'/lock_rule', ['type' => 'reset']],
            'lockdown' => ['/api/v1/developer/doors/settings/emergency', ['lockdown' => true, 'evacuation' => false]],
            'evacuate' => ['/api/v1/developer/doors/settings/emergency', ['lockdown' => false, 'evacuation' => true]],
            'emergency_end' => ['/api/v1/developer/doors/settings/emergency', ['lockdown' => false, 'evacuation' => false]],
            default => throw new RuntimeException('Unknown UniFi Access change.'),
        };
    }

    /** @param array<string, mixed> $command @param array<string, scalar|null> $material @return array<string, mixed> */
    private function reconcile(array $command, array $material, string $operation): array
    {
        $observedAt = new DateTimeImmutable('now');
        $expected = $command['expected_state'];
        try {
            $door = $command['endpoint']['door_id'];
            $doorChange = in_array($operation, ['keep_unlocked', 'keep_unlocked_end'], true);
            $path = $doorChange ? '/api/v1/developer/doors/'.rawurlencode((string) $door).'/lock_rule' : '/api/v1/developer/doors/settings/emergency';
            $response = $this->send($command, 'GET', $path, $this->token($material), null);
            $payload = json_decode($response['body'], true, 16);
            $data = is_array($payload) && ($payload['code'] ?? null) === 'SUCCESS' && is_array($payload['data'] ?? null) ? $payload['data'] : null;
            if ($response['status'] !== 200 || $data === null) {
                throw new RuntimeException('UniFi Access state is unavailable.');
            }
            if ($doorChange) {
                $type = is_string($data['type'] ?? null) ? $data['type'] : '';
                $ends = is_int($data['ended_time'] ?? null) ? $data['ended_time'] : 0;
                $now = $this->clock !== null ? (int) ($this->clock)() : time();
                $observed = ['keep_unlocked' => $type === 'keep_unlock' || ($type === 'custom' && $ends > $now)];
            } else {
                $lockdown = $data['lockdown'] ?? null;
                $evacuation = $data['evacuation'] ?? null;
                if (! is_bool($lockdown) || ! is_bool($evacuation)) {
                    throw new RuntimeException('UniFi Access emergency state is unknown.');
                }
                $observed = ['emergency' => $evacuation ? 'evacuation' : ($lockdown ? 'lockdown' : 'normal')];
            }
            $matched = $observed === $expected;

            return [
                'outcome' => $matched ? 'matched' : 'mismatch',
                'observed_state' => $observed,
                'observation_reference' => 'collector-unifi-access:'.hash('sha256', $command['command_uuid'].'|'.$command['attempt_uuid']),
                'safe_evidence_summary' => $matched
                    ? 'The hub read UniFi Access straight after and it shows what was asked for.'
                    : 'The hub read UniFi Access straight after and it does not show what was asked for.',
                'observed_at' => $observedAt->format(DATE_ATOM),
            ];
        } catch (Throwable) {
            return [
                'outcome' => 'uncertain',
                'observed_state' => null,
                'observation_reference' => null,
                'safe_evidence_summary' => 'The hub could not read UniFi Access straight after. Check the doors before trying again.',
                'observed_at' => $observedAt->format(DATE_ATOM),
            ];
        }
    }

    /**
     * One request to the console at its signed address, to its trusted key only.
     *
     * @param  array<string, mixed>  $command
     * @param  array<string, mixed>|null  $json
     * @return array{status: int, body: string}
     */
    private function send(array $command, string $method, string $path, string $token, ?array $json): array
    {
        $endpoint = $command['endpoint'];
        $address = (string) $endpoint['address'];
        $response = $this->network->request(
            $method,
            'https://'.(str_contains($address, ':') ? '['.$address.']' : $address).':'.(int) $endpoint['port'].$path,
            ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'],
            $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            (string) $endpoint['trusted_pin'],
            (int) $endpoint['connect_timeout_seconds'],
            (int) $endpoint['response_timeout_seconds'],
            (int) $endpoint['max_response_bytes'],
        );

        return ['status' => $response['truncated'] ? 0 : (int) $response['status'], 'body' => (string) $response['body']];
    }

    private function code(string $body): ?string
    {
        $payload = json_decode($body, true, 16);

        return is_array($payload) && is_string($payload['code'] ?? null) ? $payload['code'] : null;
    }

    /** @param array<string, scalar|null> $material */
    private function token(array $material): string
    {
        $token = $material['api_token'] ?? $material['token'] ?? null;
        if (! is_string($token) || strlen($token) < 8 || strlen($token) > 4090 || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new RuntimeException('UniFi Access credential material is invalid.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, scalar|null>  $safeResult
     * @param  array<string, mixed>|null  $reconciliation
     * @return array<string, mixed>
     */
    private function result(array $command, string $status, array $safeResult, ?string $failure, DateTimeImmutable $acceptedAt, DateTimeImmutable $startedAt, DateTimeImmutable $completedAt, ?array $reconciliation): array
    {
        return [
            'item_type' => 'command_result',
            'command_uuid' => $command['command_uuid'],
            'attempt_uuid' => $command['attempt_uuid'],
            'attempt_number' => $command['attempt_number'],
            'site_id' => $command['site_id'],
            'device_id' => $command['device_id'],
            'capability' => $command['capability'],
            'contract_hash' => $command['contract_hash'],
            'execution_status' => $status,
            'safe_result' => $safeResult,
            'provider_request_reference' => $status === 'succeeded' ? 'unifi-access-control:'.$command['command_uuid'] : null,
            'safe_failure_reason' => $failure,
            'accepted_at' => $acceptedAt->format(DATE_ATOM),
            'started_at' => $startedAt->format(DATE_ATOM),
            'completed_at' => $completedAt->format(DATE_ATOM),
            'reconciliation' => $reconciliation,
        ];
    }

    /** @param array<string, scalar|null> $material */
    private function erase(array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '') {
                sodium_memzero($value);
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }
}
