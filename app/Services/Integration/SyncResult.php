<?php

namespace App\Services\Integration;

class SyncResult
{
    public function __construct(
        public int $processed = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $errored = 0,
        public ?string $error = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->error === null && $this->errored === 0;
    }

    public function isPartial(): bool
    {
        return $this->error === null && $this->errored > 0;
    }
}
