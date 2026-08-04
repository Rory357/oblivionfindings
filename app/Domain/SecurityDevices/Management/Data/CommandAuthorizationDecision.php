<?php

namespace App\Domain\SecurityDevices\Management\Data;

final readonly class CommandAuthorizationDecision
{
    public function __construct(
        public bool $allowed,
        public string $code,
        public string $reason,
        public bool $concealed,
        public string $workspace,
        public string $sensitivity,
    ) {}
}
