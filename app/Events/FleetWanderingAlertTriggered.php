<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notify authorised viewers that resident tracking data should be refreshed.
 * Person, position, and signal details remain behind the normal record routes.
 */
class FleetWanderingAlertTriggered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        private readonly int $clientId,
        public readonly string $severity,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("fleet.clients.{$this->clientId}.wandering-alerts");
    }

    public function broadcastAs(): string
    {
        return 'alert.triggered';
    }

    /** @return array{severity: string} */
    public function broadcastWith(): array
    {
        return ['severity' => $this->severity];
    }
}
