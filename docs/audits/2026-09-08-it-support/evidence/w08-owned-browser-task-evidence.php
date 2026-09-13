<?php

/** Bounded read-only task/lifecycle evidence for an explicitly reviewed synthetic browser token. No application bootstrap. */
try {
    $checkout = realpath(__DIR__.'/../../../..');
    $token = $argv[1] ?? '';
    $fingerprint = $argv[2] ?? '';
    if (PHP_SAPI !== 'cli' || count($argv) !== 3
        || preg_match('/^[a-f0-9]{16}$/D', $token) !== 1
        || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
        || strcasecmp(str_replace('\\', '/', (string) $checkout), 'C:/Users/steph/Herd/oblivionfindings') !== 0) {
        throw new RuntimeException('Explicit local evidence boundary unavailable.');
    }
    $database = 'oblivion_it_draft_browser_'.$token;
    $root = $checkout.'/storage/framework/testing/it-draft-browser-'.$token;
    foreach ([$root, $root.'/owner.json', $root.'/ready.json'] as $path) {
        if (! file_exists($path) || is_link($path)
            || strcasecmp(str_replace('\\', '/', (string) realpath($path)), str_replace('\\', '/', $path)) !== 0) {
            throw new RuntimeException('Exact owned browser path unavailable.');
        }
    }
    $owner = json_decode(file_get_contents($root.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
    $ready = json_decode(file_get_contents($root.'/ready.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($owner['token'] ?? null) !== $token || ($owner['database'] ?? null) !== $database
        || ($owner['approval_fingerprint'] ?? null) !== $fingerprint
        || strcasecmp(str_replace('\\', '/', $owner['root'] ?? ''), str_replace('\\', '/', $root)) !== 0
        || ($ready['database'] ?? null) !== $database || ($ready['ready'] ?? null) !== true
        || ($owner['synthetic_retention_only'] ?? null) !== true
        || ($owner['asset_manifest_sha256'] ?? null) !== hash_file('sha256', $checkout.'/public/build/manifest.json')) {
        throw new RuntimeException('Reviewed owner, assets or readiness identity differs.');
    }
    $configuration = simplexml_load_file($checkout.'/phpunit.xml');
    $access = [];
    foreach ($configuration->php->env as $setting) {
        $name = (string) $setting['name'];
        if (in_array($name, ['DB_USERNAME', 'DB_PASSWORD'], true)) {
            $access[$name] = (string) $setting['value'];
        }
    }
    if (! isset($access['DB_USERNAME'], $access['DB_PASSWORD'])) {
        throw new RuntimeException('Existing local test access unavailable.');
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database, $access['DB_USERNAME'], $access['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        throw new RuntimeException('Connected schema differs from the reviewed identity.');
    }
    $read = function (string $sql, array $parameters = []) use ($pdo): array {
        $started = microtime(true);
        $query = $pdo->prepare($sql);
        $query->execute($parameters);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) >= 1000) {
            throw new RuntimeException('Bounded evidence row limit reached.');
        }

        return ['elapsed_ms' => round((microtime(true) - $started) * 1000, 2), 'rows' => $rows];
    };
    $tickets = $read('SELECT id, lock_version, status FROM it_tickets WHERE id IN (1, 4) ORDER BY id LIMIT 1000');
    if (count($tickets['rows']) !== 2) {
        throw new RuntimeException('Expected synthetic tickets absent.');
    }
    $timeContext = $read('SELECT @@session.time_zone AS session_time_zone, @@system_time_zone AS system_time_zone');
    $tasks = $read('SELECT id, ticket_id, team_id, assigned_to_user_id, status, is_required, evidence_required, due_at, UNIX_TIMESTAMP(due_at) AS due_at_epoch, sort_order, completed_by_user_id, completed_at, UNIX_TIMESTAMP(completed_at) AS completed_at_epoch, SHA2(title, 256) AS title_sha256, SHA2(description, 256) AS description_sha256, SHA2(completion_note, 256) AS completion_note_sha256, SHA2(CAST(evidence AS CHAR), 256) AS evidence_sha256, JSON_LENGTH(evidence) AS evidence_count FROM it_work_tasks WHERE ticket_id IN (1, 4) ORDER BY ticket_id, sort_order, id LIMIT 1000');
    $dependencies = $read('SELECT d.task_id, d.depends_on_task_id FROM it_work_task_dependencies d INNER JOIN it_work_tasks t ON t.id = d.task_id WHERE t.ticket_id IN (1, 4) ORDER BY d.task_id, d.depends_on_task_id LIMIT 1000');
    $receipts = $read("SELECT id, actor_user_id, operation, request_uuid, it_ticket_id, committed_ticket_version, committed_at, JSON_UNQUOTE(JSON_EXTRACT(result_metadata, '$.state')) AS result_state, JSON_EXTRACT(result_metadata, '$.task_id') AS task_id FROM it_ticket_command_receipts WHERE it_ticket_id IN (1, 4) AND operation LIKE 'task.%' ORDER BY id LIMIT 1000");
    $events = $read("SELECT id, subject_id, actor_user_id, type, created_at, JSON_EXTRACT(payload, '$.task_id') AS task_id, SHA2(CAST(payload AS CHAR), 256) AS payload_sha256 FROM it_ticket_events WHERE subject_type = ? AND subject_id IN (1, 4) AND type LIKE 'work_task_%' ORDER BY id LIMIT 1000", ['it_ticket']);
    $audits = $read("SELECT id, user_id, auditable_id, action, created_at, SHA2(CAST(meta AS CHAR), 256) AS meta_sha256 FROM audit_logs WHERE auditable_type = ? AND auditable_id IN (1, 4) AND (action LIKE 'it.ticket.task.%' OR action = 'it.ticket.tasks.reordered') ORDER BY id LIMIT 1000", ['it_ticket']);
    $approvalEvidence = null;
    $approvalTicketId = $ready['fixtures']['tickets']['approval_workspace']['id'] ?? null;
    if ($approvalTicketId !== null) {
        if (! is_int($approvalTicketId) || $approvalTicketId < 1) {
            throw new RuntimeException('Synthetic approval fixture identity differs.');
        }
        $approvalEvidence = [
            'ticket' => $read('SELECT id, lock_version, status FROM it_tickets WHERE id = ? LIMIT 1', [$approvalTicketId]),
            'requests' => $read('SELECT id, it_ticket_id, status, requested_by, primary_approver_user_id, cover_approver_user_id, approver_id, expires_at, remind_at, decided_at, expired_at, cancelled_at, cancelled_by_user_id, SHA2(request_reason, 256) AS request_reason_sha256, SHA2(decision_reason, 256) AS decision_reason_sha256, SHA2(cancellation_reason, 256) AS cancellation_reason_sha256 FROM it_ticket_approvals WHERE it_ticket_id = ? ORDER BY id LIMIT 1000', [$approvalTicketId]),
            'receipts' => $read("SELECT id, actor_user_id, operation, request_uuid, it_ticket_id, committed_ticket_version, committed_at FROM it_ticket_command_receipts WHERE it_ticket_id = ? AND operation LIKE 'approval.%' ORDER BY id LIMIT 1000", [$approvalTicketId]),
            'events' => $read("SELECT id, actor_user_id, type, created_at FROM it_ticket_events WHERE subject_type = ? AND subject_id = ? AND type LIKE 'approval_%' ORDER BY id LIMIT 1000", ['it_ticket', $approvalTicketId]),
            'audits' => $read("SELECT id, user_id, action, created_at FROM audit_logs WHERE auditable_type = ? AND auditable_id = ? AND action LIKE 'it.ticket.approval.%' ORDER BY id LIMIT 1000", ['it_ticket', $approvalTicketId]),
        ];
    }
    $resolutionEvidence = null;
    $resolutionTicketId = $ready['fixtures']['tickets']['resolve_workspace']['id'] ?? null;
    if ($resolutionTicketId !== null) {
        if (! is_int($resolutionTicketId) || $resolutionTicketId < 1) {
            throw new RuntimeException('Synthetic resolution fixture identity differs.');
        }
        $resolutionEvidence = [
            'ticket' => $read('SELECT id, lock_version, status, workflow_state, resolved_at, closed_at, reopened_count, resolution_code, SHA2(resolution_summary, 256) AS resolution_summary_sha256, SHA2(resolution_verification, 256) AS resolution_verification_sha256, csat_score, csat_submitted_at, SHA2(csat_comment, 256) AS csat_comment_sha256 FROM it_tickets WHERE id = ? LIMIT 1', [$resolutionTicketId]),
            'comments' => $read('SELECT id, author_user_id, is_internal, speaker_side, source_channel, created_at, SHA2(body, 256) AS body_sha256 FROM it_ticket_comments WHERE ticket_id = ? ORDER BY id LIMIT 1000', [$resolutionTicketId]),
            'conversation' => $read('SELECT id, last_public_comment_id, last_public_speaker_side, next_response_party FROM it_tickets WHERE id = ? LIMIT 1', [$resolutionTicketId]),
            'events' => $read("SELECT id, actor_user_id, type, created_at, JSON_UNQUOTE(JSON_EXTRACT(payload, '$.via')) AS source FROM it_ticket_events WHERE subject_type = ? AND subject_id = ? AND type IN ('resolved', 'reopened', 'resolution_confirmed', 'closed', 'csat_submitted', 'csat_updated') ORDER BY id LIMIT 1000", ['it_ticket', $resolutionTicketId]),
            'audits' => $read("SELECT id, user_id, action, created_at, JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source')) AS source FROM audit_logs WHERE auditable_type = ? AND auditable_id = ? AND action IN ('it.ticket.resolved', 'it.ticket.reopened', 'it.ticket.csat.submitted', 'it.ticket.csat.updated', 'it.ticket.auto_closed', 'it.work.transitioned') ORDER BY id LIMIT 1000", ['it_ticket', $resolutionTicketId]),
        ];
    }
    echo json_encode(['observed_at' => gmdate(DATE_ATOM), 'token' => $token, 'database' => $database,
        'time_context' => $timeContext, 'tickets' => $tickets, 'tasks' => $tasks, 'dependencies' => $dependencies, 'receipts' => $receipts,
        'events' => $events, 'audits' => $audits, 'approvals' => $approvalEvidence, 'resolution' => $resolutionEvidence,
        'closure' => [
            'events' => $read("SELECT id, subject_id AS ticket_id, actor_user_id, type, SHA2(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.reason')), 256) AS reason_sha256, JSON_UNQUOTE(JSON_EXTRACT(payload, '$.via')) AS source FROM it_ticket_events WHERE subject_type = 'it_ticket' AND subject_id IN (1, 4) AND type = 'closed' ORDER BY id LIMIT 1000"),
            'audits' => $read("SELECT id, auditable_id AS ticket_id, user_id, action, JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source')) AS source FROM audit_logs WHERE auditable_type = 'it_ticket' AND auditable_id IN (1, 4) AND action = 'it.ticket.closed' ORDER BY id LIMIT 1000"),
        ],
        'merge' => [
            'tickets' => $read('SELECT id, reference, lock_version, status, workflow_state, merged_into_ticket_id, merged_at FROM it_tickets WHERE id IN (1, 4, 5, 6) ORDER BY id LIMIT 1000'),
            'approvals' => $read('SELECT id, it_ticket_id, status, SHA2(request_reason, 256) AS request_reason_sha256, SHA2(decision_reason, 256) AS decision_reason_sha256 FROM it_ticket_approvals WHERE it_ticket_id IN (1, 4, 5, 6) ORDER BY id LIMIT 1000'),
            'comments' => $read('SELECT id, ticket_id, author_user_id, is_internal, SHA2(body, 256) AS body_sha256 FROM it_ticket_comments WHERE ticket_id IN (1, 4, 5, 6) ORDER BY id LIMIT 1000'),
            'events' => $read("SELECT id, subject_id AS ticket_id, actor_user_id, JSON_UNQUOTE(JSON_EXTRACT(payload, '$.direction')) AS direction, JSON_EXTRACT(payload, '$.source_id') AS source_id, JSON_EXTRACT(payload, '$.target_id') AS target_id, SHA2(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.reason')), 256) AS reason_sha256 FROM it_ticket_events WHERE subject_type = 'it_ticket' AND subject_id IN (1, 4, 5, 6) AND type = 'merged' ORDER BY id LIMIT 1000"),
            'audits' => $read("SELECT id, auditable_id AS ticket_id, user_id, JSON_EXTRACT(meta, '$.target_ticket_id') AS target_ticket_id FROM audit_logs WHERE auditable_type = 'it_ticket' AND auditable_id IN (1, 4, 5, 6) AND action = 'it.ticket.merged' ORDER BY id LIMIT 1000"),
        ],
        'relationships' => [
            'tickets' => $read('SELECT id, lock_version, status, merged_into_ticket_id FROM it_tickets WHERE id IN (1, 4, 6, 7) ORDER BY id LIMIT 1000'),
            'links' => $read("SELECT id, ticket_id, linkable_type, linkable_id, relationship FROM it_ticket_links WHERE ticket_id IN (1, 4, 6, 7) AND relationship IN ('related_ticket', 'duplicate_ticket') ORDER BY ticket_id, linkable_id LIMIT 1000"),
            'receipts' => $read("SELECT id, actor_user_id, request_uuid, it_ticket_id, committed_ticket_version, JSON_UNQUOTE(JSON_EXTRACT(result_metadata, '$.state')) AS state, JSON_UNQUOTE(JSON_EXTRACT(result_metadata, '$.action')) AS action, JSON_EXTRACT(result_metadata, '$.target_id') AS target_id FROM it_ticket_command_receipts WHERE operation = 'ticket.relationship' AND it_ticket_id IN (1, 4, 6, 7) ORDER BY id LIMIT 1000"),
            'events' => $read("SELECT id, subject_id AS ticket_id, actor_user_id, type FROM it_ticket_events WHERE subject_type = 'it_ticket' AND subject_id IN (1, 4, 6, 7) AND type IN ('related_work_linked', 'related_work_unlinked') ORDER BY id LIMIT 1000"),
            'audits' => $read("SELECT id, auditable_id AS ticket_id, user_id, action FROM audit_logs WHERE auditable_type = 'it_ticket' AND auditable_id IN (1, 4, 6, 7) AND action LIKE 'it.ticket.relationship.%' ORDER BY id LIMIT 1000"),
        ],
        'sso' => [
            'settings' => $read("SELECT `key`, JSON_EXTRACT(value, '$.version') AS version, JSON_EXTRACT(value, '$.staff_enabled') AS staff_enabled, JSON_EXTRACT(value, '$.portal_enabled') AS portal_enabled, JSON_EXTRACT(value, '$.auto_create_staff') AS auto_create_staff, JSON_EXTRACT(value, '$.auto_link_existing') AS auto_link_existing, JSON_EXTRACT(value, '$.portal_auto_create') AS portal_auto_create, JSON_CONTAINS_PATH(value, 'one', '$.secret_ciphertext') AS stored_secret_present FROM app_settings WHERE `key` IN ('settings.sso.microsoft', 'settings.sso.google', 'settings.sso.provisioning') ORDER BY `key` LIMIT 1000"),
            'mappings' => $read('SELECT id, provider, role_id, auto_assign, auto_remove FROM sso_group_mappings ORDER BY id LIMIT 1000'),
            'audits' => $read("SELECT id, user_id, action, auditable_id, JSON_UNQUOTE(JSON_EXTRACT(meta, '$.section')) AS section, JSON_EXTRACT(meta, '$.version') AS version, JSON_UNQUOTE(JSON_EXTRACT(meta, '$.secret_action')) AS secret_action FROM audit_logs WHERE action LIKE 'settings.sso.%' ORDER BY id LIMIT 1000"),
        ],
        'sequential_read_only_observations' => true,
        'database_mutations_performed' => false, 'raw_private_values_emitted' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['evidence_read_complete' => false, 'exception_class' => $exception::class,
        'private_message_suppressed' => true, 'database_mutations_performed' => false], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}

