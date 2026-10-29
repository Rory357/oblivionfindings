<?php

use Tests\TestCase;

uses(TestCase::class);

/*
 * Phase A walk step 3: the command Stephan (or MASTER on the .com) runs to see the alarm receiver read
 * reference alarm reports and reply correctly. No database, no network.
 */
it('reads every reference alarm report and replies correctly', function (): void {
    $this->artisan('security-devices:sia-dc09-check')
        ->expectsOutputToContain('Every reference report was read')
        ->assertSuccessful();
});
