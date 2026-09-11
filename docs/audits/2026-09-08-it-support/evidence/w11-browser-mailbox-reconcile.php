<?php

// Read-only evidence for an existing, explicitly owned disposable browser schema.
$token = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || count($argv) !== 2 || !preg_match('/^[a-f0-9]{16}$/D', $token)) {
    exit(2);
}
$repo = str_replace('\\', '/', dirname(__DIR__, 4));
$root = $repo.'/storage/framework/testing/it-draft-browser-'.$token;
$database = 'oblivion_it_draft_browser_'.$token;
$owner = json_decode(file_get_contents($root.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
if ($owner['token'] !== $token || $owner['database'] !== $database || !($owner['mailbox_fixtures'] ?? false)
    || str_replace('\\', '/', $owner['root']) !== $root || is_link($root) || !is_file($root.'/ready.json')) {
    exit(3);
}
$xml = simplexml_load_file($repo.'/phpunit.xml');
$access = [];
foreach ($xml->php->env as $entry) {
    if (in_array((string)$entry['name'], ['DB_USERNAME', 'DB_PASSWORD'], true)) {
        $access[(string)$entry['name']] = (string)$entry['value'];
    }
}
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database, $access['DB_USERNAME'], $access['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        exit(4);
    }
    $provider = json_decode(file_get_contents($root.'/mailbox-provider-state.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($provider['token'] !== $token || ! $provider['synthetic_only']) {
        exit(5);
    }
    $files = $pdo->query('SELECT id, attachable_type, attachable_id, path, size, inbound_storage_state, inbound_content_hash, malware_scan_status, malware_scanner, inbound_cleanup_attempts, source_inbound_attachment_id FROM it_attachments ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($files as &$file) {
        if (!preg_match('~^it_attachments/[a-f0-9-]{36}$~D', $file['path'])) {
            throw new RuntimeException('Unexpected private fixture path.');
        }
        $diskPath = $root.'/storage/app/private/'.$file['path'];
        if (is_link($diskPath)) {
            throw new RuntimeException('Linked fixture object refused.');
        }
        $file['object_exists'] = is_file($diskPath);
        $file['object_hash_matches'] = $file['object_exists'] ? hash_file('sha256', $diskPath) === $file['inbound_content_hash'] : null;
        unset($file['path']);
    }
    unset($file);
    $scannerPath = $root.'/mailbox-scanner-state.json';
    $scanner = is_file($scannerPath) ? json_decode(file_get_contents($scannerPath), true, flags: JSON_THROW_ON_ERROR) : null;
    if ($scanner !== null && ($scanner['token'] !== $token || $scanner['synthetic_only'] !== true)) {
        throw new RuntimeException('Scanner fixture identity differs.');
    }
    echo json_encode([
        'token' => $token, 'database' => $database, 'read_only' => true, 'synthetic_provider_only' => true,
        'captured_at' => gmdate(DATE_ATOM),
        'connections' => $pdo->query('SELECT id, provider, status, configuration_version, last_polled_at, last_poll_attempt_at, next_poll_at FROM it_mailbox_connections ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'inbound' => $pdo->query('SELECT it_mailbox_connection_id, status, COUNT(*) AS records, COUNT(DISTINCT it_ticket_id) AS distinct_tickets, SUM(acknowledged_at IS NOT NULL) AS acknowledged, SUM(processing_attempts) AS processing_attempts, SUM(acknowledgement_attempts) AS acknowledgement_attempts FROM it_inbound_emails GROUP BY it_mailbox_connection_id, status')->fetchAll(PDO::FETCH_ASSOC),
        'synthetic_ticket_count' => (int)$pdo->query("SELECT COUNT(*) FROM it_tickets WHERE title LIKE 'W11 synthetic %'")->fetchColumn(),
        'canonical_commands' => $pdo->query("SELECT operation, COUNT(*) AS records, SUM(committed_at IS NOT NULL) AS committed FROM it_ticket_command_receipts WHERE channel = 'email' GROUP BY operation")->fetchAll(PDO::FETCH_ASSOC),
        'ticket_conversation' => $pdo->query("SELECT t.id, t.reference, t.title, t.source, t.lock_version, t.next_response_party, COUNT(c.id) AS comments, SUM(c.source_channel = 'email') AS email_comments FROM it_tickets t LEFT JOIN it_ticket_comments c ON c.ticket_id = t.id WHERE t.title LIKE 'W11 synthetic %' GROUP BY t.id, t.reference, t.title, t.source, t.lock_version, t.next_response_party ORDER BY t.id")->fetchAll(PDO::FETCH_ASSOC),
        'quarantine_reasons' => $pdo->query("SELECT it_mailbox_connection_id, quarantine_reason, COUNT(*) AS records, SUM(body_preview IS NOT NULL) AS retained_body_previews FROM it_inbound_emails WHERE status = 'quarantined' GROUP BY it_mailbox_connection_id, quarantine_reason")->fetchAll(PDO::FETCH_ASSOC),
        'quarantine_reviews' => $pdo->query('SELECT id, it_mailbox_connection_id, status, quarantine_reason, quarantine_review_version, quarantine_retry_requested_at, acknowledged_at FROM it_inbound_emails WHERE quarantine_review_version > 0 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'quarantine_review_audit' => $pdo->query("SELECT action, auditable_id, user_id, meta FROM audit_logs WHERE action IN ('settings.it_mailbox.quarantine_retry_requested', 'it.inbound_email.quarantine_retry_completed') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        'canonical_email_audit' => $pdo->query("SELECT a.action, COUNT(*) AS records FROM audit_logs a JOIN it_tickets t ON t.id = a.auditable_id AND a.auditable_type = 'it_ticket' WHERE t.title LIKE 'W11 synthetic %' AND a.action IN ('it.ticket.created', 'it.ticket.comment.added') GROUP BY a.action")->fetchAll(PDO::FETCH_ASSOC),
        'provider_state' => $provider,
        'attachment_records' => $files,
        'scanner_fixture_state' => $scanner,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Read-only reconciliation failed; no raw exception or credentials emitted.\n");
    exit(1);
}

