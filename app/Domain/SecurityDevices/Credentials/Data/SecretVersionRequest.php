<?php

namespace App\Domain\SecurityDevices\Credentials\Data;

use InvalidArgumentException;
use RuntimeException;

final class SecretVersionRequest
{
    private string $opaqueReference;

    private int $version;

    public function __construct(
        #[\SensitiveParameter] string $opaqueReference,
        int $version,
    ) {
        $opaqueReference = trim($opaqueReference);
        if ($opaqueReference === '' || strlen($opaqueReference) > 512
            || preg_match('/[\x00-\x1f\x7f]/', $opaqueReference) === 1
            || $version < 1) {
            throw new InvalidArgumentException('Static secret version request is invalid.');
        }

        $this->opaqueReference = $opaqueReference;
        $this->version = $version;
    }

    public function opaqueReference(): string
    {
        return $this->opaqueReference;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['reference_configured' => true, 'version' => $this->version];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new RuntimeException('Static secret requests cannot be serialized.');
    }
}
