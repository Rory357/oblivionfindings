<?php

// Metadata only for Main's two expressly disposable audit schemas.
$config = simplexml_load_file(getcwd().'/phpunit.xml');
$settings = [];
foreach ($config->php->env as $entry) $settings[(string) $entry['name']] = (string) $entry['value'];
$pdo = new PDO('mysql:host='.$settings['DB_HOST'].';port='.$settings['DB_PORT'].';charset=utf8mb4', $settings['DB_USERNAME'], $settings['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$results = [];
foreach (['of_main_pkg03_review_20260928_', 'of_main_pkg09a_review_20260928_'] as $prefix) {
    $q = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE LEFT(SCHEMA_NAME, :length) = :prefix');
    $q->execute(['length' => strlen($prefix), 'prefix' => $prefix]);
    $schemas = $q->fetchAll(PDO::FETCH_COLUMN);
    $q = $pdo->prepare('SELECT ID, DB, COMMAND, TIME, STATE, SUBSTRING_INDEX(TRIM(INFO), CHAR(32), 1) AS operation FROM information_schema.PROCESSLIST WHERE LEFT(DB, :length) = :prefix');
    $q->execute(['length' => strlen($prefix), 'prefix' => $prefix]);
    $results[] = ['prefix' => $prefix, 'schemas' => $schemas, 'processes' => $q->fetchAll(PDO::FETCH_ASSOC)];
}
echo json_encode($results, JSON_PRETTY_PRINT), PHP_EOL;
