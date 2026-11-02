<?php

namespace Oblivion\Collector\Runtime;

use Closure;
use DateTimeImmutable;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\ScopeGuard;
use RuntimeException;
use Throwable;

/**
 * S&D Phase E (R6): arm or disarm one area of an Inner Range Inception or Gallagher Command Centre
 * panel from the Site's hub, then read the area back so Oblivion shows the panel's own answer.
 * Only the area and operation the server signed; only the panel's control and status paths.
 */
final readonly class AlarmAreaCommandRunner
{
    private Closure $delay;

    /** @param  (Closure(array<string, mixed>, string, string, array<string, string>, ?array<string, mixed>): array{status: int, body: string, location: ?string})|null  $transport */
    public function __construct(
        private ScopeGuard $scope,
        private CredentialLeaseDecryptor $credentials,
        private ?Closure $transport = null,
        ?Closure $delay = null,
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
        $area = (string) $command['parameters']['area'];
        $operation = (string) $command['parameters']['operation'];

        try {
            $material = $this->credentials->open($command, $at);
            $token = $this->token($material);
            $possibleSideEffect = true;
            try {
                $response = $this->send($command, 'POST', $this->controlPath($command, $area, $operation), $token, $this->controlBody($command, $operation));
                if ($response['location'] === null && ($response['status'] >= 500 || $response['status'] < 100)) {
                    // A server error may still have changed the area: read it back, never retry.
                    $status = 'uncertain';
                    $failure = 'The hub did not receive a confirmed answer from the alarm panel. Check the area’s state before trying again.';
                } elseif ($response['location'] !== null || $response['status'] >= 300) {
                    $possibleSideEffect = false;
                    $failure = match (true) {
                        in_array($response['status'], [401, 403], true) => 'The alarm panel rejected the saved login.',
                        $response['status'] === 404 => 'The alarm panel doesn’t know that area.',
                        in_array($response['status'], [409, 422, 423], true) => 'The alarm panel refused the change in its current state.',
                        default => 'The alarm panel did not accept the change.',
                    };
                } else {
                    $status = 'succeeded';
                    $safeResult = ['area' => $area, 'provider_state' => 'accepted'];
                }
            } catch (Throwable) {
                $status = 'uncertain';
                $failure = 'The hub did not receive a confirmed answer from the alarm panel. Check the area’s state before trying again.';
            }
        } catch (Throwable) {
            $failure = 'The hub could not complete the checks before sending the change.';
        }

        $reconciliation = null;
        if ($possibleSideEffect) {
            ($this->delay)(3);
            $reconciliation = $this->reconcile($command, $material, $area, $operation);
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

    /** @param array<string, mixed> $command @param array<string, scalar|null> $material @return array<string, mixed> */
    private function reconcile(array $command, array $material, string $area, string $operation): array
    {
        $observedAt = new DateTimeImmutable('now');
        try {
            $response = $this->send($command, 'GET', $this->statusPath($command), $this->token($material), null);
            if ($response['location'] !== null || $response['status'] !== 200) {
                throw new RuntimeException('Area state is unavailable.');
            }
            $armed = $this->armedState($command, $response['body'], $area);
            if ($armed === null) {
                throw new RuntimeException('Area state is unknown.');
            }
            $wanted = $operation === 'arm';

            return [
                'outcome' => $armed === $wanted ? 'matched' : 'mismatch',
                'observed_state' => ['armed' => $armed],
                'observation_reference' => 'collector-alarm:area-state:'.hash('sha256', $command['command_uuid'].'|'.$command['attempt_uuid']),
                'safe_evidence_summary' => $armed === $wanted
                    ? 'The hub read the area straight after and it shows the state asked for.'
                    : 'The hub read the area straight after and it does not show the state asked for.',
                'observed_at' => $observedAt->format(DATE_ATOM),
            ];
        } catch (Throwable) {
            return [
                'outcome' => 'uncertain',
                'observed_state' => null,
                'observation_reference' => null,
                'safe_evidence_summary' => 'The hub could not read the area’s state straight after. Check it before trying again.',
                'observed_at' => $observedAt->format(DATE_ATOM),
            ];
        }
    }

    /** @param array<string, mixed> $command */
    private function controlPath(array $command, string $area, string $operation): string
    {
        return $command['endpoint']['driver'] === 'gallagher'
            ? '/api/alarm_zones/'.rawurlencode($area).'/'.$operation
            : '/api/v1/control/area/'.rawurlencode($area).'/activity';
    }

    /** @param array<string, mixed> $command @return array<string, string> */
    private function controlBody(array $command, string $operation): array
    {
        if ($command['endpoint']['driver'] === 'gallagher') {
            return $operation === 'arm' ? ['mode' => 'away'] : [];
        }

        return ['Type' => 'ControlArea', 'AreaControlType' => $operation === 'arm' ? 'Arm' : 'Disarm'];
    }

    /** @param array<string, mixed> $command */
    private function statusPath(array $command): string
    {
        return $command['endpoint']['driver'] === 'gallagher' ? '/api/alarm_zones' : '/api/v1/control/area';
    }

    /** The area's armed state from the panel's status list, or null when it can't be told. */
    private function armedState(array $command, string $body, string $area): ?bool
    {
        $payload = json_decode($body, true, 32);
        if (! is_array($payload)) {
            return null;
        }
        $rows = $command['endpoint']['driver'] === 'gallagher'
            ? ($payload['results'] ?? $payload['alarmZones'] ?? $payload)
            : ($payload['data'] ?? $payload);
        if (! is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (! is_array($row) || trim((string) ($row['ID'] ?? $row['id'] ?? '')) !== $area) {
                continue;
            }
            $state = strtolower(trim((string) ($row['PublicStateDescription'] ?? $row['State'] ?? $row['status'] ?? $row['state'] ?? '')));

            // Same reading as the server's drivers. "Alarm" contains "arm", so a sounding alarm is
            // checked first and counts as can't-tell rather than armed.
            return match (true) {
                str_contains($state, 'unarm') || str_contains($state, 'disarm') => false,
                str_contains($state, 'alarm') || str_contains($state, 'trigger') => null,
                str_contains($state, 'arm') => true,
                default => null,
            };
        }

        return null;
    }

    /**
     * One HTTPS request to the panel at its signed address only (resolved, verified, no redirects).
     *
     * @param  array<string, mixed>  $command
     * @param  array<string, mixed>|null  $json
     * @return array{status: int, body: string, location: ?string}
     */
    private function send(array $command, string $method, string $path, string $token, ?array $json): array
    {
        $endpoint = $command['endpoint'];
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => ($endpoint['driver'] === 'gallagher' ? 'GGL-API-KEY ' : 'APIToken ').$token,
            'Content-Type' => 'application/json',
        ];
        if ($this->transport !== null) {
            return ($this->transport)($endpoint, $method, $path, $headers, $json);
        }
        $host = (string) $endpoint['host'];
        $port = (int) $endpoint['port'];
        $url = 'https://'.(str_contains($host, ':') ? '['.$host.']' : $host).($port === 443 ? '' : ':'.$port).$path;
        $handle = curl_init($url);
        if ($handle === false || ! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('Pinned alarm transport is unavailable.');
        }
        $responseBody = '';
        $location = null;
        $maximum = (int) $endpoint['max_response_bytes'];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => (int) $endpoint['connect_timeout_seconds'],
            CURLOPT_TIMEOUT => (int) $endpoint['response_timeout_seconds'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $endpoint['address'])],
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$responseBody, $maximum): int {
                if (strlen($responseBody) + strlen($chunk) > $maximum) {
                    return 0;
                }
                $responseBody .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$location): int {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }

                return strlen($line);
            },
        ]);
        if ($json !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($json === [] ? new \stdClass : $json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($ok === false) {
            throw new RuntimeException('The alarm panel did not return a confirmed response.');
        }

        return ['status' => $status, 'body' => $responseBody, 'location' => $location];
    }

    /** @param array<string, scalar|null> $material */
    private function token(array $material): string
    {
        $token = $material['api_key'] ?? $material['api_token'] ?? $material['token'] ?? null;
        if (! is_string($token) || strlen($token) < 8 || strlen($token) > 4090 || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new RuntimeException('Alarm API credential material is invalid.');
        }

        return $token;
    }

    /** @param array<string, scalar|null> $material */
    private function erase(array &$material): void
    {
        foreach ($material as $key => $value) {
            if (is_string($value)) {
                sodium_memzero($value);
            }
            unset($material[$key]);
        }
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, mixed>  $safeResult
     * @param  array<string, mixed>|null  $reconciliation
     * @return array<string, mixed>
     */
    private function result(
        array $command,
        string $status,
        array $safeResult,
        ?string $failure,
        DateTimeImmutable $acceptedAt,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $completedAt,
        ?array $reconciliation,
    ): array {
        $completedAt = $completedAt < $startedAt ? $startedAt : $completedAt;

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
            'provider_request_reference' => $status === 'succeeded' ? 'alarm:'.$command['command_uuid'] : null,
            'safe_failure_reason' => $failure,
            'accepted_at' => $acceptedAt->format(DATE_ATOM),
            'started_at' => $startedAt->format(DATE_ATOM),
            'completed_at' => $completedAt->format(DATE_ATOM),
            'reconciliation' => $reconciliation,
        ];
    }
}
