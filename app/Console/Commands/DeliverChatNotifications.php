<?php

namespace App\Console\Commands;

use App\Services\Chat\ChatBrowserPushDelivery;
use App\Services\Chat\ChatCallNoticeDelivery;
use App\Services\Chat\ChatFamilyWorkDelivery;
use App\Services\Chat\ChatMessageDelivery;
use Illuminate\Console\Command;

final class DeliverChatNotifications extends Command
{
    protected $signature = 'chat:deliver-notifications';

    protected $description = 'Recover durable Chat message, family assignment and missed-call notifications';

    public function handle(ChatMessageDelivery $delivery, ChatBrowserPushDelivery $push, ChatFamilyWorkDelivery $familyWork, ChatCallNoticeDelivery $calls): int
    {
        $this->info((string) ($delivery->recover() + $push->recover() + $familyWork->recover() + $calls->recover()));

        return self::SUCCESS;
    }
}
