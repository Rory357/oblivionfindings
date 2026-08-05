<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast a minimal fleet vehicle position to authorised record viewers.
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

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("fleet.assets.{$this->assetId}.positions");
    }

    public function broadcastAs(): string
    {
        return 'position.updated';
    }

    /** @return array<string, int|float|string|null> */
    public function broadcastWith(): array
    {
        return [
            'asset_id' => $this->assetId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'speed_kph' => $this->speed_kph,
            'heading_deg' => $this->heading_deg,
            'status' => $this->status,
        ];
    }
}
