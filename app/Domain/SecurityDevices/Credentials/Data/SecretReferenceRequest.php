<?php

namespace App\Domain\SecurityDevices\Credentials\Data;

use InvalidArgumentException;
use RuntimeException;

final class SecretReferenceRequest
{
    private string $opaqueReference;

    public function __construct(#[\SensitiveParameter] string $opaqueReference)
    {
        $opaqueReference = trim($opaqueReference);
        if ($opaqueReference === '' || strlen($opaqueReference) > 512
            || preg_match('/[\x00-\x1f\x7f]/', $opaqueReference) === 1) {
            throw new InvalidArgumentException('Static secret reference is invalid.');
        }

        $this->opaqueReference = $opaqueReference;
    }

    public function opaqueReference(): string
    {
        return $this->opaqueReference;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['reference_configured' => true];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new RuntimeException('Static secret requests cannot be serialized.');
    }
}
