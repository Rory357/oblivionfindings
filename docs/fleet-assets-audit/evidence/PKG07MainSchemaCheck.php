<?php

// Read-only metadata check using the candidate's existing test configuration.
$xml = simplexml_load_file(getcwd().'/phpunit.xml');
$env = [];
foreach ($xml->php->env as $entry) {
    $env[(string) $entry['name']] = (string) $entry['value'];
}
$pdo = new PDO('mysql:host='.$env['DB_HOST'].';port='.$env['DB_PORT'], $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$statement = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ? OR SCHEMA_NAME LIKE ? OR SCHEMA_NAME LIKE ?');
$statement->execute(['of\\_main07\\_privacy\\_20260927\\_%', 'of\\_main07\\_legacy\\_20260927\\_%', 'of\\_main07\\_correction\\_20260927\\_%']);
$rows = [];
foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $schema) {
    $count = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
    $count->execute([$schema]);
    $row = ['schema' => $schema, 'tables' => (int) $count->fetchColumn()];
    if (preg_match('/^of_main07_(privacy|legacy|correction)_20260927_[0-9]+$/', $schema)) {
        $activity = $pdo->prepare('SELECT ID, COMMAND, TIME, STATE FROM information_schema.PROCESSLIST WHERE DB = ?');
        $activity->execute([$schema]);
        $row['connections'] = $activity->fetchAll(PDO::FETCH_ASSOC);
        try {
            $row['lastMigration'] = $pdo->query('SELECT migration FROM `'.$schema.'`.migrations ORDER BY id DESC LIMIT 1')->fetchColumn();
        } catch (PDOException) {
            $row['lastMigration'] = null;
        }
    }
    $rows[] = $row;
}
echo json_encode(['remainingReviewSchemas' => count($rows), 'schemas' => $rows], JSON_PRETTY_PRINT).PHP_EOL;
