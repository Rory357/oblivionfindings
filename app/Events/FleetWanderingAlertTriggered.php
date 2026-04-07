<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a wandering/geofence breach alert is triggered for a resident-linked tracker.
 *
 * NOTE: This event requires a broadcasting driver (Laravel Reverb or Pusher)
 * to be installed and configured. Until then, the event will be dispatched
 * but not actually broadcast to WebSocket clients.
 *
 * Setup: composer require laravel/reverb  (or pusher/pusher-php-server)
 *        php artisan install:broadcasting
 */
class FleetWanderingAlertTriggered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $alertId,
        public string $alertType,
        public string $severity,
        public ?string $clientName,
        public ?int $clientId,
        public ?float $latitude,
        public ?float $longitude,
        public ?string $geofenceName,
        public string $triggeredAt,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('fleet.wandering-alerts');
    }

    public function broadcastAs(): string
    {
        return 'alert.triggered';
    }
}
