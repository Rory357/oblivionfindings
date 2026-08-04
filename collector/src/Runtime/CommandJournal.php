<?php

namespace Oblivion\Collector\Runtime;

use JsonException;
use Oblivion\Collector\Exceptions\ScopeViolation;
use RuntimeException;

final class CommandJournal
{
    private string $directory;

    public function __construct(string $stateDirectory)
    {
        $this->directory = $stateDirectory.DIRECTORY_SEPARATOR.'command-journal';
        if (! is_dir($this->directory)
            && ! mkdir($this->directory, 0700, true)
            && ! is_dir($this->directory)) {
            throw new RuntimeException('Collector command journal directory is unavailable.');
        }
    }

    /** @param array<string, mixed> $command @return array{created: bool, state: string, result: ?array<string, mixed>} */
    public function begin(array $command): array
    {
        $path = $this->path((string) ($command['attempt_uuid'] ?? ''));
        $existing = $this->read($path);
        if ($existing !== null) {
            if (! hash_equals((string) $existing['contract_hash'], (string) ($command['contract_hash'] ?? ''))) {
                throw new ScopeViolation('Collector command attempt replay changed its signed contract.');
            }

            return [
                'created' => false,
                'state' => $existing['state'],
                'result' => is_array($existing['result'] ?? null) ? $existing['result'] : null,
            ];
        }
        $this->write($path, [
            'version' => 1,
            'attempt_uuid' => $command['attempt_uuid'],
            'contract_hash' => $command['contract_hash'],
            'state' => 'prepared',
            'result' => null,
        ]);

        return ['created' => true, 'state' => 'prepared', 'result' => null];
    }

    /** @param array<string, mixed> $command @param array<string, mixed> $result */
    public function resultReady(array $command, array $result): void
    {
        $path = $this->path((string) $command['attempt_uuid']);
        $entry = $this->required($path, $command);
        if ($entry['state'] === 'complete') {
            return;
        }
        $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 65_536) {
            throw new RuntimeException('Collector command result exceeds the journal bound.');
        }
        $entry['state'] = 'result_ready';
        $entry['result'] = $result;
        $this->write($path, $entry);
    }

    /** @param array<string, mixed> $command */
    public function complete(array $command): void
    {
        $path = $this->path((string) $command['attempt_uuid']);
        $entry = $this->required($path, $command);
        if ($entry['state'] !== 'result_ready' && $entry['state'] !== 'complete') {
            throw new RuntimeException('Collector command cannot complete before its result is durable.');
        }
        $entry['state'] = 'complete';
        $entry['result'] = null;
        $this->write($path, $entry);
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    private function required(string $path, array $command): array
    {
        $entry = $this->read($path);
        if ($entry === null
            || ! hash_equals((string) $entry['contract_hash'], (string) ($command['contract_hash'] ?? ''))) {
            throw new ScopeViolation('Collector command journal contract is unavailable or changed.');
        }

        return $entry;
    }

    private function path(string $attemptUuid): string
    {
        if (preg_match('/\A[0-9a-f-]{36}\z/i', $attemptUuid) !== 1) {
            throw new RuntimeException('Collector command attempt identity is invalid.');
        }

        return $this->directory.DIRECTORY_SEPARATOR.strtolower($attemptUuid).'.json';
    }

    /** @return array<string, mixed>|null */
    private function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (! is_string($raw) || strlen($raw) > 70_000) {
            throw new RuntimeException('Collector command journal is unreadable.');
        }
        try {
            $entry = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Collector command journal is corrupted.', previous: $exception);
        }
        if (! is_array($entry) || ($entry['version'] ?? null) !== 1
            || ! is_string($entry['attempt_uuid'] ?? null)
            || ! is_string($entry['contract_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $entry['contract_hash']) !== 1
            || ! in_array($entry['state'] ?? null, ['prepared', 'result_ready', 'complete'], true)) {
            throw new RuntimeException('Collector command journal is corrupted.');
        }

        return $entry;
    }

    /** @param array<string, mixed> $entry */
    private function write(string $path, array $entry): void
    {
        $raw = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Collector command journal cannot be staged.');
        }
        try {
            if (fwrite($handle, $raw) !== strlen($raw) || ! fflush($handle)
                || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('Collector command journal cannot be synchronised.');
            }
        } finally {
            fclose($handle);
        }
        chmod($temporary, 0600);
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Collector command journal cannot be replaced atomically.');
        }
    }
}
