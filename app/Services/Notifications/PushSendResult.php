<?php

namespace App\Services\Notifications;

final readonly class PushSendResult
{
    /**
     * @param  list<string>  $providerMessageIds
     */
    private function __construct(
        public bool $sent,
        public array $providerMessageIds = [],
        public ?string $error = null,
    ) {
    }

    /**
     * @param  list<string>  $providerMessageIds
     */
    public static function sent(array $providerMessageIds = []): self
    {
        return new self(true, $providerMessageIds);
    }

    public static function failed(string $error): self
    {
        return new self(false, [], $error);
    }
}
