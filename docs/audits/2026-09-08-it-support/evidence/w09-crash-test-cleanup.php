<?php

declare(strict_types=1);

/** Exact W09 crash-interrupted test residue; log and diagnostic identify this schema. Default mode is read-only preview. */
$root = realpath(__DIR__.'/../../../..');
if (PHP_SAPI !== 'cli' || $root !== 'C:\\Users\\steph\\Herd\\oblivionfindings' || realpath(getcwd()) !== $root) {
    throw new RuntimeException('Cleanup checkout identity is invalid.');
}
$schemas = ['oblivion_it_support_test_it_e3543a5fed244ee4'];
$configuration = simplexml_load_file($root.'/phpunit.xml');
$settings = [];
foreach ($configuration->php->env as $entry) {
    $settings[(string) $entry['name']] = (string) $entry['value'];
}
if (($settings['APP_ENV'] ?? '') !== 'testing'
    || ($settings['DB_CONNECTION'] ?? '') !== 'mysql'
    || ($settings['DB_HOST'] ?? '') !== '127.0.0.1'
    || ($settings['MAIL_MAILER'] ?? '') !== 'array'
    || ($settings['QUEUE_CONNECTION'] ?? '') !== 'sync') {
    throw new RuntimeException('Test configuration identity is invalid.');
}
$pdo = new PDO('mysql:host=127.0.0.1;port=3306', $settings['DB_USERNAME'], $settings['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$inspect = static function (string $schema) use ($pdo): array {
    if (preg_match('/^oblivion_it_support_test_it_[a-f0-9]{16}$/D', $schema) !== 1) {
        throw new RuntimeException('Refusing an invalid exact test schema.');
    }
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $statement->execute([$schema]);
    $exists = (bool) $statement->fetchColumn();
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
    $statement->execute([$schema]);
    $tables = (int) $statement->fetchColumn();
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = ?');
    $statement->execute([$schema]);

    return ['schema' => $schema, 'exists' => $exists, 'table_count' => $tables, 'active_connections' => (int) $statement->fetchColumn()];
};
$before = array_map($inspect, $schemas);
foreach ($before as $state) {
    if ($state['active_connections'] !== 0) {
        throw new RuntimeException('An exact test schema still has an active connection; no cleanup performed.');
    }
}
$fingerprint = hash('sha256', json_encode(['source' => hash_file('sha256', __FILE__), 'before' => $before], JSON_THROW_ON_ERROR));
$arguments = array_slice($argv, 1);
$apply = count($arguments) === 1 && $arguments[0] === '--apply='.$fingerprint;
if ($arguments !== [] && ! $apply) {
    throw new RuntimeException('An exact current preview fingerprint is required.');
}
echo json_encode(['mode' => $apply ? 'apply' : 'preview', 'fingerprint' => $fingerprint, 'before' => $before, 'database_mutations_performed' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
if (! $apply) {
    exit(0);
}
foreach ($before as $state) {
    // Recheck each immutable target immediately before its own bounded drop.
    if ($inspect($state['schema']) !== $state) {
        throw new RuntimeException('Exact test schema changed since preview; cleanup stopped.');
    }
    if ($state['exists']) {
        $pdo->exec('DROP DATABASE `'.$state['schema'].'`');
    }
}
$after = array_map($inspect, $schemas);
foreach ($after as $state) {
    if ($state['exists'] || $state['table_count'] !== 0 || $state['active_connections'] !== 0) {
        throw new RuntimeException('A named test schema remains after cleanup.');
    }
}
echo json_encode(['mode' => 'postflight', 'after' => $after, 'database_mutations_performed' => true, 'all_exact_schemas_absent' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;

