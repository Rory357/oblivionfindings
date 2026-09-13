<?php

/** Bounded read-only reply/file evidence for an explicitly reviewed synthetic browser token. No application bootstrap. */
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
    $ticket = $read('SELECT id, lock_version, status, next_response_party, last_public_comment_id, last_public_speaker_side, last_public_commented_at FROM it_tickets WHERE id = ? LIMIT 1', [1]);
    if (count($ticket['rows']) !== 1) {
        throw new RuntimeException('Expected synthetic ticket absent.');
    }
    $timeContext = $read('SELECT @@session.time_zone AS session_time_zone, @@system_time_zone AS system_time_zone');
    $comments = $read('SELECT id, ticket_id, author_user_id, is_internal, speaker_side, source_channel, SHA2(body, 256) AS body_sha256, created_at, UNIX_TIMESTAMP(created_at) AS created_at_epoch FROM it_ticket_comments WHERE ticket_id = ? ORDER BY id LIMIT 1000', [1]);
    // Reply namespace only. A committed reply can legitimately have no stored state key.
    $receipts = $read("SELECT id, actor_user_id, channel, operation, request_uuid, it_ticket_id, it_ticket_comment_id, committed_ticket_version, committed_at, UNIX_TIMESTAMP(committed_at) AS committed_at_epoch, JSON_UNQUOTE(JSON_EXTRACT(result_metadata, '$.state')) AS stored_state, JSON_UNQUOTE(JSON_EXTRACT(result_metadata, '$.draft.draft_uuid')) AS consumed_draft_uuid, JSON_EXTRACT(result_metadata, '$.draft.submitted_revision') AS submitted_draft_revision, JSON_EXTRACT(result_metadata, '$.draft.revision') AS consumed_draft_revision FROM it_ticket_command_receipts WHERE it_ticket_id = ? AND channel = ? AND operation = ? ORDER BY id LIMIT 1000", [1, 'browser', 'ticket.comment']);
    // Morph discriminators match AppServiceProvider; draft uses its canonical unmapped class.
    // Only ticket 1, its own comments and its own draft slots can contribute a file row.
    $attachments = $read('SELECT a.id, a.attachable_type, a.attachable_id, a.uploaded_by, a.mime, a.size AS recorded_size_bytes, SHA2(a.original_name, 256) AS original_name_sha256, a.draft_storage_state, a.draft_cleanup_attempts, a.draft_cleanup_error_code, a.created_at, UNIX_TIMESTAMP(a.created_at) AS created_at_epoch, i.id AS storage_intent_id, i.state AS storage_intent_state, i.revision AS storage_intent_revision, i.cleanup_attempts AS storage_intent_cleanup_attempts, i.cleanup_error_code AS storage_intent_cleanup_error_code FROM it_attachments a LEFT JOIN it_attachment_storage_intents i ON i.attachment_id = a.id WHERE (a.attachable_type = ? AND a.attachable_id = ?) OR (a.attachable_type = ? AND EXISTS (SELECT 1 FROM it_ticket_comments c WHERE c.id = a.attachable_id AND c.ticket_id = ?)) OR (a.attachable_type = ? AND EXISTS (SELECT 1 FROM it_ticket_drafts d WHERE d.id = a.attachable_id AND d.it_ticket_id = ?)) ORDER BY a.id LIMIT 1000', ['it_ticket', 1, 'it_ticket_comment', 1, 'App\\Models\\ItTicketDraft', 1]);
    $outbox = $read('SELECT d.id, d.retry_of_delivery_id, d.it_ticket_comment_id, d.notification_type, d.audience, d.status, d.attempt_count, d.retry_count, NOT EXISTS (SELECT 1 FROM it_email_deliveries newer WHERE newer.retry_of_delivery_id = d.id) AS is_current_attempt FROM it_email_deliveries d WHERE d.it_ticket_id = ? AND d.notification_type = ? ORDER BY d.id LIMIT 1000', [1, 'ticket_replied']);
    $counts = [];
    $currentCounts = [];
    foreach ($outbox['rows'] as $delivery) {
        $comment = $delivery['it_ticket_comment_id'] === null ? 'no_comment' : (string) $delivery['it_ticket_comment_id'];
        $status = (string) $delivery['status'];
        $counts[$comment][$status] = ($counts[$comment][$status] ?? 0) + 1;
        if ((int) $delivery['is_current_attempt'] === 1) {
            $currentCounts[$comment][$status] = ($currentCounts[$comment][$status] ?? 0) + 1;
        }
    }
    echo json_encode(['observed_at' => gmdate(DATE_ATOM), 'token' => $token, 'database' => $database,
        'time_context' => $timeContext, 'ticket' => $ticket, 'comments' => $comments, 'reply_receipts' => $receipts,
        'attachments' => $attachments, 'reply_outbox' => $outbox,
        'reply_outbox_counts_by_comment_and_status' => (object) $counts,
        'current_reply_attempt_counts_by_comment_and_status' => (object) $currentCounts,
        'sequential_read_only_observations' => true, 'database_mutations_performed' => false,
        'raw_private_values_emitted' => false, 'file_bytes_read' => false, 'storage_paths_emitted' => false,
        'size_evidence' => 'canonical database metadata; physical bytes not inspected',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['evidence_read_complete' => false, 'exception_class' => $exception::class,
        'private_message_suppressed' => true, 'database_mutations_performed' => false], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
