<?php

it('updates the checkout from origin main before building', function () {
    $script = file_get_contents(__DIR__.'/../../scripts/deploy-server.sh');

    expect($script)
        ->toContain('git fetch --prune origin')
        ->toContain('git pull --ff-only origin main');
});
