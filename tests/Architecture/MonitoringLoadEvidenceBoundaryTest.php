<?php

it('keeps local synthetic monitoring performance artifacts unique and ineligible for V09 closure', function () {
    $root = dirname(__DIR__, 2);
    $source = (string) file_get_contents($root.'/tests/Performance/Monitoring/MonitoringLoadTest.php');
    $runbook = (string) file_get_contents($root.'/docs/runbooks/monitoring/load-and-soak-evidence.md');

    expect($source)->toContain(
        "'scale_profile' => \$writeEvidence ? 'full_scale' : 'smoke'",
        "'evidence_contract' => 'monitoring-local-synthetic-v1'",
        "'artifact_id' => \$artifactId",
        "'evidence_classification' => 'local_synthetic_fixture'",
        "'execution_scope' => 'test_process_only'",
        "'deployed_runtime_observed' => false",
        "'soak_duration_proven' => false",
        "'v09_release_evidence' => false",
        "fopen(\$path, 'xb')",
    )->not->toContain(
        'File::put(',
        "'mode' => \$writeEvidence ? 'full' : 'smoke'",
    );

    expect($runbook)->toContain(
        'does not mean a deployed runtime, live dependency, sustained load, or soak observation was exercised',
        'These artifacts are prerequisite regression evidence only and cannot close V09',
        'Preserve each artifact under a unique immutable identity; never overwrite an earlier run',
    );
});
