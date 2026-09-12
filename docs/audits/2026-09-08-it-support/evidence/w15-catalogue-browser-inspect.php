<?php

// Read-only catalogue reconciliation inside the exact owned disposable schema.
$token = $argv[1] ?? '';
$fingerprint = $argv[2] ?? '';
$holdCreateActor = ($argv[3] ?? '') === 'hold-create-actor';
if (PHP_SAPI !== 'cli' || (count($argv) !== 3 && ! (count($argv) === 4 && $holdCreateActor))
    || ! preg_match('/\A[a-f0-9]{16}\z/', $token)
    || ! preg_match('/\A[a-f0-9]{64}\z/', $fingerprint)) {
    exit(2);
}
$repo = realpath(__DIR__.'/../../../..');
$root = $repo.'/storage/framework/testing/it-draft-browser-'.$token;
$database = 'oblivion_it_draft_browser_'.$token;
$owner = json_decode(file_get_contents($root.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
$ready = json_decode(file_get_contents($root.'/ready.json'), true, flags: JSON_THROW_ON_ERROR);
if (($owner['token'] ?? null) !== $token || ($owner['database'] ?? null) !== $database
    || ($owner['approval_fingerprint'] ?? null) !== $fingerprint
    || ($ready['database'] ?? null) !== $database || ! ($ready['ready'] ?? false)
    || ! ($owner['catalogue_fixtures'] ?? false)
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
    if ($holdCreateActor) {
        $actorId = $ready['fixtures']['actors']['tech']['id'] ?? null;
        $login = $ready['fixtures']['actors']['tech']['login'] ?? null;
        if (! is_int($actorId) || $login !== 'w06-tech@demo.test' || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new RuntimeException('Synthetic actor mismatch');
        }
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM users WHERE id = ? AND email = ? FOR UPDATE');
        $lock->execute([$actorId, $login]);
        if ((int) $lock->fetchColumn() !== $actorId) {
            throw new RuntimeException('Synthetic actor missing');
        }
        echo json_encode(['token' => $token, 'actor_id' => $actorId, 'lock_held' => true, 'maximum_seconds' => 25, 'mutations_performed' => false]).PHP_EOL;
        flush();
        sleep(25);
        $pdo->rollBack();
        echo json_encode(['token' => $token, 'lock_released' => true, 'mutations_performed' => false]).PHP_EOL;
        exit(0);
    }
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        throw new RuntimeException('Scope mismatch');
    }
    $report = ['token' => $token, 'mutations_performed' => false];
    $queries = [
        'items' => 'SELECT id, name, outcome_type, provisioning_type, requires_approval, internal_only, site_scope, is_published, form_schema_version, published_version_id, lock_version FROM it_catalog_items ORDER BY id',
        'create_receipts' => "SELECT id, actor_user_id, resource, request_uuid, it_catalog_item_id, committed_at, cancelled_at FROM it_setup_command_receipts WHERE resource = 'catalogue-items' ORDER BY id",
        'template_create_receipts' => "SELECT id, actor_user_id, resource, request_uuid, it_provisioning_template_id, committed_configuration_version, committed_at, cancelled_at FROM it_setup_command_receipts WHERE resource = 'provisioning-templates' ORDER BY id",
        'versions' => 'SELECT id, catalog_item_id, version, provenance, published_by, contract FROM it_catalog_versions ORDER BY id',
        'submissions' => 'SELECT id, catalog_item_id, requester_user_id, schema_version, catalog_version_id, contract_snapshot, input_sha256, result_type, result_id FROM it_catalog_submissions ORDER BY id',
        'provisioning' => 'SELECT id, employee_profile_id, created_by, type, status, approval_required, approval_status, approved_by_user_id, fulfilled_by, evidence_summary FROM it_provisioning_requests ORDER BY id',
        'templates' => 'SELECT id, name, site_id, lock_version, current_version_id FROM it_provisioning_templates ORDER BY id',
        'template_versions' => 'SELECT id, provisioning_template_id, version, provenance, contract FROM it_provisioning_template_versions ORDER BY id',
        'workflows' => 'SELECT id, provisioning_template_id, template_version_id, employee_profile_id, status FROM it_provisioning_workflows ORDER BY id',
        'workflow_instructions' => 'SELECT id, provisioning_workflow_id, task_key, item, notes, approval_required, evidence_required FROM it_provisioning_requests WHERE provisioning_workflow_id IS NOT NULL ORDER BY id',
        'catalogue_tickets' => "SELECT t.id, t.reference, t.site_id, t.requester_user_id, t.requested_for_user_id, t.status FROM it_tickets t WHERE EXISTS (SELECT 1 FROM it_catalog_submissions s WHERE s.result_id = t.id AND (s.result_type LIKE '%ItTicket' OR s.result_type = 'it_ticket')) ORDER BY t.id",
        'events' => "SELECT id, subject_type, subject_id, actor_user_id, type, payload FROM it_ticket_events WHERE subject_type LIKE '%Provisioning%' OR subject_type = 'it_provisioning_request' ORDER BY id",
    ];
    foreach ($queries as $key => $sql) {
        $report[$key] = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    $pdo->rollBack();
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['token' => $token, 'error_class' => $exception::class, 'mutations_performed' => false]).PHP_EOL;
    exit(1);
}
