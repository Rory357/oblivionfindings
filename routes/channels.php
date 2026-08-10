<?php

use App\Models\User;
use App\Services\Fleet\FleetRealtimeAuthorizationService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'fleet.assets.{assetId}.positions',
    fn (User $user, int $assetId): bool => app(FleetRealtimeAuthorizationService::class)
        ->canViewAssetPosition($user, $assetId),
);

Broadcast::channel(
    'fleet.clients.{clientId}.wandering-alerts',
    fn (User $user, int $clientId): bool => app(FleetRealtimeAuthorizationService::class)
        ->canViewClientAlert($user, $clientId),
);
