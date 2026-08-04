<?php

namespace App\Domain\Monitoring\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

final readonly class CredentialLease implements JsonSerializable
{
    private CredentialLeaseMaterial $state;

    /**
     * The optional clock value is a deterministic test seam. Production callers
     * always evaluate expiry against the current UTC time at consumption.
     *
     * @param  array<string, scalar|null>  $material
     */
    public function __construct(
        public string $leaseId,
        public CarbonImmutable $expiresAt,
        #[\SensitiveParameter] array $material,
        private ?CarbonImmutable $clockNow = null,
    ) {
        if ($leaseId === '' || strlen($leaseId) > 190 || $material === []) {
            throw new InvalidArgumentException('Credential lease is invalid.');
        }

        foreach ($material as $key => $value) {
            if (! is_string($key) || $key === '' || strlen($key) > 64
                || (! is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('Credential lease material is invalid.');
            }
        }

        $this->state = new CredentialLeaseMaterial($material);
    }

    /** @return array<string, scalar|null> */
    public function material(): array
    {
        $now = $this->clockNow ?? CarbonImmutable::now('UTC');
        if ($this->expiresAt->lte($now)) {
            $this->state->destroy();

            throw new RuntimeException('Credential lease expired.');
        }

        return $this->state->consume();
    }

    /** @return array{lease_id: string, expires_at: string, consumed: bool} */
    public function jsonSerialize(): array
    {
        return [
            'lease_id' => $this->leaseId,
            'expires_at' => $this->expiresAt->utc()->toISOString(),
            'consumed' => $this->state->consumed(),
        ];
    }

    /** @return array{lease_id: string, expires_at: string, consumed: bool} */
    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    public function __unserialize(array $data): void
    {
        throw new RuntimeException('Credential leases cannot be restored from serialized data.');
    }
}

final class CredentialLeaseMaterial
{
    private bool $consumed = false;

    /** @param array<string, scalar|null> $material */
    public function __construct(#[\SensitiveParameter] private array $material) {}

    /** @return array<string, scalar|null> */
    public function consume(): array
    {
        if ($this->consumed) {
            throw new RuntimeException('Credential lease has already been consumed.');
        }

        $this->consumed = true;
        $material = $this->material;
        $this->destroy();

        return $material;
    }

    public function destroy(): void
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

    public function consumed(): bool
    {
        return $this->consumed;
    }
}
