<?php

/** Read-only opaque W07 evidence for ticket 1 in one explicitly reviewed synthetic browser schema. */
try {
    $checkout = realpath(__DIR__.'/../../../..');
    $token = 'f55d5818cbf5499b';
    $fingerprint = '4ebb2ccd42acb0fec221b4bc5daf00ea808f29d1fc273a7c6542590de8fdfb20';
    $database = 'oblivion_it_draft_browser_'.$token;
    $root = $checkout.'/storage/framework/testing/it-draft-browser-'.$token;
    if (PHP_SAPI !== 'cli' || count($argv) !== 1
        || strcasecmp(str_replace('\\', '/', (string) $checkout), 'C:/Users/steph/Herd/oblivionfindings') !== 0
        || ! is_dir($root) || is_link($root) || ! is_file($root.'/owner.json') || is_link($root.'/owner.json')
        || strcasecmp(str_replace('\\', '/', (string) realpath($root)), str_replace('\\', '/', $root)) !== 0) {
        throw new RuntimeException('Exact local browser boundary unavailable.');
    }
    $owner = json_decode(file_get_contents($root.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
    $ready = json_decode(file_get_contents($root.'/ready.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($owner['token'] ?? null) !== $token || ($owner['database'] ?? null) !== $database
        || ($owner['approval_fingerprint'] ?? null) !== $fingerprint
        || strcasecmp(str_replace('\\', '/', $owner['root'] ?? ''), str_replace('\\', '/', $root)) !== 0
        || ($ready['database'] ?? null) !== $database || ($ready['ready'] ?? null) !== true) {
        throw new RuntimeException('Reviewed owner or readiness identity differs.');
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
        throw new RuntimeException('Existing local test access source unavailable.');
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database, $access['DB_USERNAME'], $access['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        throw new RuntimeException('Connected schema differs from the reviewed identity.');
    }
    $read = function (string $sql) use ($pdo): array {
        $started = microtime(true);
        $query = $pdo->prepare($sql);
        $query->execute([1]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) >= 1000) {
            throw new RuntimeException('Bounded evidence row limit reached.');
        }

        return ['elapsed_ms' => round((microtime(true) - $started) * 1000, 2), 'rows' => $rows];
    };
    $ticket = $read('SELECT id, lock_version, status, next_response_party, last_public_comment_id, last_public_speaker_side, last_public_commented_at FROM it_tickets WHERE id = ? LIMIT 1');
    if (count($ticket['rows']) !== 1) {
        throw new RuntimeException('The exact synthetic ticket is absent.');
    }
    $comments = $read('SELECT id, ticket_id, author_user_id, is_internal, SHA2(body, 256) AS body_sha256, speaker_side, source_channel, created_at FROM it_ticket_comments WHERE ticket_id = ? ORDER BY id LIMIT 1000');
    $receipts = $read("SELECT id, actor_user_id, channel, operation, request_uuid, it_ticket_id, it_ticket_comment_id, committed_ticket_version, committed_at, JSON_UNQUOTE(JSON_EXTRACT(result_metadata, '$.state')) AS result_state, JSON_UNQUOTE(JSON_EXTRACT(result_metadata, '$.draft.draft_uuid')) AS draft_uuid, JSON_EXTRACT(result_metadata, '$.draft.submitted_revision') AS draft_submitted_revision, JSON_EXTRACT(result_metadata, '$.draft.revision') AS draft_revision, JSON_EXTRACT(result_metadata, '$.task_id') AS task_id FROM it_ticket_command_receipts WHERE it_ticket_id = ? ORDER BY id LIMIT 1000");
    $watchers = $read('SELECT ticket_id, user_id FROM it_ticket_watchers WHERE ticket_id = ? ORDER BY user_id LIMIT 1000');
    $outbox = $read('SELECT id, retry_of_delivery_id, it_ticket_comment_id, notification_type, audience, status, attempt_count, retry_count FROM it_email_deliveries WHERE it_ticket_id = ? ORDER BY id LIMIT 1000');
    $counts = [];
    foreach ($outbox['rows'] as $delivery) {
        $comment = $delivery['it_ticket_comment_id'] === null ? 'no_comment' : (string) $delivery['it_ticket_comment_id'];
        $status = (string) $delivery['status'];
        $counts[$comment][$status] = ($counts[$comment][$status] ?? 0) + 1;
    }
    echo json_encode(['observed_at' => gmdate(DATE_ATOM), 'token' => $token, 'database' => $database,
        'ticket_id' => 1, 'ticket' => $ticket, 'comments' => $comments, 'receipts' => $receipts,
        'watchers' => $watchers, 'outbox' => $outbox, 'outbox_counts_by_comment_and_status' => (object) $counts,
        'sequential_read_only_observations' => true, 'database_mutations_performed' => false,
        'raw_sql_and_private_values_emitted' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['evidence_read_complete' => false, 'exception_class' => $exception::class,
        'private_message_suppressed' => true, 'database_mutations_performed' => false], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
