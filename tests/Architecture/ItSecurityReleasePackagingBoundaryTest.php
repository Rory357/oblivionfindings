<?php

it('keeps IT Security and Monitoring release packaging source-only exact and separate from publication', function (): void {
    $root = dirname(__DIR__, 2);
    $runbook = (string) file_get_contents($root.'/docs/runbooks/it-security-release-packaging.md');

    expect($runbook)->toContain(
        'Packaging is a read-only inventory and review activity',
        'A packaging manifest is not permission to stage, commit, push, deploy, delete, clean, or move any file',
        'exact candidate `HEAD` SHA',
        'exact `origin/main` SHA',
        'git rev-parse --verify HEAD',
        'git rev-parse --verify refs/remotes/origin/main',
        'git status --porcelain=v1 --untracked-files=all',
        '`HEAD` must equal the approved candidate revision and `origin/main`',
        'The status output must be empty',
        'The manifest must identify every intended file individually',
        'An allowed source family below is a review boundary, not permission to include every file in that directory',
        '`path`',
        '`sha256`',
        '`owner`',
        '`requirement`',
        '`source_or_generated`',
        '`verification`',
    );

    expect($runbook)->toContain(
        '`app/Domain/It/**`',
        '`app/Http/Controllers/It/**`',
        '`resources/js/pages/it/**`',
        '`app/Domain/SecurityDevices/**`',
        '`routes/security-devices.php`',
        '`resources/js/pages/security-devices/**`',
        '`app/Domain/Monitoring/**`',
        '`routes/monitoring-collector.php`',
        '`collector/**`',
        '`scripts/monitoring/**`',
        '`ops/supervisor/oblivion-monitoring-workers.conf`',
        '`ops/supervisor/oblivion-monitoring-listeners.conf`',
        '`scripts/deploy-server.sh`',
        '`scripts/inertia/install-supervisor.sh`',
        '`tests/Feature/It/**`',
        '`tests/Feature/Monitoring/**`',
        '`tests/Feature/SecurityDevices/**`',
        '### Canonical cross-module projections',
        'Site Profile Technology and monitoring projection',
        'Client Profile Healthcare Devices and consent-aware location projection',
        'HR Equipment & Access projection',
        'Fleet, Resident Tracking, vehicle technology, Asset, and Finance reconciliation',
        'Control Room Device signals, map, alert, and sealed monitoring evidence',
        'Browser test source is allowed. Browser test output is not.',
    );

    foreach ([
        '`.env`',
        '`.env.dusk.local`',
        '`.playwright-cli/**`',
        '`.phpunit.result.cache`',
        '`output/**`',
        '`playwright-report/**`',
        '`test-results/**`',
        '`tests/Browser/screenshots/**`',
        '`tests/Browser/console/**`',
        '`storage/logs/**`',
        '`storage/framework/testing/**`',
        '`database/database.sqlite`',
        '`*.sqlite-journal`',
        '`*.sqlite-wal`',
        '`*.sqlite-shm`',
        '`vendor/**`',
        '`node_modules/**`',
        '`public/hot`',
        '`public/build/**`',
        '`bootstrap/ssr/**`',
        '`count()])`',
        "`pluck('migration'))`",
        '`toSql())`',
        "`value('migration')])`",
    ] as $excludedPath) {
        expect($runbook)->toContain($excludedPath);
    }

    expect($runbook)->toContain(
        'These files may support an external evidence pack',
        'They must not be copied into the application source package',
        'Client and SSR builds are verification outputs',
        'Never use `git add -A`, `git add .`, `git commit -am`, or a blanket directory path',
        'Do not delete, clean, reset, or relocate an excluded artifact as part of packaging review',
    );

    $inventory = strpos($runbook, '| Inventory | None |');
    $manifest = strpos($runbook, '| Packaging manifest | None |');
    $staging = strpos($runbook, '| Staging | Git index changes |');
    $commit = strpos($runbook, '| Commit | New repository revision |');
    $push = strpos($runbook, '| Push or pull request | Remote repository change |');
    $deployment = strpos($runbook, '| Deployment | Runtime and data change |');

    expect($inventory)
        ->not->toBeFalse()
        ->and($manifest)->toBeGreaterThan($inventory)
        ->and($staging)->toBeGreaterThan($manifest)
        ->and($commit)->toBeGreaterThan($staging)
        ->and($push)->toBeGreaterThan($commit)
        ->and($deployment)->toBeGreaterThan($push)
        ->and($runbook)->toContain(
            'A manifest does not prove a commit exists',
            'A commit does not prove it was pushed',
            'A push does not prove deployment',
            'A deployment does not prove the final desktop browser',
        );
});
