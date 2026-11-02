<?php

namespace Oblivion\Collector\Runtime;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;

/**
 * phpseclib 3 behind the hub's SSH session interface (same calls the server's backup transport uses).
 */
final class PhpseclibSshSession implements SshSession
{
    private SSH2 $ssh;

    public function __construct(string $address, int $port, int $connectTimeoutSeconds)
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            throw new RuntimeException('SSH target must be a pinned numeric address.');
        }
        $this->ssh = new SSH2($address, $port, max(2, min(10, $connectTimeoutSeconds)));
        $this->ssh->disablePTY();
    }

    public function hostKeyFingerprint(): ?string
    {
        $key = $this->ssh->getServerPublicHostKey();
        if (! is_string($key) || $key === '') {
            return null;
        }

        return 'SHA256:'.PublicKeyLoader::load($key)->getFingerprint('sha256');
    }

    public function login(string $username, array $material): bool
    {
        $privateKey = $material['private_key'] ?? null;
        if (is_string($privateKey) && trim($privateKey) !== '') {
            try {
                $passphrase = $material['private_key_passphrase'] ?? null;

                return $this->ssh->login($username, PublicKeyLoader::loadPrivateKey(
                    $privateKey,
                    is_string($passphrase) && $passphrase !== '' ? $passphrase : false,
                ));
            } catch (Throwable) {
                return false;
            }
        }
        $password = $material['password'] ?? null;

        return is_string($password) && $password !== '' && $this->ssh->login($username, $password);
    }

    public function exec(string $command, int $maxBytes, int $timeoutSeconds): array
    {
        $this->ssh->disablePTY();
        $this->ssh->setTimeout(max(2, min(25, $timeoutSeconds)));
        $output = '';
        $truncated = false;
        try {
            $this->ssh->exec($command, static function (string $chunk) use (&$output, &$truncated, $maxBytes): void {
                $remaining = $maxBytes - strlen($output);
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

        return ['output' => $output, 'truncated' => $truncated, 'timed_out' => $this->ssh->isTimeout()];
    }

    public function close(): void
    {
        try {
            $this->ssh->disconnect();
        } catch (Throwable) {
            // Closing is best effort; the socket goes with the process anyway.
        }
    }
}
