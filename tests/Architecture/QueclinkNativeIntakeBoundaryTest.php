<?php

it('keeps native Queclink intake bounded and operational logs value free', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $listener = file_get_contents($root.'/app/Services/Queclink/Listener/TcpListener.php');
    $router = file_get_contents($root.'/app/Services/Queclink/Listener/FrameRouter.php');
    $parser = file_get_contents($root.'/app/Services/Queclink/AtTrackProtocolParser.php');
    $limits = file_get_contents($root.'/app/Services/Queclink/Listener/ListenerLimits.php');
    $events = file_get_contents($root.'/app/Services/Queclink/Listener/ListenerSecurityEventAggregator.php');
    $retention = file_get_contents($root.'/app/Jobs/PrunePersonalTrackingTelemetry.php');
    $logCalls = function (string $source): string {
        preg_match_all('/Log::(?:debug|info|warning|error)\([\s\S]*?\]\)\);/', $source, $matches);

        return implode("\n", $matches[0]);
    };
    $listenerLogs = $logCalls($listener);
    $routerLogs = $logCalls($router);

    expect($listener)
        ->toContain(
            'ListenerPressureGuard',
            'maxBufferBytes + 1',
            'pruneIdleConnections',
            'flushSecurityEvents',
            'SafeOperationalData::logContext',
        )
        ->not->toContain('$state->touch();');

    expect($listenerLogs)->toContain('SafeOperationalData::logContext')
        ->not->toContain(
            "'session' =>",
            "'frame' =>",
            "'imei' =>",
            "'peer' =>",
            'substr($rawFrame',
            '$e->getMessage()',
        );

    expect($router)
        ->toContain(
            "throw new IntakeRejected('invalid_frame')",
            "throw new IntakeRejected('invalid_direction')",
            "['RESP', 'BUFF', 'ACK']",
            'frameLineage',
            'SafeOperationalData::logContext',
        );

    expect($routerLogs)->toContain('SafeOperationalData::logContext')
        ->not->toContain(
            "'session' =>",
            "'frame' =>",
            "'imei' =>",
            "'peer' =>",
            '$e->getMessage()',
        );

    $rejectionPosition = strpos($router, "throw new IntakeRejected('invalid_frame')");
    $resolvePosition = strpos($router, '$this->resolveDevice');
    $persistencePosition = strpos($router, '$this->logRaw');

    expect($rejectionPosition)->toBeLessThan($resolvePosition)
        ->and($resolvePosition)->toBeLessThan($persistencePosition);

    expect($parser)->toContain('maxBufferBytes', 'maxFrameBytes', "throw new IntakeRejected('frame_limit')")
        ->and($limits)->toContain(
            'maxConnectionsPerSource',
            'connectionAttemptsPerWindow',
            'invalidFramesPerWindow',
            'idleTimeoutSeconds',
        )
        ->and($events)->toContain(
            'ALLOWED_CATEGORIES',
            'function drain',
            'ksort($counts)',
        )
        ->and($retention)->toContain(
            'excludeActiveRawFrameHolds',
            "Schema::hasTable('legal_holds')",
            'raw_frame_retention_days',
            "whereColumn('holdable_id', 'queclink_raw_frames.canonical_device_id')",
            "whereColumn('holdable_id', 'queclink_raw_frames.device_assignment_id')",
        );
});
