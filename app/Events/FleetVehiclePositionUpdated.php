<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a fleet vehicle's GPS position changes.
 *
 * NOTE: This event requires a broadcasting driver (Laravel Reverb or Pusher)
 * to be installed and configured. Until then, the event will be dispatched
 * but not actually broadcast to WebSocket clients.
 *
 * Setup: composer require laravel/reverb  (or pusher/pusher-php-server)
 *        php artisan install:broadcasting
 */
class FleetVehiclePositionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $assetId,
        public ?float $latitude,
        public ?float $longitude,
        public ?float $speed_kph,
        public ?int $heading_deg,
        public string $status,
        public ?string $motion_status,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('fleet.vehicles');
    }

    public function broadcastAs(): string
    {
        return 'position.updated';
    }
}
