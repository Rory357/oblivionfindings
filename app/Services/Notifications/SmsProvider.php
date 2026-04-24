<?php

namespace App\Services\Notifications;

interface SmsProvider
{
    public function send(string $to, string $message): SmsSendResult;
}
