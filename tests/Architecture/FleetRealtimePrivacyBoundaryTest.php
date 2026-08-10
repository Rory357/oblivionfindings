<?php

it('keeps fleet realtime channels record private and raw signal payloads off the wire', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $signalEvent = file_get_contents($root.'/app/Events/FleetSignalEmitted.php');
    $positionEvent = file_get_contents($root.'/app/Events/FleetVehiclePositionUpdated.php');
    $alertEvent = file_get_contents($root.'/app/Events/FleetWanderingAlertTriggered.php');
    $channels = file_get_contents($root.'/routes/channels.php');
    $bootstrap = file_get_contents($root.'/bootstrap/app.php');
    $authorizer = file_get_contents($root.'/app/Services/Fleet/FleetRealtimeAuthorizationService.php');
    $provider = file_get_contents($root.'/app/Providers/AppServiceProvider.php');

    expect($signalEvent)
        ->not->toContain('ShouldBroadcast')
        ->not->toContain('broadcastOn')
        ->not->toContain('broadcastWith')
        ->not->toContain('$this->signal->payload')
        ->and($positionEvent)
        ->toContain('new PrivateChannel("fleet.assets.{$this->assetId}.positions")')
        ->toContain('public function broadcastWith(): array')
        ->not->toContain('new Channel(')
        ->and($alertEvent)
        ->toContain('new PrivateChannel("fleet.clients.{$this->clientId}.wandering-alerts")')
        ->toContain("return ['severity' => \$this->severity]")
        ->not->toContain('clientName')
        ->not->toContain('latitude')
        ->not->toContain('longitude')
        ->not->toContain('geofenceName')
        ->not->toContain('new Channel(')
        ->and($channels)
        ->toContain('FleetRealtimeAuthorizationService')
        ->toContain('fleet.assets.{assetId}.positions')
        ->toContain('fleet.clients.{clientId}.wandering-alerts')
        ->and($bootstrap)
        ->toContain('->withBroadcasting(')
        ->toContain("['middleware' => ['web', 'auth']]")
        ->and($authorizer)
        ->toContain('->forDevice($deviceId)')
        ->toContain('->forAsset($assetId)')
        ->toContain('->active()')
        ->toContain('authorisedClientAssignment($client)')
        ->toContain('resolveForContext($deviceId)')
        ->and($provider)
        ->toContain('->consentedClientForSignal($event->signal)');
});
