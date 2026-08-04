<?php

namespace Oblivion\Collector\Spool;

use JsonException;
use RuntimeException;

final readonly class CheckpointFile
{
    public function __construct(private string $path) {}

    /** @return array{config_sequence: int, config_hash: ?string, acknowledged_source_sequence: int, acknowledged_ids: list<string>, corrupted_frames: int} */
    public function read(): array
    {
        if (! is_file($this->path)) {
            return $this->defaults();
        }

        $contents = file_get_contents($this->path);
        if (! is_string($contents)) {
            throw new RuntimeException('Collector checkpoint is unreadable.');
        }

        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Collector checkpoint is corrupted.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Collector checkpoint is corrupted.');
        }

        return [
            'config_sequence' => max(0, (int) ($decoded['config_sequence'] ?? 0)),
            'config_hash' => is_string($decoded['config_hash'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/', $decoded['config_hash']) === 1
                    ? $decoded['config_hash']
                    : null,
            'acknowledged_source_sequence' => max(0, (int) ($decoded['acknowledged_source_sequence'] ?? 0)),
            'acknowledged_ids' => array_values(array_filter(
                is_array($decoded['acknowledged_ids'] ?? null) ? $decoded['acknowledged_ids'] : [],
                fn (mixed $id): bool => is_string($id) && $id !== '',
            )),
            'corrupted_frames' => max(0, (int) ($decoded['corrupted_frames'] ?? 0)),
        ];
    }

    /** @param array<string, mixed> $changes */
    public function merge(array $changes): void
    {
        $this->replace([...$this->read(), ...$changes]);
    }

    /** @param array<string, mixed> $checkpoint */
    public function replace(array $checkpoint): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Collector checkpoint directory is not writable.');
        }

        $data = [
            'config_sequence' => max(0, (int) ($checkpoint['config_sequence'] ?? 0)),
            'config_hash' => is_string($checkpoint['config_hash'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/', $checkpoint['config_hash']) === 1
                    ? $checkpoint['config_hash']
                    : null,
            'acknowledged_source_sequence' => max(0, (int) ($checkpoint['acknowledged_source_sequence'] ?? 0)),
            'acknowledged_ids' => array_slice(array_values(array_unique(array_filter(
                is_array($checkpoint['acknowledged_ids'] ?? null) ? $checkpoint['acknowledged_ids'] : [],
                fn (mixed $id): bool => is_string($id) && $id !== '',
            ))), -4096),
            'corrupted_frames' => max(0, (int) ($checkpoint['corrupted_frames'] ?? 0)),
        ];
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $temporary = $this->path.'.tmp.'.bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Collector checkpoint cannot be staged.');
        }

        try {
            if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                throw new RuntimeException('Collector checkpoint cannot be staged.');
            }
            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException('Collector checkpoint cannot be synchronised.');
            }
        } finally {
            fclose($handle);
        }

        chmod($temporary, 0600);
        if (! rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Collector checkpoint cannot be replaced atomically.');
        }
    }

    /** @return array{config_sequence: int, config_hash: ?string, acknowledged_source_sequence: int, acknowledged_ids: list<string>, corrupted_frames: int} */
    private function defaults(): array
    {
        return [
            'config_sequence' => 0,
            'config_hash' => null,
            'acknowledged_source_sequence' => 0,
            'acknowledged_ids' => [],
            'corrupted_frames' => 0,
        ];
    }
}
