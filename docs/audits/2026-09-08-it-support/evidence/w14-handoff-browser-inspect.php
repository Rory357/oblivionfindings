<?php

// Read-only reconciliation, or a bounded row lock, in an explicitly owned synthetic run.
$token = $argv[1] ?? '';
$fingerprint = $argv[2] ?? '';
$holdCase = $argv[3] ?? null;
if (PHP_SAPI !== 'cli' || ! preg_match('/\A[a-f0-9]{16}\z/', $token)
    || ! preg_match('/\A[a-f0-9]{64}\z/', $fingerprint)
    || count($argv) > 4 || ($holdCase !== null && ! in_array($holdCase, ['cancel', 'stale'], true))) {
    exit(2);
}
$repo = realpath(__DIR__.'/../../../..');
$root = $repo.'/storage/framework/testing/it-draft-browser-'.$token;
$database = 'oblivion_it_draft_browser_'.$token;
$owner = json_decode(file_get_contents($root.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
$ready = json_decode(file_get_contents($root.'/ready.json'), true, flags: JSON_THROW_ON_ERROR);
if (($owner['token'] ?? null) !== $token || ($owner['database'] ?? null) !== $database
    || ($owner['approval_fingerprint'] ?? null) !== $fingerprint || ($ready['database'] ?? null) !== $database
    || ! ($ready['ready'] ?? false) || ! ($owner['monitoring_fixtures'] ?? false)
    || str_replace('\\', '/', (string) realpath($root)) !== str_replace('\\', '/', $root)) {
    exit(3);
}
$access = [];
foreach (simplexml_load_file($repo.'/phpunit.xml')->php->env as $setting) {
    if (in_array((string) $setting['name'], ['DB_USERNAME', 'DB_PASSWORD'], true)) {
        $access[(string) $setting['name']] = (string) $setting['value'];
    }
}
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database, $access['DB_USERNAME'], $access['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($access);
    if ($holdCase !== null) {
        $alertId = $ready['monitoring_fixtures']['handoffs'][$holdCase]['alert_id'] ?? null;
        if (! is_int($alertId) || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new RuntimeException('Scope mismatch');
        }
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM control_room_alerts WHERE id = ? FOR UPDATE');
        $lock->execute([$alertId]);
        if ((int) $lock->fetchColumn() !== $alertId) {
            throw new RuntimeException('Fixture missing');
        }
        echo json_encode(['token' => $token, 'alert_id' => $alertId, 'lock_held' => true, 'maximum_hold_seconds' => 25, 'record_mutations' => false]).PHP_EOL;
        flush();
        sleep(25);
        $pdo->rollBack();
        echo json_encode(['token' => $token, 'lock_released' => true, 'record_mutations' => false]).PHP_EOL;
        exit(0);
    }
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        throw new RuntimeException('Scope mismatch');
    }
    $report = ['token' => $token, 'mutations_performed' => false, 'cases' => []];
    $alert = $pdo->prepare('SELECT id, site_id, status FROM control_room_alerts WHERE id = ?');
    $links = $pdo->prepare("SELECT ticket_id, created_by_user_id FROM it_ticket_links WHERE relationship = 'source_alert' AND linkable_type = ? AND linkable_id = ?");
    $receipts = $pdo->prepare("SELECT actor_user_id, request_uuid, it_ticket_id, committed_ticket_version, result_metadata FROM it_ticket_command_receipts WHERE operation = 'ticket.control_room_handoff' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(result_metadata, '$.alert_id')) AS UNSIGNED) = ? ORDER BY id");
    foreach ($ready['monitoring_fixtures']['handoffs'] as $case => $fixture) {
        $alert->execute([$fixture['alert_id']]);
        $links->execute(['control_room_alert', $fixture['alert_id']]);
        $receipts->execute([$fixture['alert_id']]);
        $report['cases'][$case] = ['alert' => $alert->fetch(PDO::FETCH_ASSOC),
            'links' => $links->fetchAll(PDO::FETCH_ASSOC), 'receipts' => $receipts->fetchAll(PDO::FETCH_ASSOC)];
    }
    $report['ticket_count'] = (int) $pdo->query('SELECT COUNT(*) FROM it_tickets')->fetchColumn();
    $pdo->rollBack();
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['token' => $token, 'error_class' => $exception::class, 'mutations_performed' => false]).PHP_EOL;
    exit(1);
}
