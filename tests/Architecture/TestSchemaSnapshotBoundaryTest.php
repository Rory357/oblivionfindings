<?php

/*
 * database/schema/mysql-schema.sql is what every test process (and `migrate`
 * on an empty database) starts from. scripts/testing/refresh-schema-dump.php
 * makes it from a full build and proves it loads to the same database. A plain
 * `php artisan schema:dump` would keep the tables but drop the rows migrations
 * insert (permissions, roles, signal types...), so tests would start from a
 * different database than a migrated one.
 */

it('keeps the schema snapshot a proven full dump of a migrated database', function () {
    $root = dirname(__DIR__, 2);
    $snapshot = (string) file_get_contents($root.'/database/schema/mysql-schema.sql');
    $migrations = glob($root.'/database/migrations/*.php') ?: [];

    expect($snapshot)
        // Rows inserted by migrations, not only the migrations table.
        ->toContain('INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (')
        ->toContain('INSERT INTO `permissions` (')
        // Loads as any test user.
        ->not->toMatch('/DEFINER\s*=/')
        // Triggers are part of the build.
        ->toContain('DELIMITER ;;');

    // Every migration in the snapshot still exists, so nothing replays out of order.
    preg_match_all("/INSERT INTO `migrations` \\(`id`, `migration`, `batch`\\) VALUES \\(\\d+,'([^']+)',\\d+\\);/", $snapshot, $recorded);
    $files = array_map(fn (string $path): string => basename($path, '.php'), $migrations);

    expect($recorded[1])->not->toBeEmpty()
        ->and(array_diff($recorded[1], $files))->toBe([])
        ->and(file_get_contents($root.'/scripts/testing/refresh-schema-dump.php'))
        ->toContain('--skip-extended-insert')
        ->toContain('TestDatabaseState::capture');
});
