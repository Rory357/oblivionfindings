<?php
// Remove only the exact synthetic schema owned by this interrupted audit.
$root = realpath(__DIR__.'/../../../../../..');
$state = json_decode(file_get_contents(__DIR__.'/runtime-state.json'), true);
$database = 'oblivion_gov_review3_runtime_20260913_22816';
if (($state['database'] ?? null) !== $database || ($state['pid'] ?? null) !== 22816) {
    throw new RuntimeException('Audit ownership state mismatch');
}
$env = [];
foreach (simplexml_load_file($root.'/phpunit.xml')->php->env as $entry) {
    $env[(string) $entry['name']] = (string) $entry['value'];
}
$pdo = new PDO('mysql:host='.$env['DB_HOST'].';port='.$env['DB_PORT'], $env['DB_USERNAME'], $env['DB_PASSWORD']);
if (!in_array($database, $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), true)) {
    echo json_encode(['database' => $database, 'already_absent' => true]);
    exit;
}
foreach ($pdo->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $connection) {
    if (($connection['db'] ?? null) === $database) {
        throw new RuntimeException('Audit schema still has an active connection');
    }
}
$sentinels = $pdo->query("SELECT COUNT(*) FROM `oblivion_gov_review3_runtime_20260913_22816`.`users` WHERE email IN ('review-chair@example.test','review-member@example.test','review-observer@example.test')")->fetchColumn();
if ((int) $sentinels !== 3) {
    throw new RuntimeException('Expected synthetic audit identities missing');
}
$pdo->exec('DROP DATABASE `oblivion_gov_review3_runtime_20260913_22816`');
echo json_encode(['database' => $database, 'synthetic_sentinels' => 3, 'removed' => !in_array($database, $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), true)], JSON_PRETTY_PRINT);
