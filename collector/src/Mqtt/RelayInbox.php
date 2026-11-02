<?php

namespace Oblivion\Collector\Mqtt;

use RuntimeException;

/**
 * S&D Phase B (D21): readings the sensor relay received, waiting for the hub's next cycle to move
 * them into its upload spool.
 *
 * One encrypted file per reading, written to a temporary name and renamed into place, so the relay
 * (which adds) and the cycle (which takes and deletes) never rewrite each other's data.
 */
final class RelayInbox
{
    private const string SUFFIX = '.rly';

    private ?int $knownCount = null;

    private int $putsSinceCount = 0;

    /** @param string $key 32 bytes, derived from the hub's own identity key */
    public function __construct(
        private readonly string $directory,
        #[\SensitiveParameter] private readonly string $key,
        private readonly int $maxFiles = 200_000,
    ) {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Relay inbox key is invalid.');
        }
    }

    /** The inbox key for a hub: derived from its request-signing key, so both of its processes agree. */
    public static function keyFor(#[\SensitiveParameter] string $requestSigningSecretKey): string
    {
        return sodium_crypto_generichash('oblivion-hub-relay-inbox-v1', $requestSigningSecretKey, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /** @param array<string, mixed> $item */
    public function put(array $item): bool
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Relay inbox is not writable.');
        }
        // Count the folder now and then, not on every reading.
        if ($this->knownCount === null || ++$this->putsSinceCount >= 500) {
            $this->knownCount = $this->count();
            $this->putsSinceCount = 0;
        }
        if ($this->knownCount >= $this->maxFiles) {
            return false; // Full: keep the oldest readings, refuse new ones until the link catches up.
        }
        $this->knownCount++;
        $name = sprintf('%015d-%s', (int) floor(microtime(true) * 1000), bin2hex(random_bytes(6)));
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $sealed = $nonce.sodium_crypto_secretbox($plain, $nonce, $this->key);
        sodium_memzero($plain);
        $temporary = $this->directory.DIRECTORY_SEPARATOR.$name.'.tmp';
        if (file_put_contents($temporary, $sealed) !== strlen($sealed)
            || ! rename($temporary, $this->directory.DIRECTORY_SEPARATOR.$name.self::SUFFIX)) {
            @unlink($temporary);
            throw new RuntimeException('Relay inbox receipt was not persisted.');
        }

        return true;
    }

    /** @return list<array{file: string, item: array<string, mixed>}> oldest first */
    public function take(int $limit): array
    {
        $taken = [];
        foreach ($this->files() as $file) {
            if (count($taken) >= $limit) {
                break;
            }
            $path = $this->directory.DIRECTORY_SEPARATOR.$file;
            $sealed = @file_get_contents($path);
            $plain = is_string($sealed) && strlen($sealed) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
                ? sodium_crypto_secretbox_open(
                    substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                    substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                    $this->key,
                ) : false;
            $item = is_string($plain) ? json_decode($plain, true) : null;
            if (! is_array($item)) {
                @unlink($path); // Damaged or not ours: never upload it.

                continue;
            }
            $taken[] = ['file' => $file, 'item' => $item];
        }

        return $taken;
    }

    public function remove(string $file): void
    {
        if (preg_match('/\A\d{15}-[0-9a-f]{12}\.rly\z/', $file) === 1) {
            @unlink($this->directory.DIRECTORY_SEPARATOR.$file);
        }
    }

    public function count(): int
    {
        return count($this->files());
    }

    /** @return list<string> */
    private function files(): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }
        $files = array_values(array_filter(
            scandir($this->directory) ?: [],
            static fn (string $name): bool => str_ends_with($name, self::SUFFIX),
        ));
        sort($files, SORT_STRING);

        return $files;
    }
}
