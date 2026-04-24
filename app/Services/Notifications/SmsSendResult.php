<?php

namespace App\Services\Notifications;

final readonly class SmsSendResult
{
    private function __construct(
        public bool $sent,
        public ?string $providerMessageId = null,
        public ?string $error = null,
    ) {
    }

    public static function sent(?string $providerMessageId = null): self
    {
        return new self(true, $providerMessageId);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
