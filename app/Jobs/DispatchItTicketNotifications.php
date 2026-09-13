<?php

namespace App\Jobs;

use App\Domain\It\Services\ItEmailDeliveryService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchItTicketNotifications
{
    use Dispatchable;

    public function __construct(public readonly ?int $ticketId, public readonly ?int $deliveryId = null) {}

    public function handle(ItEmailDeliveryService $deliveries): void
    {
        try {
            if ($this->ticketId === null && $this->deliveryId === null) {
                return;
            }
            $deliveries->dispatchPending(100, $this->ticketId, $this->deliveryId);
        } catch (Throwable) {
            // Durable pending rows remain discoverable by the scheduled drain.
            // Diagnostics must never invalidate the committed HTTP response.
            Log::warning('IT notification dispatch deferred to the recovery drain.');
        }
    }
}
