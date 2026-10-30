<?php

// Metadata-only read. No Laravel bootstrap, operating data or database mutation.
$config = simplexml_load_file(getcwd().'/phpunit.xml');
$settings = [];
foreach ($config->php->env as $entry) {
    $settings[(string) $entry['name']] = (string) $entry['value'];
}
$pdo = new PDO('mysql:host='.$settings['DB_HOST'].';port='.$settings['DB_PORT'].';charset=utf8mb4', $settings['DB_USERNAME'], $settings['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$prefix = 'of_main_pkg09b_review_20260928_';
$query = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE LEFT(SCHEMA_NAME, :length) = :prefix');
$query->execute(['length' => strlen($prefix), 'prefix' => $prefix]);
$remaining = $query->fetchAll(PDO::FETCH_COLUMN);
$processes = $pdo->prepare('SELECT ID, DB, COMMAND, TIME, STATE, SUBSTRING_INDEX(TRIM(INFO), CHAR(32), 1) AS operation, SHA2(INFO, 256) AS queryHash FROM information_schema.PROCESSLIST WHERE LEFT(DB, :length) = :prefix');
$processes->execute(['length' => strlen($prefix), 'prefix' => $prefix]);
echo json_encode(['databasePrefix' => $prefix, 'remaining' => $remaining, 'count' => count($remaining), 'processes' => $processes->fetchAll(PDO::FETCH_ASSOC)], JSON_PRETTY_PRINT), PHP_EOL;
