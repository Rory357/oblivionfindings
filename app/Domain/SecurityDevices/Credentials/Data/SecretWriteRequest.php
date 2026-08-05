<?php

namespace App\Domain\SecurityDevices\Credentials\Data;

use InvalidArgumentException;
use RuntimeException;

final class SecretWriteRequest
{
    private const MAXIMUM_MATERIAL_BYTES = 1_048_576;

    private string $opaqueReference;

    /** @var array<string, scalar|null> */
    private array $material;

    private int $expectedVersion;

    private bool $consumed = false;

    /** @param array<string, scalar|null> $material */
    public function __construct(
        #[\SensitiveParameter] string $opaqueReference,
        #[\SensitiveParameter] array $material,
        int $expectedVersion,
    ) {
        $opaqueReference = trim($opaqueReference);
        if ($opaqueReference === '' || strlen($opaqueReference) > 512
            || preg_match('/[\x00-\x1f\x7f]/', $opaqueReference) === 1
            || $expectedVersion < 0
            || $material === []
            || count($material) > 64) {
            throw new InvalidArgumentException('Static secret write request is invalid.');
        }

        $bytes = 0;
        foreach ($material as $key => $value) {
            if (! is_string($key)
                || preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,63}$/', $key) !== 1
                || (! is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('Static secret material is invalid.');
            }
            $bytes += is_string($value) ? strlen($value) : 16;
            if ($bytes > self::MAXIMUM_MATERIAL_BYTES) {
                throw new InvalidArgumentException('Static secret material is too large.');
            }
        }

        $this->opaqueReference = $opaqueReference;
        $this->material = $material;
        $this->expectedVersion = $expectedVersion;
    }

    public function opaqueReference(): string
    {
        return $this->opaqueReference;
    }

    public function expectedVersion(): int
    {
        return $this->expectedVersion;
    }

    /** @return array<string, scalar|null> */
    public function consumeMaterial(): array
    {
        if ($this->consumed) {
            throw new RuntimeException('Static secret material has already been consumed.');
        }

        $this->consumed = true;
        $material = $this->material;
        $this->destroyMaterial();

        return $material;
    }

    public function __destruct()
    {
        $this->destroyMaterial();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'reference_configured' => true,
            'expected_version' => $this->expectedVersion,
            'material_field_count' => count($this->material),
            'consumed' => $this->consumed,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new RuntimeException('Static secret write requests cannot be serialized.');
    }

    private function destroyMaterial(): void
    {
        foreach ($this->material as &$value) {
            if (is_string($value) && $value !== '') {
                if (function_exists('sodium_memzero')) {
                    sodium_memzero($value);
                } else {
                    $value = str_repeat("\0", strlen($value));
                }
            }
            $value = null;
        }
        unset($value);
        $this->material = [];
    }
}
