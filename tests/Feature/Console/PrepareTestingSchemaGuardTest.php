<?php

/*
 * testing:prepare-schema wipes the database it builds in. Without a
 * .env.testing, `--env=testing` reads .env, whose database is the developer's
 * own. The name here is made up and exists nowhere, so even a broken guard
 * could only wipe an empty database.
 */
it('refuses to wipe a database whose name does not say it is for tests', function (): void {
    config(['database.connections.guard_probe' => [
        ...config('database.connections.mysql'),
        'database' => 'oblivion_guard_probe',
    ]]);

    $this->artisan('testing:prepare-schema', ['--database' => 'guard_probe', '--from-scratch' => true])
        ->expectsOutputToContain('Refusing to wipe [oblivion_guard_probe]')
        ->assertFailed();
});
