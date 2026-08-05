<?php

namespace App\Domain\SecurityDevices\Credentials\Data;

use InvalidArgumentException;
use JsonSerializable;

final readonly class StoredSecretVersion implements JsonSerializable
{
    public function __construct(
        public string $opaqueReference,
        public int $version,
        public string $fingerprint,
    ) {
        if ($opaqueReference === '' || strlen($opaqueReference) > 512
            || $version < 1
            || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new InvalidArgumentException('Stored secret version metadata is invalid.');
        }
    }

    /** @return array{opaque_reference: string, version: int, fingerprint: string} */
    public function jsonSerialize(): array
    {
        return [
            'opaque_reference' => $this->opaqueReference,
            'version' => $this->version,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
