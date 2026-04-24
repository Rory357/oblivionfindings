<?php

namespace App\Services\Notifications;

final readonly class FailingSmsProvider implements SmsProvider
{
    public function __construct(private string $reason)
    {
    }

    public function send(string $to, string $message): SmsSendResult
    {
        return SmsSendResult::failed($this->reason);
    }
}
