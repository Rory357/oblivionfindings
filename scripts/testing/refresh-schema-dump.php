#!/usr/bin/env php
<?php

/*
 * Refresh database/schema/mysql-schema.sql, the snapshot every test process
 * (and `migrate` on an empty database) starts from.
 *
 *   php scripts/testing/refresh-schema-dump.php [--keep]
 *
 * 1. Builds a reference database exactly as a test process does today: the
 *    current snapshot, then every pending migration, with phpunit.xml's
 *    environment.
 * 2. Dumps it with mysqldump: structure, the rows migrations inserted (a fresh
 *    build is not empty: permissions, the migrations table and so on), triggers
 *    and routines, with DEFINER clauses removed and auto-increment values kept.
 * 3. Loads the new snapshot into two scratch databases, once through the
 *    `mysql` client and once through the PDO fallback, and checks both match
 *    the reference: whole schema, every table's checksum, every auto-increment
 *    counter. The file is replaced only when both match.
 *
 * Needs the mysql and mysqldump clients (C:\Program Files\MySQL\MySQL Server
 * 8.0\bin on Windows, PATH elsewhere; or MYSQL_CLIENT_BIN). Takes about as
 * long as one old-style test database build (minutes); run it through
 * heavy-lock on the shared PC.
 */

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\MysqlStatementSplitter;
use Tests\Support\TestDatabaseState;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$keep = in_array('--keep', $argv, true);
$schemaPath = $root.'/database/schema/mysql-schema.sql';
$log = static function (string $message): void {
    fwrite(STDOUT, '['.date('H:i:s').'] '.$message.PHP_EOL);
};

// phpunit.xml's environment, with real environment variables taking precedence (as in PHPUnit).
$env = [];
foreach (simplexml_load_file($root.'/phpunit.xml')->php->env as $entry) {
    $env[(string) $entry['name']] = (string) $entry['value'];
}
foreach (array_keys($env) as $name) {
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        $env[$name] = $value;
    }
}

$host = $env['DB_HOST'];
$port = $env['DB_PORT'];
$username = $env['DB_USERNAME'];
$password = $env['DB_PASSWORD'];
$base = $env['DB_DATABASE'];
$suffix = (string) getmypid();
$reference = $base.'_dumpref_'.$suffix;
$viaClient = $base.'_dumpclient_'.$suffix;
$viaPdo = $base.'_dumppdo_'.$suffix;

$binary = static function (string $name): string {
    $exe = DIRECTORY_SEPARATOR === '\\' ? $name.'.exe' : $name;
    foreach (array_filter([getenv('MYSQL_CLIENT_BIN') ?: null, 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin', 'C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin']) as $directory) {
        if (is_file($directory.DIRECTORY_SEPARATOR.$exe)) {
            return $directory.DIRECTORY_SEPARATOR.$exe;
        }
    }

    return (new ExecutableFinder)->find($name) ?? throw new RuntimeException("The $name client was not found; set MYSQL_CLIENT_BIN.");
};
$mysql = $binary('mysql');
$mysqldump = $binary('mysqldump');

$pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port), $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$recreate = static function (string $database) use ($pdo): void {
    $pdo->exec(sprintf('DROP DATABASE IF EXISTS %s', TestDatabaseState::quote($database)));
    $pdo->exec(sprintf('CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', TestDatabaseState::quote($database)));
};
$loadWithClient = static function (string $file, string $database) use ($mysql, $host, $port, $username, $password): void {
    $process = new Process([$mysql, '--host='.$host, '--port='.$port, '--user='.$username, $database], env: ['MYSQL_PWD' => $password]);
    $process->setInput(file_get_contents($file));
    $process->setTimeout(1800);
    $process->mustRun();
};
$migrate = static function (string $database) use ($root, $env): string {
    $process = new Process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], $root, [...$env, 'DB_DATABASE' => $database]);
    $process->setTimeout(3600);
    $process->mustRun();

    return $process->getOutput();
};

$scratch = [$reference, $viaClient, $viaPdo];
$exitCode = 1;

try {
    $started = microtime(true);
    $recreate($reference);
    $loadWithClient($schemaPath, $reference);
    $log(sprintf('Loaded the current snapshot in %.1fs.', microtime(true) - $started));

    $started = microtime(true);
    $migrate($reference);
    $pending = (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s.`migrations` WHERE batch > 1', TestDatabaseState::quote($reference)))->fetchColumn();
    $log(sprintf('Ran pending migrations in %.1fs (%d migrations after the snapshot).', microtime(true) - $started, $pending));

    $expected = TestDatabaseState::capture($pdo, $reference);

    $dump = new Process([
        $mysqldump,
        '--host='.$host,
        '--port='.$port,
        '--user='.$username,
        '--no-tablespaces',
        '--skip-add-locks',
        '--skip-comments',
        '--skip-set-charset',
        '--tz-utc',
        '--column-statistics=0',
        '--set-gtid-purged=OFF',
        '--routines',
        '--triggers',
        '--hex-blob',
        '--order-by-primary',
        '--skip-extended-insert',
        '--complete-insert',
        $reference,
    ], env: ['MYSQL_PWD' => $password]);
    $dump->setTimeout(1800);
    $dump->mustRun();

    // mysqldump.exe writes CRLF; the file is LF like the rest of the repo (data
    // keeps its own line breaks escaped as \r and \n, so only line ends change).
    $sql = str_replace("\r\n", "\n", $dump->getOutput());
    // Objects take their creator as definer when loaded, so the file works for any test user.
    $sql = preg_replace('~/\*!50017 DEFINER=`[^`]*`@`[^`]*`\s*\*/\s*~', '', $sql);
    $sql = preg_replace('~ DEFINER=`[^`]*`@`[^`]*`~', '', (string) $sql);
    $candidate = $schemaPath.'.new';
    file_put_contents($candidate, $sql);
    $log(sprintf('Dumped %s (%.1f MB).', basename($candidate), strlen((string) $sql) / 1048576));

    $results = [];
    foreach (['mysql client' => $viaClient, 'PDO fallback' => $viaPdo] as $path => $database) {
        $started = microtime(true);
        $recreate($database);
        if ($database === $viaClient) {
            $loadWithClient($candidate, $database);
        } else {
            $load = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database), $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach (MysqlStatementSplitter::split((string) file_get_contents($candidate)) as $statement) {
                $load->exec($statement);
            }
        }
        $loaded = microtime(true) - $started;
        $output = $migrate($database);
        $actual = TestDatabaseState::capture($pdo, $database);
        $differences = $actual->differencesFrom($expected);
        $same = $actual->matches($expected);
        $results[$path] = $same;
        $log(sprintf(
            '%s: loaded in %.1fs; schema %s; %d tables differ in rows, %d in auto-increment; migrate: %s',
            $path,
            $loaded,
            $actual->schemaHash === $expected->schemaHash ? 'identical' : 'DIFFERENT',
            count($differences['data']),
            count($differences['autoIncrement']),
            str_contains($output, 'Nothing to migrate') ? 'nothing to migrate' : trim($output),
        ));
    }

    if (in_array(false, $results, true)) {
        $log('The new snapshot does not reproduce the reference build; left it at '.basename($candidate).' and kept the old file.');
    } else {
        rename($candidate, $schemaPath);
        $log(sprintf(
            'Replaced database/schema/mysql-schema.sql: %d tables, %d migrations, schema hash %s.',
            count($expected->checksums),
            (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s.`migrations`', TestDatabaseState::quote($reference)))->fetchColumn(),
            substr($expected->schemaHash, 0, 16),
        ));
        $exitCode = 0;
    }
} finally {
    if (! $keep) {
        foreach ($scratch as $database) {
            $pdo->exec(sprintf('DROP DATABASE IF EXISTS %s', TestDatabaseState::quote($database)));
        }
    } else {
        $log('Kept '.implode(', ', $scratch).'.');
    }
}

exit($exitCode);
