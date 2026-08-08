<?php

it('keeps the release source manifest verifier fail closed and read only', function (): void {
    $root = dirname(__DIR__, 2);
    $verifierPath = $root.'/scripts/release/verify-source-manifest.php';
    $runbookPath = $root.'/docs/runbooks/it-security-release-packaging.md';

    expect($verifierPath)->toBeFile()
        ->and($runbookPath)->toBeFile();

    $verifier = (string) file_get_contents($verifierPath);
    $runbook = (string) file_get_contents($runbookPath);

    expect($verifier)->toContain(
        "['manifest:', 'checkout:']",
        "['rev-parse', '--verify', 'HEAD']",
        "['rev-parse', '--verify', 'refs/remotes/origin/main']",
        "['merge-base', '--is-ancestor'",
        "['status', '--porcelain=v1', '--untracked-files=all']",
        "['-c', 'core.quotePath=false', 'diff', '--name-status', '--find-renames=50%'",
        "source_or_generated'] !== 'source'",
        "hash_file('sha256'",
        'the manifest contains a duplicate path',
        'without a glob',
        'the candidate revision contains an excluded path',
        'the manifest does not exactly match the revision diff',
        'the prepared manifest must remain outside the candidate checkout',
        "str_starts_with(\$lower, '.env.')",
        '\.playwright-cli',
        "'public/build/'",
        "'bootstrap/ssr/'",
        '^resources/js/(actions|routes|wayfinder)(/|$)',
        'png|jpe?g|gif|webp|mp4|webm|mov|har',
        'requireApprovedReview',
        'requirePassedVerification',
        "'.decision must be approved.'",
        "'.result must be passed.'",
    );

    expect(preg_match(
        "/(?:runGit|requireGit)\\([^;]*?\\[\\s*['\"](?:add|commit|push|reset|clean|checkout|switch|restore|rm|mv)['\"]/s",
        $verifier,
    ))->toBe(0);

    foreach ([
        'file_put_contents(',
        'fopen(',
        'unlink(',
        'rmdir(',
        'rename(',
        'copy(',
        'shell_exec(',
        'system(',
        'passthru(',
        'deploy-server.sh',
        'artisan migrate',
    ] as $writeCapability) {
        expect($verifier)->not->toContain($writeCapability);
    }

    expect($runbook)->toContain(
        '## Executable read-only manifest verification',
        'Prepare the exact JSON manifest outside the candidate checkout',
        'The schema is exact. Unknown or missing fields fail verification',
        '"schema_version": 2',
        '"source_or_generated": "source"',
        '"decision": "approved"',
        '"result": "passed"',
        'Non-empty placeholder text is not approval evidence',
        'php /absolute/candidate/scripts/release/verify-source-manifest.php',
        '--manifest=/absolute/external/release-source-manifest.json',
        '--checkout=/absolute/candidate',
        'an unlisted changed file also fails verification',
        'Known Wayfinder output paths and browser/manual evidence extensions are rejected from the revision diff itself',
        '`resources/js/actions/**`, `resources/js/routes/**`, and `resources/js/wayfinder/**` Wayfinder output',
        '`*.png`, `*.jpg`, `*.jpeg`, `*.gif`, `*.webp`, `*.mp4`, `*.webm`, `*.mov`, and `*.har`',
        'does not create a manifest, stage, commit, push, delete, clean, reset, move, build, deploy, or contact a live service',
        'staging still requires separate approval and exact pathspecs',
    );
});
