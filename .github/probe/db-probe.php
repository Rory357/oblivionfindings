<?php
// Throwaway CI probe (branch probe/test-speed-db only): time test-database builds on a GitHub runner.
require __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Process\Process;
use Tests\Support\MysqlStatementSplitter;
use Tests\Support\TestDatabaseState;

$root = dirname(__DIR__, 2);
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'testUser', 'test101', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$log = fn (string $m) => fwrite(STDOUT, '['.date('H:i:s')."] $m\n");
$q = fn (string $db) => '`'.$db.'`';
$fresh = function (string $db) use ($pdo, $q) { $pdo->exec('DROP DATABASE IF EXISTS '.$q($db)); $pdo->exec('CREATE DATABASE '.$q($db).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); };
$client = function (string $file, string $db) {
    $p = new Process(['mysql', '--host=127.0.0.1', '--port=3306', '--user=testUser', $db], env: ['MYSQL_PWD' => 'test101']);
    $p->setInput(file_get_contents($file)); $p->setTimeout(3600); $p->mustRun();
};
$env = [];
foreach (simplexml_load_file($root.'/phpunit.xml')->php->env as $e) { $env[(string) $e['name']] = (string) $e['value']; }
$migrate = function (string $db) use ($root, $env) {
    $p = new Process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], $root, [...$env, 'DB_DATABASE' => $db]);
    $p->setTimeout(3600); $p->mustRun();
};
$t = fn () => microtime(true);

// Old way: the snapshot main has (21 Oct file), then pending migrations.
$s = $t(); $fresh('probe_old'); $client($root.'/old-schema.sql', 'probe_old'); $a = $t() - $s;
$s = $t(); $migrate('probe_old'); $b = $t() - $s;
$log(sprintf('OLD snapshot: load %.1fs + pending migrations %.1fs = %.1fs', $a, $b, $a + $b));
$old = TestDatabaseState::capture($pdo, 'probe_old');

// New snapshot through the client and through PDO.
$s = $t(); $fresh('probe_new'); $client($root.'/database/schema/mysql-schema.sql', 'probe_new'); $a = $t() - $s;
$s = $t(); $migrate('probe_new'); $b = $t() - $s;
$new = TestDatabaseState::capture($pdo, 'probe_new');
$log(sprintf('NEW snapshot (mysql client): load %.1fs + migrate %.1fs; identical to old build: %s', $a, $b, $new->matches($old) ? 'yes' : 'NO'));
$diff = $new->differencesFrom($old);
$log(sprintf('  schema identical: %s; tables with different rows: %s; different auto-increment: %s', $new->schemaHash === $old->schemaHash ? 'yes' : 'NO', json_encode($diff['data']), json_encode($diff['autoIncrement'])));
foreach ($diff['data'] as $tbl) {
    $cols = $pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='probe_old' AND TABLE_NAME='$tbl' AND COLUMN_NAME NOT IN ('created_at','updated_at')")->fetchColumn();
    $cols = implode(',', array_map(fn ($c) => "`$c`", explode(',', $cols)));
    $a = $pdo->query("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', $cols) ORDER BY 1)) FROM `probe_old`.`$tbl`")->fetchColumn();
    $b = $pdo->query("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', $cols) ORDER BY 1)) FROM `probe_new`.`$tbl`")->fetchColumn();
    $log(sprintf('  %s: rows equal apart from created_at/updated_at: %s', $tbl, $a === $b ? 'yes' : 'NO'));
}
$s = $t(); $fresh('probe_pdo');
$conn = new PDO('mysql:host=127.0.0.1;port=3306;dbname=probe_pdo;charset=utf8mb4', 'testUser', 'test101', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (MysqlStatementSplitter::split(file_get_contents($root.'/database/schema/mysql-schema.sql')) as $st) { $conn->exec($st); }
$a = $t() - $s;
$log(sprintf('NEW snapshot (PDO fallback): load %.1fs; identical: %s', $a, TestDatabaseState::capture($pdo, 'probe_pdo')->matches($old) ? 'yes' : 'NO'));
$log(sprintf('  PDO load identical to the mysql client load: %s', TestDatabaseState::capture($pdo, 'probe_pdo')->matches($new) ? 'yes' : 'NO'));

// Verification and move costs.
$s = $t(); TestDatabaseState::capture($pdo, 'probe_new'); $log(sprintf('capture state: %.2fs', $t() - $s));
$tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='probe_new' AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
$triggers = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='probe_new'")->fetchAll(PDO::FETCH_COLUMN);
$s = $t();
foreach ($triggers as $tr) { $pdo->exec("DROP TRIGGER `probe_new`.`$tr`"); }
$fresh('probe_moved');
$pdo->exec('RENAME TABLE '.implode(', ', array_map(fn ($x) => "`probe_new`.`$x` TO `probe_moved`.`$x`", $tables)));
$log(sprintf('RENAME %d tables to another database: %.2fs', count($tables), $t() - $s));
$s = $t(); $pdo->exec('DROP DATABASE `probe_moved`'); $log(sprintf('DROP DATABASE (%d tables): %.2fs', count($tables), $t() - $s));

// Three builds at once, as three workers would.
$s = $t();
$procs = [];
foreach ([1, 2, 3] as $i) { $fresh("probe_par_$i"); $p = new Process(['bash', '-c', "MYSQL_PWD=test101 mysql --host=127.0.0.1 --user=testUser probe_par_$i < database/schema/mysql-schema.sql"], $root); $p->setTimeout(3600); $p->start(); $procs[] = $p; }
foreach ($procs as $p) { $p->wait(); }
$log(sprintf('3 parallel NEW snapshot loads: %.1fs', $t() - $s));
$log('nproc='.trim((string) shell_exec('nproc')).' mem='.trim((string) shell_exec("free -g | awk '/Mem/{print \$2}'")).'G');
