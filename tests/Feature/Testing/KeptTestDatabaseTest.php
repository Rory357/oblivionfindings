<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\KeptTestDatabase;
use Tests\Support\OwnedTestDatabase;

/*
 * These run against throwaway databases of their own (`oblivion_kept_selftest_*`)
 * on a separate server connection, never this test's database.
 */

function keptSelfTestServer(): PDO
{
    $config = config('database.connections.mysql');

    return new PDO(
        sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']),
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function dropKeptSelfTestDatabases(PDO $server): void
{
    foreach ($server->query("SHOW DATABASES LIKE 'oblivion\\_kept\\_selftest\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $database) {
        $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    }
}

it('reuses, migrates or rebuilds a kept database as its migrations and committed rows require', function (): void {
    $server = keptSelfTestServer();
    $kept = KeptTestDatabase::claim($server, 'oblivion_kept_selftest', sys_get_temp_dir().'/kept-selftest', 1);
    $table = '`'.$kept->name().'`.`things`';
    $migrations = ['2026_01_01_000000_create_things' => 'a', '2026_01_02_000000_add_colour' => 'b'];

    try {
        $kept->recreate();
        expect($kept->inspect($migrations))->toBe(['rebuild', 'it has no record of its migrations']);

        $server->exec("CREATE TABLE {$table} (`id` int PRIMARY KEY, `name` varchar(20))");
        $server->exec("INSERT INTO {$table} VALUES (1, 'from a migration')");
        $kept->recordBaseline($migrations);

        expect($kept->inspect($migrations))->toBe(['reuse', 'unchanged'])
            ->and($kept->inspect([...$migrations, '2026_01_03_000000_add_size' => 'c']))->toBe(['migrate', '1 new migration(s)'])
            ->and($kept->inspect(['2026_01_01_000000_create_things' => 'a']))
            ->toBe(['rebuild', 'migration 2026_01_02_000000_add_colour is no longer in this checkout'])
            ->and($kept->inspect([...$migrations, '2026_01_02_000000_add_colour' => 'edited']))
            ->toBe(['rebuild', 'migration 2026_01_02_000000_add_colour has changed'])
            ->and($kept->inspect($migrations, forceRebuild: true))->toBe(['rebuild', 'TEST_DB_REBUILD is set']);

        $server->exec("CREATE TABLE `{$kept->name()}`.`empty_things` (`id` int PRIMARY KEY)");
        $kept->recordBaseline($migrations);

        $server->exec("INSERT INTO {$table} VALUES (2, 'committed by a test')");
        $server->exec("UPDATE {$table} SET `name` = 'changed by a test' WHERE `id` = 1");
        $server->exec("INSERT INTO `{$kept->name()}`.`empty_things` VALUES (7)");
        expect($kept->inspect($migrations))->toBe(['repair', 'a test left committed rows in empty_things, things'])
            ->and($kept->repair())->toBeTrue()
            ->and($kept->inspect($migrations))->toBe(['reuse', 'unchanged'])
            ->and($server->query("SELECT `id`, `name` FROM {$table}")->fetchAll(PDO::FETCH_NUM))->toBe([[1, 'from a migration']])
            ->and($server->query("SELECT COUNT(*) FROM `{$kept->name()}`.`empty_things`")->fetchColumn())->toBe(0);

        $server->exec("ALTER TABLE {$table} ADD `extra` int NULL");
        $server->exec("INSERT INTO {$table} (`id`, `name`) VALUES (3, 'after the change')");
        $server->exec("CREATE TABLE `{$kept->name()}`.`made_by_a_test` (`id` int)");
        $server->exec("DROP TABLE `{$kept->name()}`.`empty_things`");
        expect($kept->inspect($migrations))
            ->toBe(['repair', 'a test left schema changes in empty_things, made_by_a_test, things'])
            ->and($kept->repair())->toBeTrue()
            ->and($kept->inspect($migrations))->toBe(['reuse', 'unchanged'])
            ->and($server->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC))->toBe([['id' => 1, 'name' => 'from a migration']])
            ->and($server->query("SHOW TABLES FROM `{$kept->name()}`")->fetchAll(PDO::FETCH_COLUMN))->toBe(['empty_things', 'things']);
    } finally {
        dropKeptSelfTestDatabases($server);
    }
});

it('gives a second run the next free slot and frees a slot when its run ends', function (): void {
    $path = sys_get_temp_dir().'/kept-selftest';
    $firstServer = keptSelfTestServer();

    try {
        $first = KeptTestDatabase::claim($firstServer, 'oblivion_kept_selftest', $path, 1);
        $second = KeptTestDatabase::claim(keptSelfTestServer(), 'oblivion_kept_selftest', $path, 1);

        expect($first->name())->toEndWith('_1')
            ->and($second->name())->toEndWith('_2')
            ->and(substr($first->name(), 0, -2))->toBe(substr($second->name(), 0, -2));

        // Closing the session frees its lock, as a run that exits or dies does.
        unset($first);
        $firstServer = null;

        expect(KeptTestDatabase::claim(keptSelfTestServer(), 'oblivion_kept_selftest', $path, 1)->name())->toEndWith('_1');
    } finally {
        dropKeptSelfTestDatabases(keptSelfTestServer());
    }
});

it('names slots per checkout, so worktrees never share one', function (): void {
    $server = keptSelfTestServer();

    try {
        $here = KeptTestDatabase::claim($server, 'oblivion_kept_selftest', sys_get_temp_dir().'/checkout-a', 1);
        $there = KeptTestDatabase::claim($server, 'oblivion_kept_selftest', sys_get_temp_dir().'/checkout-b', 1);

        expect($here->name())->not->toBe($there->name())
            ->and($here->name())->toEndWith('_1')
            ->and($there->name())->toEndWith('_1');
    } finally {
        dropKeptSelfTestDatabases($server);
    }
});

it('proves from any connection which process holds this run\'s kept slot', function (): void {
    $database = DB::connection()->getDatabaseName();

    if (preg_match('/_k[0-9a-f]{6}_\d+\z/', $database) !== 1) {
        $this->markTestSkipped('This run uses a throwaway database.');
    }

    expect(OwnedTestDatabase::isOwnedBy($database, getmypid()))->toBeTrue()
        ->and(OwnedTestDatabase::isOwnedBy($database, getmypid() + 1))->toBeFalse()
        ->and(OwnedTestDatabase::isOwnedBy($database.'0', getmypid()))->toBeFalse();
});

it('lists every migration file by name with a hash of its contents', function (): void {
    $manifest = KeptTestDatabase::migrationManifest(database_path('migrations'));

    expect($manifest)->toHaveCount(count(glob(database_path('migrations/*.php'))))
        ->and(array_key_first($manifest))->not->toEndWith('.php')
        ->and($manifest)->each->toMatch('/\A[0-9a-f]{40}\z/');
});
