<?php

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\SecurityDevices\NetworkConfig\Services\PhpseclibNetworkCliTransport;

// Found on the Phase A walk (1 Nov 2026): the real SSH transport read `$target->address`, which
// AuthorizedProbeTarget doesn't have, so every real config backup failed before it connected. The
// stand-in transport in the feature tests hid it. This drives the real transport at a closed local
// port: it must reach the network step and report a plain refusal, not throw.

it('connects to the approved address and reports a closed port as a refusal', function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($probe, false);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    fclose($probe); // Nothing listens there now.

    $target = AuthorizedProbeTarget::fromEgressPolicy(
        siteId: 1, deviceId: 1, scheme: 'tcp', host: '127.0.0.1', port: $port, path: null,
        addresses: ['127.0.0.1'], connectTimeoutSeconds: 2, responseTimeoutSeconds: 2, maxResponseBytes: 1024,
    );

    $result = (new PhpseclibNetworkCliTransport)->runReadOnlyExport(
        $target, ['username' => 'walk-test', 'password' => 'walk-test'], '/export terse', null, 2,
    );

    expect($result->ok)->toBeFalse()
        ->and($result->reasonCode)->not->toBe('');
});
