<?php

namespace App\Domain\Monitoring\Protocols\RemoteInventory;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;

final class PhpseclibSshConnection implements SshConnection
{
    private SSH2 $session;

    public function __construct(string $address, int $port, int $connectTimeoutSeconds)
    {
        $this->session = new SSH2($address, $port, max(1, min(15, $connectTimeoutSeconds)));
        $this->session->disablePTY();
    }

    public function fingerprint(): string
    {
        $key = $this->session->getServerPublicHostKey();
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('SSH host key is unavailable.');
        }

        try {
            $fingerprint = PublicKeyLoader::load($key)->getFingerprint('sha256');
        } catch (Throwable) {
            throw new RuntimeException('SSH host key is invalid.');
        }
        if (! is_string($fingerprint) || $fingerprint === '') {
            throw new RuntimeException('SSH host key is invalid.');
        }

        return 'SHA256:'.$fingerprint;
    }

    public function authenticate(array $material): bool
    {
        $username = $material['username'] ?? null;
        if (! is_string($username)) {
            return false;
        }

        if (is_string($material['password'] ?? null)) {
            return $this->session->login($username, $material['password']);
        }

        $privateKey = $material['private_key'] ?? null;
        if (! is_string($privateKey)) {
            return false;
        }

        try {
            $key = PublicKeyLoader::loadPrivateKey(
                $privateKey,
                is_string($material['private_key_passphrase'] ?? null)
                    ? $material['private_key_passphrase']
                    : false,
            );
        } catch (Throwable) {
            return false;
        }

        return $this->session->login($username, $key);
    }

    public function execute(array $command, int $timeoutSeconds, int $maxOutputBytes): SshCommandResponse
    {
        $started = hrtime(true);
        $output = '';
        $truncated = false;
        $this->session->disablePTY();
        $this->session->setTimeout(max(1, min(15, $timeoutSeconds)));
        $compiled = implode(' ', array_map($this->quote(...), $command));

        try {
            $this->session->exec($compiled, function (string $chunk) use (&$output, &$truncated, $maxOutputBytes): void {
                $remaining = $maxOutputBytes + 1 - strlen($output);
                if ($remaining > 0) {
                    $output .= substr($chunk, 0, $remaining);
                }
                if (strlen($output) > $maxOutputBytes || strlen($chunk) > $remaining) {
                    $truncated = true;

                    throw new SshOutputLimitExceeded;
                }
            });
        } catch (SshOutputLimitExceeded) {
            $truncated = true;
        }

        $latency = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
        $exitStatus = $this->session->getExitStatus();

        return new SshCommandResponse(
            output: substr($output, 0, $maxOutputBytes + 1),
            exitStatus: is_int($exitStatus) ? $exitStatus : null,
            timedOut: $this->session->isTimeout(),
            truncated: $truncated,
            latencyMs: $latency,
        );
    }

    public function close(): void
    {
        $this->session->disconnect();
    }

    private function quote(string $part): string
    {
        if ($part === '' || strlen($part) > 128
            || preg_match('/[\\\\\x00-\x20\x7f;&|`$<>]/', $part) === 1) {
            throw new RuntimeException('SSH inventory operation is invalid.');
        }

        return "'".$part."'";
    }
}

final class SshOutputLimitExceeded extends RuntimeException {}
