<?php

namespace Oblivion\Collector\Spool;

use DateTimeImmutable;
use JsonException;
use Oblivion\Collector\Exceptions\SpoolFull;
use RuntimeException;

final class EncryptedSpool
{
    private const int MAX_FRAME_BYTES = 2_097_152;

    private string $spoolPath;

    private string $keyPath;

    private string $quarantineDirectory;

    private string $key;

    public function __construct(
        private readonly string $directory,
        private readonly CheckpointFile $checkpoint,
        private readonly int $maxBytes = 67_108_864,
        private readonly int $maxItems = 100_000,
        private readonly int $maxAgeSeconds = 604_800,
    ) {
        if ($maxBytes < 4096 || $maxItems < 1 || $maxAgeSeconds < 1) {
            throw new RuntimeException('Collector spool bounds are invalid.');
        }

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Collector spool directory is not writable.');
        }

        $this->spoolPath = $directory.DIRECTORY_SEPARATOR.'spool.bin';
        $this->keyPath = $directory.DIRECTORY_SEPARATOR.'spool.key';
        $this->quarantineDirectory = $directory.DIRECTORY_SEPARATOR.'quarantine';
        if (! is_dir($this->quarantineDirectory)
            && ! mkdir($this->quarantineDirectory, 0700, true)
            && ! is_dir($this->quarantineDirectory)
        ) {
            throw new RuntimeException('Collector quarantine directory is not writable.');
        }

        $this->key = $this->loadOrCreateKey();
        if (! is_file($this->spoolPath) && file_put_contents($this->spoolPath, '') === false) {
            throw new RuntimeException('Collector spool file is not writable.');
        }
        chmod($this->spoolPath, 0600);
    }

    /** @param array<string, mixed> $payload */
    public function append(
        string $id,
        int $sourceSequence,
        array $payload,
        ?DateTimeImmutable $at = null,
    ): bool {
        $at ??= new DateTimeImmutable('now');
        if ($id === '' || strlen($id) > 128 || $sourceSequence < 0) {
            throw new RuntimeException('Collector spool item identity is invalid.');
        }

        $frames = $this->readFrames();
        $checkpoint = $this->checkpoint->read();
        if (in_array($id, $checkpoint['acknowledged_ids'], true)
            || array_any($frames, fn (array $frame): bool => $frame['item']['id'] === $id)
        ) {
            return false;
        }

        if ($this->atCapacity($frames, $at)) {
            throw new SpoolFull('buffer_full: collector spool cap has been reached.');
        }

        $item = [
            'id' => $id,
            'source_sequence' => $sourceSequence,
            'created_at' => $at->format(DATE_ATOM),
            'payload' => $payload,
        ];
        $raw = $this->encryptFrame($item);
        $currentBytes = is_file($this->spoolPath) ? (int) filesize($this->spoolPath) : 0;
        if ($currentBytes + strlen($raw) > $this->maxBytes) {
            throw new SpoolFull('buffer_full: collector spool byte cap has been reached.');
        }

        $handle = fopen($this->spoolPath, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Collector spool is not writable.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Collector spool cannot be locked.');
            }
            if (fwrite($handle, $raw) !== strlen($raw) || ! fflush($handle)) {
                throw new RuntimeException('Collector spool receipt was not persisted.');
            }
            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException('Collector spool receipt was not synchronised.');
            }
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return true;
    }

    /** @return list<array{id: string, source_sequence: int, created_at: string, payload: array<string, mixed>}> */
    public function readBatch(int $limit, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $limit = max(1, min(1000, $limit));
        $acknowledged = $this->checkpoint->read()['acknowledged_ids'];

        return array_values(array_map(
            fn (array $frame): array => $frame['item'],
            array_slice(array_filter(
                $this->readFrames(),
                fn (array $frame): bool => ! in_array($frame['item']['id'], $acknowledged, true),
            ), 0, $limit),
        ));
    }

    /** @param list<string> $ids */
    public function acknowledge(array $ids, int $sourceSequence): void
    {
        $ids = array_values(array_unique(array_filter($ids, fn (mixed $id): bool => is_string($id) && $id !== '')));
        if ($ids === []) {
            return;
        }

        $frames = $this->readFrames();
        $matched = array_values(array_filter(
            $ids,
            fn (string $id): bool => array_any($frames, fn (array $frame): bool => $frame['item']['id'] === $id),
        ));
        if ($matched === []) {
            return;
        }

        $checkpoint = $this->checkpoint->read();
        $this->checkpoint->replace([
            ...$checkpoint,
            'acknowledged_source_sequence' => max($checkpoint['acknowledged_source_sequence'], $sourceSequence),
            'acknowledged_ids' => [...$checkpoint['acknowledged_ids'], ...$matched],
        ]);

        $remaining = array_values(array_filter(
            $frames,
            fn (array $frame): bool => ! in_array($frame['item']['id'], $matched, true),
        ));
        $this->rewrite($remaining);
    }

    public function count(?DateTimeImmutable $at = null): int
    {
        return count($this->readBatch($this->maxItems, $at));
    }

    public function nextSourceSequence(): int
    {
        $highest = $this->checkpoint->read()['acknowledged_source_sequence'];
        foreach ($this->readFrames() as $frame) {
            $highest = max($highest, $frame['item']['source_sequence']);
        }

        return $highest + 1;
    }

    /** @return array{state: string, items: int, bytes: int, oldest_at: ?string, corrupted_frames: int} */
    public function status(?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $frames = $this->readFrames();
        $oldest = $frames === [] ? null : $frames[0]['item']['created_at'];

        return [
            'state' => $this->atCapacity($frames, $at) ? 'buffer_full' : 'writable',
            'items' => count($frames),
            'bytes' => is_file($this->spoolPath) ? (int) filesize($this->spoolPath) : 0,
            'oldest_at' => $oldest,
            'corrupted_frames' => $this->checkpoint->read()['corrupted_frames'],
        ];
    }

    /** @param list<array{raw: string, item: array{id: string, source_sequence: int, created_at: string, payload: array<string, mixed>}}> $frames */
    private function atCapacity(array $frames, DateTimeImmutable $at): bool
    {
        if (count($frames) >= $this->maxItems || (is_file($this->spoolPath) && filesize($this->spoolPath) >= $this->maxBytes)) {
            return true;
        }
        if ($frames === []) {
            return false;
        }

        try {
            $oldest = new DateTimeImmutable($frames[0]['item']['created_at']);
        } catch (\Throwable) {
            return true;
        }

        return ($at->getTimestamp() - $oldest->getTimestamp()) >= $this->maxAgeSeconds;
    }

    /** @param array<string, mixed> $item */
    private function encryptFrame(array $item): string
    {
        $plaintext = json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($plaintext) > self::MAX_FRAME_BYTES - 1024) {
            throw new SpoolFull('buffer_full: collector spool item is too large.');
        }

        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
        $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
            $state,
            $plaintext,
            '',
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
        );
        $frame = $header.$ciphertext;

        return pack('N', strlen($frame)).$frame;
    }

    /** @return list<array{raw: string, item: array{id: string, source_sequence: int, created_at: string, payload: array<string, mixed>}}> */
    private function readFrames(): array
    {
        $contents = is_file($this->spoolPath) ? file_get_contents($this->spoolPath) : '';
        if (! is_string($contents)) {
            throw new RuntimeException('Collector spool is unreadable.');
        }

        $frames = [];
        $offset = 0;
        $corrupted = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $start = $offset;
            if ($length - $offset < 4) {
                $this->quarantine(substr($contents, $offset));
                $corrupted++;
                break;
            }

            $frameLength = unpack('Nlength', substr($contents, $offset, 4))['length'];
            $offset += 4;
            if ($frameLength < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES + 17
                || $frameLength > self::MAX_FRAME_BYTES
                || $offset + $frameLength > $length
            ) {
                $this->quarantine(substr($contents, $start));
                $corrupted++;
                break;
            }

            $encrypted = substr($contents, $offset, $frameLength);
            $offset += $frameLength;
            $raw = substr($contents, $start, 4 + $frameLength);
            $item = $this->decryptFrame($encrypted);
            if ($item === null) {
                $this->quarantine($raw);
                $corrupted++;

                continue;
            }

            $frames[] = ['raw' => $raw, 'item' => $item];
        }

        if ($corrupted > 0) {
            $checkpoint = $this->checkpoint->read();
            $this->checkpoint->merge(['corrupted_frames' => $checkpoint['corrupted_frames'] + $corrupted]);
            $this->rewrite($frames);
        }

        return $frames;
    }

    /** @return null|array{id: string, source_sequence: int, created_at: string, payload: array<string, mixed>} */
    private function decryptFrame(string $encrypted): ?array
    {
        try {
            $header = substr($encrypted, 0, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $ciphertext = substr($encrypted, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
            $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);
            if ($opened === false || $opened[1] !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                return null;
            }
            $item = json_decode($opened[0], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException|\SodiumException) {
            return null;
        }

        if (! is_array($item)
            || ! is_string($item['id'] ?? null)
            || ! is_int($item['source_sequence'] ?? null)
            || ! is_string($item['created_at'] ?? null)
            || ! is_array($item['payload'] ?? null)
        ) {
            return null;
        }

        return [
            'id' => $item['id'],
            'source_sequence' => $item['source_sequence'],
            'created_at' => $item['created_at'],
            'payload' => $item['payload'],
        ];
    }

    private function quarantine(string $raw): void
    {
        $path = $this->quarantineDirectory.DIRECTORY_SEPARATOR
            .gmdate('YmdHis').'-'.hash('sha256', $raw).'.frame';
        if (file_put_contents($path, $raw, LOCK_EX) === false) {
            throw new RuntimeException('Corrupted collector frame could not be quarantined.');
        }
        chmod($path, 0600);
    }

    /** @param list<array{raw: string, item: array<string, mixed>}> $frames */
    private function rewrite(array $frames): void
    {
        $temporary = $this->spoolPath.'.tmp.'.bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Collector spool cannot be compacted.');
        }

        try {
            foreach ($frames as $frame) {
                if (fwrite($handle, $frame['raw']) !== strlen($frame['raw'])) {
                    throw new RuntimeException('Collector spool cannot be compacted.');
                }
            }
            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('Collector spool cannot be synchronised.');
            }
        } finally {
            fclose($handle);
        }

        chmod($temporary, 0600);
        if (! rename($temporary, $this->spoolPath)) {
            @unlink($temporary);
            throw new RuntimeException('Collector spool cannot be replaced atomically.');
        }
    }

    private function loadOrCreateKey(): string
    {
        if (is_file($this->keyPath)) {
            $key = file_get_contents($this->keyPath);
            if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
                throw new RuntimeException('Collector spool key is invalid.');
            }

            return $key;
        }

        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        $handle = fopen($this->keyPath, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Collector spool key cannot be created.');
        }
        try {
            if (fwrite($handle, $key) !== strlen($key) || ! fflush($handle)) {
                throw new RuntimeException('Collector spool key cannot be persisted.');
            }
            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException('Collector spool key cannot be synchronised.');
            }
        } finally {
            fclose($handle);
        }
        chmod($this->keyPath, 0600);

        return $key;
    }
}
