<?php

// Pure in-memory source audit: no Laravel bootstrap, database, network or device.
require getcwd().'/vendor/autoload.php';
$cases = ['hdop_only' => ['hdop' => 1.8], 'metres_and_hdop' => ['accuracy' => 7, 'hdop' => 1.8], 'missing' => []];
$result = [];
foreach (['queclink' => new App\Services\Fleet\Telemetry\QueclinkAdapter(), 'generic' => new App\Services\Fleet\Telemetry\GenericAdapter(new App\Services\TelemetryNormalizer(), 'synthetic-audit')] as $name => $adapter) {
    foreach ($cases as $case => $payload) $result[$name][$case] = $adapter->normalize($payload)['accuracy_m'];
}
$zone = new App\Services\Tracking\ClientZonePosition();
$shape = ['type' => 'circle', 'center' => ['lat' => 0.0, 'lng' => 0.0], 'radius_m' => 100.0];
$result['zone_with_missing_accuracy'] = ['centre' => $zone->classify($shape, 0.0, 0.0, null), 'outside' => $zone->classify($shape, 0.01, 0.0, null)];
echo json_encode($result, JSON_PRETTY_PRINT), PHP_EOL;
