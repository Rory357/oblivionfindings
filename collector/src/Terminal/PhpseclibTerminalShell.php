<?php

namespace Oblivion\Collector\Terminal;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;

/**
 * phpseclib 3 behind TerminalShell: an interactive shell with a terminal, read without blocking.
 */
final class PhpseclibTerminalShell implements TerminalShell
{
    private SSH2 $ssh;

    private bool $open = true;

    public function __construct(string $address, int $port, int $connectTimeoutSeconds = 5)
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            throw new RuntimeException('SSH target must be a pinned numeric address.');
        }
        $this->ssh = new SSH2($address, $port, max(2, min(10, $connectTimeoutSeconds)));
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
        try {
            if (is_string($privateKey) && trim($privateKey) !== '') {
                $passphrase = $material['private_key_passphrase'] ?? null;
                $ok = $this->ssh->login($username, PublicKeyLoader::loadPrivateKey($privateKey, is_string($passphrase) && $passphrase !== '' ? $passphrase : false));
            } else {
                $password = $material['password'] ?? null;
                $ok = is_string($password) && $password !== '' && $this->ssh->login($username, $password);
            }
        } catch (Throwable) {
            return false;
        }
        if ($ok) {
            $this->ssh->enablePTY();
            $this->ssh->setTimeout(0.05);
        }

        return $ok;
    }

    public function write(string $text): void
    {
        try {
            $this->ssh->write($text);
        } catch (Throwable) {
            $this->open = false;
        }
    }

    public function read(): string
    {
        try {
            $text = $this->ssh->read();
            if (! $this->ssh->isConnected()) {
                $this->open = false;
            }

            return is_string($text) ? $text : '';
        } catch (Throwable) {
            $this->open = false;

            return '';
        }
    }

    public function isOpen(): bool
    {
        return $this->open && $this->ssh->isConnected();
    }

    public function close(): void
    {
        $this->open = false;
        try {
            $this->ssh->disconnect();
        } catch (Throwable) {
            // Best effort; the socket goes with the process anyway.
        }
    }
}
