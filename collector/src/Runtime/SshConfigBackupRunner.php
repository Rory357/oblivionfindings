<?php

namespace Oblivion\Collector\Runtime;

use Closure;
use DateTimeImmutable;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\ScopeGuard;
use Throwable;

/**
 * S&D Phase B: a router or switch settings backup, run on the hub that covers the Site.
 *
 * 1. Read the device's SSH host key. No login yet.
 * 2. No trusted key, or a different one: stop, send nothing, report the key that was offered.
 * 3. Log in with the short-lived saved login, run only the profile's fixed read-only export
 *    commands, and send the output back (gzip, base64, with its sha256) for the server to store.
 */
final readonly class SshConfigBackupRunner
{
    private const string FINGERPRINT = '/\ASHA256:[A-Za-z0-9+\/]{43}\z/';

    private const array FAILURE_TEXT = [
        'host_key_unavailable' => 'The device did not offer a usable SSH host key.',
        'host_key_unconfirmed' => 'The device’s SSH key isn’t trusted yet, so no login was sent.',
        'host_key_mismatch' => 'The device’s SSH key has changed since it was trusted, so no login was sent.',
        'invalid_credentials' => 'The saved login for this device is incomplete.',
        'auth_failed' => 'The device rejected the saved SSH login.',
        'cli_rejected_command' => 'The device rejected the read-only export command.',
        'empty_output' => 'The device returned no settings.',
        'hub_output_exceeded_limit' => 'The settings export was larger than the hub’s 1 MB limit.',
        'command_timed_out' => 'The settings export timed out.',
        'ssh_transport_error' => 'The hub could not open an SSH connection to the device.',
        'precheck_failed' => 'The hub could not complete its safety and login checks.',
        'interrupted' => 'The hub restarted during the backup; nothing was changed on the device.',
    ];

    private Closure $connect;

    /** @param (Closure(string, int, int): SshSession)|null $connect */
    public function __construct(
        private ScopeGuard $scope,
        private CredentialLeaseDecryptor $credentials,
        ?Closure $connect = null,
    ) {
        $this->connect = $connect ?? static fn (string $address, int $port, int $timeout): SshSession => new PhpseclibSshSession($address, $port, $timeout);
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    public function run(array $command, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $this->scope->assertCommand($command, $at);
        $startedAt = new DateTimeImmutable('now');
        $endpoint = $command['endpoint'];
        $profileName = (string) $command['parameters']['export_profile'];
        $profile = SshExportProfiles::for($profileName);
        $material = [];
        $session = null;

        try {
            try {
                $material = $this->credentials->open($command, $at);
            } catch (Throwable) {
                return $this->failed($command, 'precheck_failed', $at, $startedAt);
            }
            $username = $material['username'] ?? null;
            if ($profile === null || ! is_string($username) || trim($username) === '' || strlen($username) > 128) {
                return $this->failed($command, 'invalid_credentials', $at, $startedAt);
            }

            $session = ($this->connect)($endpoint['address'], $endpoint['port'], $endpoint['connect_timeout_seconds']);
            $offered = $session->hostKeyFingerprint();
            if (! is_string($offered) || preg_match(self::FINGERPRINT, $offered) !== 1) {
                return $this->failed($command, 'host_key_unavailable', $at, $startedAt);
            }
            $trusted = $endpoint['expected_host_key'];
            if (! is_string($trusted)) {
                return $this->failed($command, 'host_key_unconfirmed', $at, $startedAt, $offered);
            }
            if (! hash_equals($trusted, $offered)) {
                return $this->failed($command, 'host_key_mismatch', $at, $startedAt, $offered);
            }
            if (! $session->login(trim($username), $material)) {
                return $this->failed($command, 'auth_failed', $at, $startedAt);
            }

            $reason = 'empty_output';
            foreach ($profile['commands'] as $cli) {
                $result = $session->exec($cli, (int) $endpoint['max_output_bytes'], (int) $endpoint['command_timeout_seconds']);
                if ($result['truncated']) {
                    return $this->failed($command, 'hub_output_exceeded_limit', $at, $startedAt);
                }
                if ($result['timed_out']) {
                    return $this->failed($command, 'command_timed_out', $at, $startedAt);
                }
                if (trim($result['output']) === '') {
                    $reason = 'empty_output';

                    continue;
                }
                if (SshExportProfiles::looksLikeCliError($result['output'], $profileName)) {
                    // RouterOS 7 dropped `hide-sensitive`; its export already hides secrets, so try the next one.
                    $reason = 'cli_rejected_command';

                    continue;
                }

                return $this->succeeded($command, $profileName, $offered, $result['output'], $at, $startedAt);
            }

            return $this->failed($command, $reason, $at, $startedAt);
        } catch (Throwable) {
            return $this->failed($command, 'ssh_transport_error', $at, $startedAt);
        } finally {
            $session?->close();
            foreach ($material as &$value) {
                if (is_string($value) && $value !== '') {
                    sodium_memzero($value);
                }
                $value = null;
            }
            unset($value);
        }
    }

    /**
     * The hub restarted after recording that it had started. A read-only export can simply be
     * reported as not done; nothing on the device changed.
     *
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    public function interrupted(array $command, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $this->scope->assertCommand($command, $at);

        return $this->failed($command, 'interrupted', $at, $at);
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    private function succeeded(array $command, string $profile, string $fingerprint, string $output, DateTimeImmutable $acceptedAt, DateTimeImmutable $startedAt): array
    {
        $sha = hash('sha256', $output);
        $result = $this->result($command, 'succeeded', [
            'provider_state' => 'captured',
            'export_profile' => $profile,
            'output_bytes' => strlen($output),
            'output_sha256' => $sha,
            'host_key_fingerprint' => $fingerprint,
        ], 'ssh-backup:'.$command['command_uuid'], null, $acceptedAt, $startedAt);
        $result['attachment'] = [
            'kind' => 'cli_output',
            'encoding' => 'gzip+base64',
            'bytes' => strlen($output),
            'sha256' => $sha,
            'data' => base64_encode((string) gzencode($output, 6)),
        ];

        return $result;
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    private function failed(array $command, string $code, DateTimeImmutable $acceptedAt, DateTimeImmutable $startedAt, ?string $offeredHostKey = null): array
    {
        $safe = ['reason_code' => $code];
        if ($offeredHostKey !== null) {
            $safe['offered_host_key'] = $offeredHostKey;
        }

        return $this->result($command, 'failed', $safe, null, self::FAILURE_TEXT[$code], $acceptedAt, $startedAt);
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, scalar|null>  $safeResult
     * @return array<string, mixed>
     */
    private function result(array $command, string $status, array $safeResult, ?string $reference, ?string $failure, DateTimeImmutable $acceptedAt, DateTimeImmutable $startedAt): array
    {
        $completedAt = new DateTimeImmutable('now');
        if ($startedAt < $acceptedAt) {
            $startedAt = $acceptedAt;
        }
        if ($completedAt < $startedAt) {
            $completedAt = $startedAt;
        }

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
            'provider_request_reference' => $reference,
            'safe_failure_reason' => $failure,
            'accepted_at' => $acceptedAt->format(DATE_ATOM),
            'started_at' => $startedAt->format(DATE_ATOM),
            'completed_at' => $completedAt->format(DATE_ATOM),
            'reconciliation' => null,
        ];
    }
}
