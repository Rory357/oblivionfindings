<?php

namespace App\Listeners\It;

use App\Domain\It\Services\ItEmailDeliveryService;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;

class RecordItEmailDelivery
{
    public function __construct(private readonly ItEmailDeliveryService $deliveries) {}

    public function handle(NotificationSending|NotificationSent|NotificationFailed $event): void
    {
        $this->deliveries->recordNotificationEvent($event);
    }
}
