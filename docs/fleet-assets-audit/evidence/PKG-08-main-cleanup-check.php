<?php

// Metadata-only verification. No Laravel bootstrap or database mutation.
$config = simplexml_load_file(getcwd().'/phpunit.xml');
$settings = [];
foreach ($config->php->env as $entry) {
    $settings[(string) $entry['name']] = (string) $entry['value'];
}
$pdo = new PDO('mysql:host='.$settings['DB_HOST'].';port='.$settings['DB_PORT'].';charset=utf8mb4', $settings['DB_USERNAME'], $settings['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$prefix = 'of_main_pkg08_review_20260928_';
$query = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE LEFT(SCHEMA_NAME, :length) = :prefix');
$query->execute(['length' => strlen($prefix), 'prefix' => $prefix]);
$remaining = $query->fetchAll(PDO::FETCH_COLUMN);
echo json_encode(['databasePrefix' => $prefix, 'remaining' => $remaining, 'count' => count($remaining)], JSON_PRETTY_PRINT), PHP_EOL;
