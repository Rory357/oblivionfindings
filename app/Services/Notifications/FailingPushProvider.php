<?php

namespace App\Services\Notifications;

final readonly class FailingPushProvider implements PushProvider
{
    public function __construct(private string $reason)
    {
    }

    public function send(array $tokens, string $message, ?string $title = null, array $data = []): PushSendResult
    {
        return PushSendResult::failed($this->reason);
    }
}
