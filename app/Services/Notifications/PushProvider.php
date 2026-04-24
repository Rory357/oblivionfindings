<?php

namespace App\Services\Notifications;

interface PushProvider
{
    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $data
     */
    public function send(array $tokens, string $message, ?string $title = null, array $data = []): PushSendResult;
}
