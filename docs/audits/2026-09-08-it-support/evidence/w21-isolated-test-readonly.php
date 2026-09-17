<?php

// Metadata only: no Laravel boot, application records, SQL text or mutations.
$itRoot = realpath(__DIR__.'/../../../..');
$itToken = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || $itRoot !== 'C:\\Users\\steph\\Herd\\oblivionfindings' || ! preg_match('/^it_[a-f0-9]{16}$/D', $itToken)) {
    throw new RuntimeException('Unexpected isolated test inspection target.');
}
$itSettings = simplexml_load_file($itRoot.'/phpunit.xml');
$itCredentials = [];
foreach ($itSettings->php->env as $itSetting) {
    if (in_array((string) $itSetting['name'], ['DB_USERNAME', 'DB_PASSWORD'], true)) {
        $itCredentials[(string) $itSetting['name']] = (string) $itSetting['value'];
    }
}
$itDatabase = 'oblivion_it_support_test_'.$itToken;
$itPdo = new PDO('mysql:host=127.0.0.1;port=3306', $itCredentials['DB_USERNAME'], $itCredentials['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
$itTables = $itPdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
$itTables->execute([$itDatabase]);
$itProcesses = $itPdo->prepare('SELECT ID, COMMAND, TIME, STATE FROM information_schema.PROCESSLIST WHERE DB = ?');
$itProcesses->execute([$itDatabase]);
echo json_encode(['utc' => gmdate(DATE_ATOM), 'database' => $itDatabase, 'tables' => (int) $itTables->fetchColumn(), 'connections' => $itProcesses->fetchAll(PDO::FETCH_ASSOC), 'mutations_performed' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
