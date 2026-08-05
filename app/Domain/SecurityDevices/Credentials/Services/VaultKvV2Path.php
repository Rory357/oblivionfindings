<?php

namespace App\Domain\SecurityDevices\Credentials\Services;

use RuntimeException;

final readonly class VaultKvV2Path
{
    private function __construct(
        private string $opaqueReference,
        private string $mount,
        private string $relativePath,
    ) {}

    public static function fromReference(
        #[\SensitiveParameter] string $opaqueReference,
        string $configuredMount,
        string $configuredPrefix,
    ): self {
        $mount = trim($configuredMount, '/');
        $prefix = trim($configuredPrefix, '/');
        $opaqueReference = trim($opaqueReference, '/');

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $mount) !== 1
            || ! self::validSegments($prefix)
            || ! self::validSegments($opaqueReference)
            || strlen($opaqueReference) > 512) {
            throw new RuntimeException('Vault KV v2 secret path is invalid.');
        }

        $requiredPrefix = $mount.'/data/'.$prefix.'/';
        if (! str_starts_with($opaqueReference, $requiredPrefix)) {
            throw new RuntimeException('Vault KV v2 secret path is outside the configured prefix.');
        }
        $relativePath = substr($opaqueReference, strlen($mount.'/data/'));
        if ($relativePath === $prefix || ! str_starts_with($relativePath, $prefix.'/')) {
            throw new RuntimeException('Vault KV v2 secret path is outside the configured prefix.');
        }

        return new self($opaqueReference, $mount, $relativePath);
    }

    public function opaqueReference(): string
    {
        return $this->opaqueReference;
    }

    public function apiPath(string $operation): string
    {
        if (! in_array($operation, ['data', 'metadata', 'delete', 'undelete', 'destroy'], true)) {
            throw new RuntimeException('Vault KV v2 operation is invalid.');
        }

        return '/v1/'.$this->mount.'/'.$operation.'/'.$this->relativePath;
    }

    private static function validSegments(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 512
            && ! str_contains($path, '//')
            && ! str_contains($path, '..')
            && preg_match('#^[A-Za-z0-9][A-Za-z0-9._@-]*(?:/[A-Za-z0-9][A-Za-z0-9._@-]*)*$#', $path) === 1;
    }
}
