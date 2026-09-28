<?php

// Metadata-only check of this review's disposable database and active test work.
$config = simplexml_load_file(getcwd().'/phpunit.xml');
$settings = [];
foreach ($config->php->env as $entry) {
    $settings[(string) $entry['name']] = (string) $entry['value'];
}
$pdo = new PDO('mysql:host='.$settings['DB_HOST'].';port='.$settings['DB_PORT'].';charset=utf8mb4', $settings['DB_USERNAME'], $settings['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$prefix = $argv[1] ?? 'of_main_combined_history_20260928_';
if (! in_array($prefix, ['of_main_combined_history_20260928_', 'of_main_combined_map_20260928_'], true)) {
    throw new InvalidArgumentException('Only the two named Main review database prefixes are supported.');
}
$query = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE LEFT(SCHEMA_NAME, :length) = :prefix');
$query->execute(['length' => strlen($prefix), 'prefix' => $prefix]);
$remaining = $query->fetchAll(PDO::FETCH_COLUMN);
$query = $pdo->prepare('SELECT ID, DB, COMMAND, TIME, STATE FROM information_schema.PROCESSLIST WHERE LEFT(DB, :length) = :prefix');
$query->execute(['length' => strlen($prefix), 'prefix' => $prefix]);
echo json_encode(['databasePrefix' => $prefix, 'remaining' => $remaining, 'count' => count($remaining), 'active' => $query->fetchAll(PDO::FETCH_ASSOC)], JSON_PRETTY_PRINT), PHP_EOL;
