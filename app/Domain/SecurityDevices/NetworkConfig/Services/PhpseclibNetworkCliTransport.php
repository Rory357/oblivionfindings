<?php

namespace App\Domain\SecurityDevices\NetworkConfig\Services;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\SecurityDevices\NetworkConfig\Contracts\NetworkCliSshTransport;
use App\Domain\SecurityDevices\NetworkConfig\Data\NetworkCliCommandResult;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;

/**
 * Non-interactive `phpseclib3\Net\SSH2` transport strictly limited to read-only running-config export commands.
 */
final class PhpseclibNetworkCliTransport implements NetworkCliSshTransport
{
    public const int MAX_OUTPUT_BYTES = 2_097_152;

    /**
     * Hard allowlist of vendor read-only configuration export commands.
     * Arbitrary shell or mutating CLI commands are rejected before opening a socket.
     *
     * @var list<string>
     */
    public const array ALLOWED_COMMANDS = [
        '/export terse hide-sensitive',
        '/export terse',
        'show running-config',
        'show full-configuration',
        'show',
    ];

    public function runReadOnlyExport(
        AuthorizedProbeTarget $target,
        array $credentialMaterial,
        string $command,
        ?string $expectedHostKeyFingerprint = null,
        int $timeoutSeconds = 15,
    ): NetworkCliCommandResult {
        $cleanCommand = trim($command);
        if (! in_array($cleanCommand, self::ALLOWED_COMMANDS, true)) {
            throw new RuntimeException('Requested SSH CLI command is not in the read-only export allowlist.');
        }

        $username = $credentialMaterial['username'] ?? null;
        if (! is_string($username) || trim($username) === '' || strlen($username) > 128) {
            return new NetworkCliCommandResult(
                ok: false,
                reasonCode: 'invalid_credentials',
                output: '',
            );
        }

        $started = hrtime(true);
        $ssh = new SSH2($target->address, $target->port, max(2, min(20, $timeoutSeconds)));
        $ssh->disablePTY();

        try {
            $serverKey = $ssh->getServerPublicHostKey();
            if (! is_string($serverKey) || $serverKey === '') {
                return new NetworkCliCommandResult(
                    ok: false,
                    reasonCode: 'host_key_unavailable',
                    output: '',
                );
            }

            $fingerprint = 'SHA256:'.PublicKeyLoader::load($serverKey)->getFingerprint('sha256');
            $pinned = false;
            if (is_string($expectedHostKeyFingerprint) && trim($expectedHostKeyFingerprint) !== '') {
                $expected = trim($expectedHostKeyFingerprint);
                if (! hash_equals($expected, $fingerprint)) {
                    return new NetworkCliCommandResult(
                        ok: false,
                        reasonCode: 'host_key_mismatch',
                        output: '',
                        hostKeyFingerprint: $fingerprint,
                        hostKeyPinned: true,
                    );
                }
                $pinned = true;
            }

            if (! $this->authenticate($ssh, trim($username), $credentialMaterial)) {
                return new NetworkCliCommandResult(
                    ok: false,
                    reasonCode: 'auth_failed',
                    output: '',
                    hostKeyFingerprint: $fingerprint,
                    hostKeyPinned: $pinned,
                );
            }

            $ssh->disablePTY();
            $ssh->setTimeout(max(2, min(25, $timeoutSeconds)));

            $output = '';
            $truncated = false;
            try {
                $ssh->exec($cleanCommand, static function (string $chunk) use (&$output, &$truncated): void {
                    $remaining = self::MAX_OUTPUT_BYTES - strlen($output);
                    if ($remaining > 0) {
                        $output .= substr($chunk, 0, $remaining);
                    }
                    if (strlen($chunk) > $remaining) {
                        $truncated = true;
                        throw new RuntimeException('output_limit_exceeded');
                    }
                });
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'output_limit_exceeded') {
                    throw $exception;
                }
            }

            $latencyMs = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
            if ($truncated) {
                return new NetworkCliCommandResult(
                    ok: false,
                    reasonCode: 'output_exceeded_limit',
                    output: '',
                    hostKeyFingerprint: $fingerprint,
                    hostKeyPinned: $pinned,
                    latencyMs: $latencyMs,
                );
            }

            if ($ssh->isTimeout()) {
                return new NetworkCliCommandResult(
                    ok: false,
                    reasonCode: 'command_timed_out',
                    output: '',
                    hostKeyFingerprint: $fingerprint,
                    hostKeyPinned: $pinned,
                    latencyMs: $latencyMs,
                );
            }

            return new NetworkCliCommandResult(
                ok: trim($output) !== '',
                reasonCode: trim($output) !== '' ? 'ok' : 'empty_output',
                output: $output,
                hostKeyFingerprint: $fingerprint,
                hostKeyPinned: $pinned,
                latencyMs: $latencyMs,
            );
        } catch (Throwable) {
            return new NetworkCliCommandResult(
                ok: false,
                reasonCode: 'ssh_transport_error',
                output: '',
            );
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @param  array<string, scalar|null>  $material
     */
    private function authenticate(SSH2 $ssh, string $username, array $material): bool
    {
        $privateKey = $material['private_key'] ?? null;
        if (is_string($privateKey) && trim($privateKey) !== '') {
            try {
                $passphrase = $material['private_key_passphrase'] ?? null;
                $loadedKey = PublicKeyLoader::loadPrivateKey(
                    $privateKey,
                    is_string($passphrase) && $passphrase !== '' ? $passphrase : false,
                );

                return $ssh->login($username, $loadedKey);
            } catch (Throwable) {
                return false;
            }
        }

        $password = $material['password'] ?? null;
        if (is_string($password) && $password !== '') {
            return $ssh->login($username, $password);
        }

        return false;
    }
}
