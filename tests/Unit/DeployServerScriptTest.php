<?php

it('updates the checkout from origin main before building', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy-server.sh');

    expect($script)
        ->toContain('git fetch --prune origin')
        ->toContain('git pull --ff-only origin main');
});

it('creates deploy artifacts with web-readable permissions even under a restrictive login umask', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy-server.sh');

    $umaskPosition = strpos($script, 'umask 002');
    $composerPosition = strpos($script, 'run_app composer install');
    $npmPosition = strpos($script, 'run_app npm ci');

    expect($umaskPosition)
        ->not->toBeFalse()
        ->toBeLessThan($composerPosition)
        ->toBeLessThan($npmPosition);
});
