<?php

namespace Oblivion\Collector\Runtime;

use Closure;
use DateTimeImmutable;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\ScopeGuard;
use RuntimeException;
use Throwable;

final readonly class UnifiAccessCommandRunner
{
    private Closure $delay;

    public function __construct(
        private ScopeGuard $scope,
        private CredentialLeaseDecryptor $credentials,
        private CollectorCommandTransport $transport,
        ?Closure $delay = null,
    ) {
        $this->delay = $delay ?? static fn (int $seconds): int => sleep($seconds);
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    public function run(array $command, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $this->scope->assertCommand($command, $at);
        $acceptedAt = $at;
        $startedAt = new DateTimeImmutable('now');
        $material = [];
        $executionStatus = 'failed';
        $safeResult = [];
        $safeFailureReason = null;
        $possibleSideEffect = false;

        try {
            $material = $this->credentials->open($command, $at);
            $token = $this->token($material);
            $door = $this->door($command, $token);
            if (($door['is_bind_hub'] ?? false) !== true) {
                $safeFailureReason = 'UniFi Access reports that the mapped door is not bound to an access hub.';
            } elseif (($door['door_lock_relay_status'] ?? null) !== 'lock') {
                $safeFailureReason = 'The door was not in the approved locked state immediately before execution.';
            } else {
                $possibleSideEffect = true;
                try {
                    $response = $this->transport->request(
                        $command['endpoint'],
                        'PUT',
                        '/api/v1/developer/doors/'.$command['endpoint']['door_id'].'/unlock',
                        $this->headers($token),
                        [
                            'actor_id' => $command['command_uuid'],
                            'actor_name' => 'Oblivion Findings',
                            'extra' => [
                                'command_uuid' => $command['command_uuid'],
                                'attempt_uuid' => $command['attempt_uuid'],
                                'attempt_number' => $command['attempt_number'],
                                'duration_seconds' => $command['parameters']['duration_seconds'],
                            ],
                        ],
                    );
                    $payload = $this->json($response['body']);
                    if ($response['location'] !== null || $response['status'] >= 300
                        || ($payload['code'] ?? null) !== 'SUCCESS') {
                        $possibleSideEffect = false;
                        $safeFailureReason = $this->failureForStatus($response['status']);
                    } else {
                        $executionStatus = 'succeeded';
                        $safeResult = [
                            'provider_state' => 'accepted',
                            'previous_lock_state' => 'locked',
                            'unlock_duration_seconds' => $command['parameters']['duration_seconds'],
                        ];
                    }
                } catch (Throwable) {
                    $executionStatus = 'uncertain';
                    $safeFailureReason = 'The collector did not receive a confirmed final provider response. Actual state was checked before any retry.';
                }
            }
        } catch (Throwable) {
            $safeFailureReason = 'The collector could not complete the approved pre-execution state and credential checks.';
        }

        $reconciliation = null;
        if ($possibleSideEffect) {
            try {
                ($this->delay)(min(65, (int) $command['parameters']['duration_seconds'] + 2));
                $reconciliation = $this->reconcile($command, $material);
            } catch (Throwable) {
                $observedAt = new DateTimeImmutable('now');
                $reconciliation = [
                    'outcome' => 'uncertain',
                    'observed_state' => null,
                    'observation_reference' => null,
                    'safe_evidence_summary' => 'The remote Site collector could not freshly confirm the final door state. Do not retry until actual state is known.',
                    'observed_at' => $observedAt->format(DATE_ATOM),
                ];
            }
        }
        $completedAt = new DateTimeImmutable('now');

        $this->erase($material);

        return $this->result(
            $command,
            $executionStatus,
            $safeResult,
            $safeFailureReason,
            $acceptedAt,
            $startedAt,
            $completedAt,
            $reconciliation,
        );
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    public function interrupted(array $command, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $this->scope->assertCommand($command, $at);

        return $this->result(
            $command,
            'uncertain',
            [],
            'The collector restarted after persisting execution intent without a durable final result. The action was not repeated.',
            $at,
            $at,
            $at,
            [
                'outcome' => 'uncertain',
                'observed_state' => null,
                'observation_reference' => null,
                'safe_evidence_summary' => 'Fresh state is required before any new attempt.',
                'observed_at' => $at->format(DATE_ATOM),
            ],
        );
    }

    /** @param array<string, mixed> $command @param array<string, scalar|null> $material @return array<string, mixed> */
    private function reconcile(array $command, array $material): array
    {
        try {
            $door = $this->door($command, $this->token($material));
            $relay = $door['door_lock_relay_status'] ?? null;
            if (! in_array($relay, ['lock', 'unlock'], true)) {
                throw new RuntimeException('Door relay state is unavailable.');
            }
            $locked = $relay === 'lock';
            $observedAt = new DateTimeImmutable('now');

            return [
                'outcome' => $locked ? 'matched' : 'mismatch',
                'observed_state' => ['locked' => $locked],
                'observation_reference' => 'collector-unifi:door-state:'.hash(
                    'sha256',
                    $command['command_uuid'].'|'.$command['attempt_uuid'],
                ),
                'safe_evidence_summary' => $locked
                    ? 'The remote Site collector freshly confirmed that the door relay returned to locked.'
                    : 'The remote Site collector freshly reported that the door relay remains unlocked.',
                'observed_at' => $observedAt->format(DATE_ATOM),
            ];
        } catch (Throwable) {
            $observedAt = new DateTimeImmutable('now');

            return [
                'outcome' => 'uncertain',
                'observed_state' => null,
                'observation_reference' => null,
                'safe_evidence_summary' => 'The remote Site collector could not freshly confirm the final door state. Do not retry until actual state is known.',
                'observed_at' => $observedAt->format(DATE_ATOM),
            ];
        }
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    private function door(array $command, string $token): array
    {
        $response = $this->transport->request(
            $command['endpoint'],
            'GET',
            '/api/v1/developer/doors/'.$command['endpoint']['door_id'],
            $this->headers($token),
        );
        $payload = $this->json($response['body']);
        $door = $payload['data'] ?? null;
        if ($response['location'] !== null || $response['status'] !== 200
            || ($payload['code'] ?? null) !== 'SUCCESS' || ! is_array($door)
            || ! hash_equals($command['endpoint']['door_id'], strtolower((string) ($door['id'] ?? '')))) {
            throw new RuntimeException('UniFi Access door state could not be confirmed.');
        }

        return $door;
    }

    /** @return array<string, mixed> */
    private function json(string $body): array
    {
        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('UniFi Access returned an invalid response.', previous: $exception);
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('UniFi Access returned an invalid response.');
        }

        return $payload;
    }

    /** @param array<string, scalar|null> $material */
    private function token(array $material): string
    {
        $token = $material['api_token'] ?? $material['token'] ?? null;
        if (! is_string($token) || strlen($token) < 8 || strlen($token) > 4090
            || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new RuntimeException('UniFi Access credential material is invalid.');
        }

        return $token;
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ];
    }

    private function failureForStatus(int $status): string
    {
        return match ($status) {
            401, 403 => 'UniFi Access rejected the credential or required API permission.',
            404 => 'The mapped UniFi Access door is unavailable.',
            409, 422 => 'UniFi Access rejected the door action in its current state.',
            429 => 'UniFi Access rate-limited the door action.',
            default => 'UniFi Access did not accept the remote door action.',
        };
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, scalar|null>  $safeResult
     * @param  array<string, mixed>|null  $reconciliation
     * @return array<string, mixed>
     */
    private function result(
        array $command,
        string $status,
        array $safeResult,
        ?string $safeFailureReason,
        DateTimeImmutable $acceptedAt,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $completedAt,
        ?array $reconciliation,
    ): array {
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
            'provider_request_reference' => $status === 'succeeded'
                ? 'unifi-access:'.$command['command_uuid']
                : null,
            'safe_failure_reason' => $safeFailureReason,
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
