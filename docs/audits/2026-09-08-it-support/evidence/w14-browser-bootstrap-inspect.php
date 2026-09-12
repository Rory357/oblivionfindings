<?php

// Read-only metadata inspection of one explicitly owned failed browser bootstrap.
$token = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || preg_match('/\A[a-f0-9]{16}\z/', $token) !== 1) { exit(2); }
$repo = realpath(__DIR__.'/../../../..');
$root = $repo.'/storage/framework/testing/it-draft-browser-'.$token;
$owner = json_decode(file_get_contents($root.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
$database = 'oblivion_it_draft_browser_'.$token;
if (($owner['token'] ?? null) !== $token || ($owner['database'] ?? null) !== $database
    || str_replace('\\', '/', (string) realpath($root)) !== str_replace('\\', '/', $root)) { exit(3); }
$access = [];
foreach (simplexml_load_file($repo.'/phpunit.xml')->php->env as $setting) {
    if (in_array((string) $setting['name'], ['DB_USERNAME', 'DB_PASSWORD'], true)) { $access[(string) $setting['name']] = (string) $setting['value']; }
}
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database, $access['DB_USERNAME'], $access['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($access);
    $report = ['token' => $token, 'database_matches' => $pdo->query('SELECT DATABASE()')->fetchColumn() === $database, 'mutations_performed' => false];
    $tables = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
    $tables->execute([$database]);
    $tableNames = $tables->fetchAll(PDO::FETCH_COLUMN);
    $report['imported_table_count'] = count($tableNames);
    $report['last_table_names'] = array_slice($tableNames, -4);
    foreach (['users', 'sites', 'it_tickets', 'it_teams', 'devices', 'monitors', 'device_event_signal_outbox', 'control_room_signals', 'monitoring_incident_evidence_snapshots'] as $table) {
        $report['counts'][$table] = in_array($table, $tableNames, true) ? (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn() : null;
    }
    $report['latest_migrations'] = in_array('migrations', $tableNames, true) ? $pdo->query('SELECT migration FROM migrations ORDER BY id DESC LIMIT 4')->fetchAll(PDO::FETCH_COLUMN) : null;
    $report['source_permissions'] = in_array('permissions', $tableNames, true) ? $pdo->query("SELECT `key` FROM permissions WHERE `key` IN ('securityDevices.devices.view', 'controlRoom.alerts.view') ORDER BY `key`")->fetchAll(PDO::FETCH_COLUMN) : null;
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['token' => $token, 'error_class' => $exception::class,
        'driver_code' => $exception instanceof PDOException ? ($exception->errorInfo[1] ?? null) : null,
        'mutations_performed' => false]).PHP_EOL;
    exit(1);
}
