<?php

// Read only: restrict metadata inspection to this Main review's four prefixes.
$xml = simplexml_load_file(getcwd().'/phpunit.pkg06b.xml');
$environment = [];
foreach ($xml->php->env as $entry) {
    $environment[(string) $entry['name']] = (string) $entry['value'];
}
$pdo = new PDO('mysql:host='.$environment['DB_HOST'].';port='.$environment['DB_PORT'], $environment['DB_USERNAME'], $environment['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$query = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ? OR SCHEMA_NAME LIKE ? OR SCHEMA_NAME LIKE ? OR SCHEMA_NAME LIKE ?');
$query->execute(['of\\_main06b\\_review\\_20260927\\_%', 'of\\_main06b\\_boundary\\_20260927\\_%', 'of\\_main06b\\_kit\\_20260927\\_%', 'of\\_main06b\\_correction\\_20260927\\_%']);
$rows = [];
foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $schema) {
    if (!preg_match('/^of_main06b_(review|boundary|kit|correction)_20260927_[0-9]+$/', $schema)) {
        throw new RuntimeException('Unexpected review schema');
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
    $count->execute([$schema]);
    $row = ['schema' => $schema, 'tables' => (int) $count->fetchColumn()];
    $activity = $pdo->prepare('SELECT ID, COMMAND, TIME, STATE FROM information_schema.PROCESSLIST WHERE DB = ?');
    $activity->execute([$schema]);
    $row['connections'] = $activity->fetchAll(PDO::FETCH_ASSOC);
    try {
        $row['lastMigration'] = $pdo->query('SELECT migration FROM `'.$schema.'`.migrations ORDER BY id DESC LIMIT 1')->fetchColumn();
    } catch (PDOException) {
        $row['lastMigration'] = null;
    }
    $rows[] = $row;
}
echo json_encode(['remainingReviewSchemas' => count($rows), 'schemas' => $rows], JSON_PRETTY_PRINT).PHP_EOL;
