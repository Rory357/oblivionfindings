<?php

namespace App\Services\Notifications;

/**
 * What happened when a text was handed to the SMS provider.
 *
 *  - sent: the provider accepted it.
 *  - failed: the provider refused it for good (bad number, no credentials).
 *  - retryLater: the provider definitely did NOT take it, and trying again
 *    later may work (rate limited, briefly unavailable, never reached).
 *  - unconfirmed: we can't tell whether it went out (a timeout, a server
 *    error mid-request). Never retried, so a text is never sent twice.
 */
final readonly class SmsSendResult
{
    private function __construct(
        public bool $sent,
        public ?string $providerMessageId = null,
        public ?string $error = null,
        public bool $retryable = false,
        public bool $unconfirmed = false,
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

    /** Not taken by the provider; safe to try the same text again. */
    public static function retryLater(string $error): self
    {
        return new self(false, null, $error, retryable: true);
    }

    /** May have gone out; must not be sent again automatically. */
    public static function unconfirmed(string $error): self
    {
        return new self(false, null, $error, unconfirmed: true);
    }
}
