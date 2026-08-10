<?php

it('requires sustained value-free native-listener evidence without inventing a cloud provider', function () {
    $command = (string) file_get_contents(__DIR__.'/../../app/Console/Commands/QueclinkStatus.php');
    $script = (string) file_get_contents(__DIR__.'/../../scripts/monitoring/verify-queclink-native-listener-evidence.sh');
    $runbook = (string) file_get_contents(__DIR__.'/../../docs/runbooks/monitoring/queclink-native-listener.md');

    expect($command)->toContain(
        "'canonical_roster_fingerprint'",
        "'frame_execution_fingerprint'",
        "'frame_window'",
        "'oldest_observed_at'",
        "'newest_observed_at'",
        "'evidence_key_missing'",
        "->where('created_at', '<=', \$observedAt)",
        "hash_hmac('sha256'",
        '$pairedTrackers = $trackers->count();',
        'catch (UnexpectedValueException)',
        "'canonical_tracker_resolution_incomplete'",
        "'canonical_paired_trackers' => \$pairedTrackers",
    )->not->toContain(
        "->whereNotNull('device_id')",
        'catch (Throwable)',
    );
    expect($script)->toContain(
        'queclink:status',
        '--evidence-json',
        '"$SAMPLES" -ge 5',
        '"$INTERVAL_SECONDS" -ge 60',
        'the canonical paired-tracker roster changed during the observation period.',
        'persisted native-listener frame evidence did not advance during the observation period.',
        '"$latest_execution_fingerprint" != "$initial_execution_fingerprint"',
        '$fresh !== $canonical',
        '"$latest_oldest_evidence" -gt "$initial_newest_evidence"',
        'every canonical tracker must advance beyond the initial native-listener evidence window.',
    )->not->toContain('provider_queclink', 'cloud API');
    expect($runbook)->toContain(
        'verify-queclink-native-listener-evidence.sh',
        'diagnostic snapshot',
        'does not prove sustained release acceptance',
        'native TCP intake, not a Queclink cloud API integration',
    );
});
