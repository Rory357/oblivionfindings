<?php

namespace App\Events;

use App\Models\FleetSignal;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched whenever a fleet signal is emitted (geofence breach, SOS, tamper, etc.).
 *
 * NOTE: This event requires a broadcasting driver (Laravel Reverb or Pusher)
 * to be installed and configured. Until then, the event will be dispatched
 * locally but not broadcast to WebSocket clients.
 *
 * Setup: composer require laravel/reverb  (or pusher/pusher-php-server)
 *        php artisan install:broadcasting
 */
class FleetSignalEmitted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public FleetSignal $signal)
    {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('fleet.signals');
    }

    public function broadcastAs(): string
    {
        return 'signal.emitted';
    }

    /**
     * Data to broadcast. Avoids sending the full Eloquent model over the wire.
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->signal->id,
            'asset_id' => $this->signal->asset_id,
            'signal_type' => $this->signal->signal_type,
            'severity_hint' => $this->signal->severity_hint,
            'occurred_at' => optional($this->signal->occurred_at)->toISOString(),
            'payload' => $this->signal->payload,
        ];
    }
}
