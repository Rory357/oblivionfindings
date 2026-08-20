<?php

test('incident routes emit lifecycle signals while Control Room owns alert transitions', function () {
    $repositoryRoot = dirname(__DIR__, 2);
    $incidentController = file_get_contents($repositoryRoot.'/app/Http/Controllers/IncidentController.php');
    $controlRoomLifecycle = file_get_contents($repositoryRoot.'/app/Services/ControlRoom/ControlRoomAlertLifecycleService.php');
    $signalService = file_get_contents($repositoryRoot.'/app/Services/Incidents/IncidentAlertLifecycleSignalService.php');

    expect($incidentController)
        ->toContain('IncidentAlertLifecycleSignalService')
        ->toContain('recordClose(')
        ->toContain('recordReopen(')
        ->not->toContain('ControlRoomAlert::query()')
        ->and($controlRoomLifecycle)
        ->toContain('applyIncidentLifecycleSignal(')
        ->toContain('resolveAutomatically(')
        ->toContain('reopenLockedFromIncidentSignal(')
        ->and($signalService)
        ->toContain('DispatchIncidentLifecycleSignalOutbox::dispatch(');
});
