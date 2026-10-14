<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Support\Monitoring\StrictJsonObjectDecoder;
use DateTimeImmutable;
use Throwable;

final class EndpointReleaseCatalogue
{
    public const DOMAIN = "OBLIVION-DOWNLOADS-V1\n";

    public const MAX_CATALOGUE = 65536;

    public const MAX_DOWNLOAD = 536870912;

    public function __construct(
        private readonly string $directory,
        private readonly string $publicKey,
        private readonly int $minimumRevision = 1,
    ) {}

    public static function configured(): self
    {
        return new self(
            (string) config('endpoint_management.release_directory'),
            (string) config('endpoint_management.release_public_key'),
            (int) config('endpoint_management.minimum_catalogue_revision', 1),
        );
    }

    /** Publisher metadata only: no profile, machine credential, API key or arbitrary URL. */
    public function catalogue(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable;
        $key = base64_decode($this->publicKey, true);
        $this->require(is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && base64_encode($key) === $this->publicKey);
        $bytes = $this->smallFile('downloads.json', self::MAX_CATALOGUE);
        $decoder = new StrictJsonObjectDecoder;
        try {
            $envelope = $decoder->decode($bytes, 12);
            $this->keys($envelope, ['payload', 'signature']);
            $payload = $this->base64($envelope['payload'] ?? null, self::MAX_CATALOGUE);
            $signature = $this->base64($envelope['signature'] ?? null, SODIUM_CRYPTO_SIGN_BYTES);
            $this->require(strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
                && sodium_crypto_sign_verify_detached($signature, self::DOMAIN.$payload, $key));
            $catalogue = $decoder->decode($payload, 12);
            $this->keys($catalogue, ['schema', 'product', 'revision', 'issued_at', 'expires_at', 'artifacts']);
            $this->require(($catalogue['schema'] ?? null) === 1 && ($catalogue['product'] ?? null) === 'oblivion-downloads'
                && is_int($catalogue['revision']) && $this->minimumRevision >= 1
                && $catalogue['revision'] >= $this->minimumRevision && $catalogue['revision'] <= 9007199254740991);
            $issued = $this->date($catalogue['issued_at']);
            $expires = $this->date($catalogue['expires_at']);
            $this->require($issued <= $now->modify('+5 minutes') && $expires > $now && $expires > $issued
                && $expires->getTimestamp() - $issued->getTimestamp() <= 90 * 86400);
            $this->require(is_array($catalogue['artifacts']) && array_is_list($catalogue['artifacts']) && count($catalogue['artifacts']) <= 16);
            $ids = [];
            $names = [];
            foreach ($catalogue['artifacts'] as $artifact) {
                $this->require(is_array($artifact));
                $this->keys($artifact, ['id', 'platform', 'architecture', 'roles', 'version', 'release_revision', 'filename', 'size', 'sha256']);
                $this->require(is_string($artifact['id']) && preg_match('/\A[a-z0-9][a-z0-9-]{1,63}\z/', $artifact['id']) === 1
                    && ! isset($ids[$artifact['id']]));
                $this->require(in_array($artifact['platform'], ['windows', 'linux'], true) && in_array($artifact['architecture'], ['amd64', 'arm64'], true));
                $this->require(is_array($artifact['roles']) && array_is_list($artifact['roles'])
                    && count($artifact['roles']) >= 1 && count($artifact['roles']) <= 2
                );
                foreach ($artifact['roles'] as $role) {
                    $this->require(in_array($role, ['computer', 'site'], true));
                }
                $this->require(count(array_unique($artifact['roles'])) === count($artifact['roles']));
                $this->require($artifact['platform'] !== 'linux' || $artifact['roles'] === ['site']);
                $this->require(is_string($artifact['version']) && strlen($artifact['version']) <= 32
                    && preg_match('/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/', $artifact['version']) === 1
                    && is_int($artifact['release_revision']) && $artifact['release_revision'] > 0 && $artifact['release_revision'] <= 9007199254740991);
                $this->require(is_string($artifact['filename']) && $this->safeFilename($artifact['filename'])
                    && ! isset($names[strtolower($artifact['filename'])])
                    && ($artifact['platform'] === 'windows'
                        ? str_ends_with($artifact['filename'], '.exe') || str_ends_with($artifact['filename'], '.zip')
                        : str_ends_with($artifact['filename'], '.tar.gz')));
                $this->require(is_int($artifact['size']) && $artifact['size'] > 0 && $artifact['size'] <= self::MAX_DOWNLOAD
                    && is_string($artifact['sha256']) && preg_match('/\A[a-f0-9]{64}\z/', $artifact['sha256']) === 1);
                $ids[$artifact['id']] = true;
                $names[strtolower($artifact['filename'])] = true;
            }

            return $catalogue;
        } catch (Throwable) {
            throw new EndpointReleaseUnavailable('Agent releases are unavailable.');
        }
    }

    /** Returns a verified immutable snapshot; never stream the mutable source after hashing. */
    public function download(string $id, string $expectedDigest): array
    {
        $catalogue = $this->catalogue();
        $artifact = null;
        foreach ($catalogue['artifacts'] as $candidate) {
            if ($candidate['id'] === $id && hash_equals($candidate['sha256'], $expectedDigest)) {
                $artifact = $candidate;
                break;
            }
        }
        $this->require($artifact !== null);
        $input = $this->openFile($artifact['filename'], $artifact['size']);
        $output = tmpfile();
        if ($output === false) {
            fclose($input);
            throw new EndpointReleaseUnavailable('Agent releases are unavailable.');
        }
        try {
            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                $this->require(is_string($chunk) && ($chunk !== '' || feof($input)));
                $size += strlen($chunk);
                $this->require($size <= $artifact['size']);
                hash_update($hash, $chunk);
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($output, substr($chunk, $offset));
                    $this->require(is_int($written) && $written > 0);
                    $offset += $written;
                }
            }
            $this->require($size === $artifact['size'] && hash_equals($artifact['sha256'], hash_final($hash)));
            $this->require(fflush($output) && rewind($output));
            // A withdrawn, expired or changed publication must not be served
            // merely because copying began while it was still available.
            $current = $this->catalogue();
            $stillPublished = false;
            foreach ($current['artifacts'] as $candidate) {
                if ($candidate === $artifact) {
                    $stillPublished = true;
                    break;
                }
            }
            $this->require($stillPublished);
            fclose($input);

            return ['artifact' => $artifact, 'stream' => $output, 'catalogue_revision' => $current['revision']];
        } catch (Throwable) {
            fclose($input);
            fclose($output);
            throw new EndpointReleaseUnavailable('Agent releases are unavailable.');
        }
    }

    private function smallFile(string $filename, int $limit): string
    {
        $input = $this->openFile($filename, null);
        try {
            $value = stream_get_contents($input, $limit + 1);
            $this->require(is_string($value) && strlen($value) <= $limit);

            return $value;
        } finally {
            fclose($input);
        }
    }

    private function openFile(string $filename, ?int $expectedSize)
    {
        $this->require($this->safeFilename($filename) && $this->directory !== '' && ! is_link($this->directory));
        $root = realpath($this->directory);
        $this->require(is_string($root) && is_dir($root));
        $path = $root.DIRECTORY_SEPARATOR.$filename;
        clearstatcache(true, $path);
        $resolved = realpath($path);
        $this->require(! is_link($path) && is_string($resolved) && dirname($resolved) === $root);
        $input = @fopen($path, 'rb');
        $this->require(is_resource($input));
        $stat = fstat($input);
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['nlink'] ?? 1) !== 1
            || $stat['size'] <= 0 || $stat['size'] > self::MAX_DOWNLOAD
            || ($expectedSize !== null && $stat['size'] !== $expectedSize)) {
            fclose($input);
            throw new EndpointReleaseUnavailable('Agent releases are unavailable.');
        }

        return $input;
    }

    private function safeFilename(string $filename): bool
    {
        return strlen($filename) <= 160 && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/', $filename) === 1
            && ! str_contains($filename, '..') && ! str_ends_with($filename, '.')
            && preg_match('/\A(?:CON|PRN|AUX|NUL|CLOCK\$|COM[0-9]|LPT[0-9])(?:\.|\z)/i', $filename) !== 1;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        $this->require(is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) === 1);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $this->require($date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value);

        return $date;
    }

    private function base64(mixed $value, int $limit): string
    {
        $this->require(is_string($value));
        $decoded = base64_decode($value, true);
        $this->require(is_string($decoded) && strlen($decoded) <= $limit && base64_encode($decoded) === $value);

        return $decoded;
    }

    private function keys(array $value, array $keys): void
    {
        $this->require(count($value) === count($keys) && array_diff(array_keys($value), $keys) === []);
    }

    private function require(bool $valid): void
    {
        if (! $valid) {
            throw new EndpointReleaseUnavailable('Agent releases are unavailable.');
        }
    }
}
