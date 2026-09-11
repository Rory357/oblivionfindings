<?php

// Read-only outcome reconciliation, or a 25-second lock, for this owned synthetic fixture.
$token = $argv[1] ?? '';
$fingerprint = $argv[2] ?? '';
$holdCase = $argv[3] ?? null;
if (PHP_SAPI !== 'cli' || ! preg_match('/\A[a-f0-9]{16}\z/', $token)
    || ! preg_match('/\A[a-f0-9]{64}\z/', $fingerprint)
    || count($argv) > 4 || ($holdCase !== null && $holdCase !== 'retry_access')) {
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
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        throw new RuntimeException('Scope mismatch');
    }
    if ($holdCase !== null) {
        $id = $ready['monitoring_fixtures']['retry_cases'][$holdCase]['outbox_id'] ?? null;
        if (! is_int($id)) {
            throw new RuntimeException('Owned retry fixture missing');
        }
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM device_event_signal_outbox WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        if ((int) $lock->fetchColumn() !== $id) {
            throw new RuntimeException('Owned retry delivery missing');
        }
        echo json_encode(['token' => $token, 'outbox_id' => $id, 'lock_held' => true, 'maximum_hold_seconds' => 25, 'record_mutations' => false]).PHP_EOL;
        flush();
        sleep(25);
        $pdo->rollBack();
        echo json_encode(['token' => $token, 'lock_released' => true, 'record_mutations' => false]).PHP_EOL;
        exit(0);
    }
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $report = ['token' => $token, 'mutations_performed' => false, 'cases' => []];
    foreach ($ready['monitoring_fixtures']['retry_cases'] as $case => $fixture) {
        $device = $fixture['source'] === 'device';
        if (! $device && $fixture['source'] !== 'fleet') {
            throw new RuntimeException('Unknown fixture source');
        }
        $table = $device ? 'device_event_signal_outbox' : 'fleet_signal_outbox';
        $statement = $pdo->prepare('SELECT id,status,it_status,it_attempts,it_attempt_limit,it_outcome_code,it_ticket_ids,it_completed_at FROM '.$table.' WHERE id = ?');
        $statement->execute([$fixture['outbox_id']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $tickets = [];
        foreach (json_decode($row['it_ticket_ids'] ?? '[]', true, flags: JSON_THROW_ON_ERROR) ?? [] as $id) {
            $ticket = $pdo->prepare('SELECT id,reference,status,site_id,queue_id,team_id,assigned_to_user_id,owner_user_id FROM it_tickets WHERE id = ?');
            $ticket->execute([$id]);
            $tickets[] = $ticket->fetch(PDO::FETCH_ASSOC);
        }
        $audit = $pdo->prepare('SELECT action,user_id FROM audit_logs WHERE auditable_id = ? AND action IN (?,?) ORDER BY id');
        $prefix = $device ? 'it.monitoring' : 'it.fleet';
        $audit->execute([$fixture['outbox_id'], $prefix.'.delivery_retry_requested', $prefix.'.delivery_completed']);
        $report['cases'][$case] = ['source' => $fixture['source'], 'outcome' => $row, 'tickets' => $tickets, 'audits' => $audit->fetchAll(PDO::FETCH_ASSOC)];
    }
    $report['history_pending'] = [];
    $pending = $pdo->prepare('SELECT id,status,it_status,it_attempts,it_ticket_ids FROM device_event_signal_outbox WHERE id = ?');
    foreach ($ready['monitoring_fixtures']['history_pending_outbox_ids'] ?? [] as $id) {
        $pending->execute([$id]);
        $report['history_pending'][] = $pending->fetch(PDO::FETCH_ASSOC);
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
