<?php

$root = dirname(__DIR__, 5);
$xml = simplexml_load_file($root.'/phpunit.pkg06b.xml');
$env = [];
foreach ($xml->php->env as $row) {
    $env[(string) $row['name']] = (string) $row['value'];
}
$pdo = new PDO('mysql:host='.$env['DB_HOST'].';port='.$env['DB_PORT'], $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ($pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (str_starts_with((string) $row['db'], 'oblivion_findings_pkg06b_8821_test_')) {
        $info = array_intersect_key($row, array_flip(['Id', 'db', 'Command', 'Time', 'State']));
        if (preg_match('/^(alter table|create table)/i', trim($row['Info'] ?? ''))) {
            $info['ddl'] = substr($row['Info'], 0, 130);
        }
        if ($row['State'] === 'Waiting for table metadata lock') {
            $info['waiting_query'] = substr(preg_replace("/'(?:[^']|'')*'/", "'…'", $row['Info'] ?? ''), 0, 300);
        }
        echo json_encode($info).PHP_EOL;
        if (preg_match('/^oblivion_findings_pkg06b_8821_test_\d+$/', $row['db'])) {
            try {
                echo json_encode($pdo->query('SELECT COUNT(*) AS completed, MAX(migration) AS latest FROM `'.$row['db'].'`.`migrations`')->fetch(PDO::FETCH_ASSOC)).PHP_EOL;
            } catch (Throwable) {
            }
        }
    }
}
