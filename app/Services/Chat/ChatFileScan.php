<?php

namespace App\Services\Chat;

/** A verdict for exact bytes; never a promise about a later replacement file. */
final readonly class ChatFileScan
{
    public function __construct(
        public string $state,
        public ?string $sha256 = null,
        public ?string $scanner = null,
        public ?string $errorCode = null,
    ) {}

    public function verifies(string $serverPath): bool
    {
        if ($this->state !== 'clean' || ! $this->sha256 || ! is_file($serverPath) || ! is_readable($serverPath)) {
            return false;
        }
        $current = @hash_file('sha256', $serverPath);

        return is_string($current) && hash_equals($this->sha256, $current);
    }
}
