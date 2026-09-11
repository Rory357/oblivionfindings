<?php

/** Stage only synthetic provider messages; all ticket merging must happen through the application UI. */
require __DIR__.'/w06-draft-browser-runtime.php';
try {
    [$script, $token, $fingerprint, $phase] = $argv + [null, null, null, null];
    w06BrowserRequire(PHP_SAPI === 'cli' && count($argv) === 4 && preg_match('/^[a-f0-9]{16}$/D', (string) $token) === 1
        && preg_match('/^[a-f0-9]{64}$/D', (string) $fingerprint) === 1
        && in_array($phase, ['before_merge', 'after_merge', 'outbound_reply', 'observe'], true), 'Invalid synthetic merge staging arguments.');
    $repo = realpath(__DIR__.'/../../../..');
    w06BrowserRequire($repo !== false && strcasecmp(w06BrowserPath($repo), W06_BROWSER_CHECKOUT) === 0, 'Unexpected checkout.');
    $root = $repo.'/storage/framework/testing/it-draft-browser-'.$token;
    foreach ([$repo.'/storage/framework/testing', $root] as $path) {
        w06BrowserRequire(is_dir($path) && ! is_link($path) && w06BrowserPath((string) realpath($path)) === w06BrowserPath($path), 'Owned directory is missing or linked.');
    }
    foreach (['owner.json', 'ready.json', 'mailbox-provider-state.json'] as $leaf) {
        w06BrowserRequire(is_file($root.'/'.$leaf) && ! is_link($root.'/'.$leaf), 'Required owned fixture evidence is unavailable.');
    }
    $owner = json_decode(file_get_contents($root.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
    $ready = json_decode(file_get_contents($root.'/ready.json'), true, flags: JSON_THROW_ON_ERROR);
    $database = W06_BROWSER_DATABASE_PREFIX.$token;
    w06BrowserRequire($owner['token'] === $token && $owner['database'] === $database && $owner['mailbox_fixtures'] === true
        && $owner['approval_fingerprint'] === $fingerprint && $owner['source_hashes'] === w06BrowserSources()
        && w06BrowserPath($owner['root']) === w06BrowserPath($root) && $ready['database'] === $database && $ready['ready'] === true,
        'Owned source, fingerprint or schema identity changed.');
    $access = [];
    foreach (simplexml_load_file($repo.'/phpunit.xml')->php->env as $entry) {
        if (in_array((string) $entry['name'], ['DB_USERNAME', 'DB_PASSWORD'], true)) $access[(string) $entry['name']] = (string) $entry['value'];
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database, $access['DB_USERNAME'], $access['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($access);
    w06BrowserRequire($pdo->query('SELECT DATABASE()')->fetchColumn() === $database, 'Unexpected database.');
    $ids = $ready['fixtures']['tickets'];
    $sourceId = (int) $ids['duplicate_workspace']['id'];
    $targetId = (int) $ids['public_workspace']['id'];
    $query = $pdo->prepare('SELECT id, reference, merged_into_ticket_id, status, lock_version FROM it_tickets WHERE id IN (?, ?) ORDER BY id');
    $query->execute([$sourceId, $targetId]);
    $tickets = array_column($query->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    w06BrowserRequire(isset($tickets[$sourceId], $tickets[$targetId]), 'Synthetic canonical tickets are missing.');
    $stateFile = fopen($root.'/mailbox-provider-state.json', $phase === 'observe' ? 'r' : 'r+');
    w06BrowserRequire($stateFile !== false && flock($stateFile, $phase === 'observe' ? LOCK_SH : LOCK_EX), 'Cannot lock synthetic provider state.');
    try {
        $state = json_decode(stream_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
        w06BrowserRequire($state['token'] === $token && $state['synthetic_only'] === true, 'Synthetic provider identity changed.');
        $receipts = $pdo->query('SELECT id, it_mailbox_connection_id, it_ticket_id, status, quarantine_reason, acknowledged_at, acknowledgement_attempts FROM it_inbound_emails ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        // Read all evidence before changing the inbox: a report query failure must
        // leave the staging phase untouched and retryable.
        $evidence = [
            'connections' => $pdo->query('SELECT id, provider FROM it_mailbox_connections ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'ticket_count' => (int) $pdo->query('SELECT COUNT(*) FROM it_tickets')->fetchColumn(),
            'email_comment_commands' => (int) $pdo->query("SELECT COUNT(*) FROM it_ticket_command_receipts WHERE channel = 'email' AND operation = 'ticket.comment'")->fetchColumn(),
            'comment_audits' => (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'it.ticket.comment.added'")->fetchColumn(),
            'merge_events' => $pdo->query("SELECT id, subject_id, actor_user_id, type FROM it_ticket_events WHERE type = 'merged' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
            'synthetic_comments' => $pdo->query("SELECT id, ticket_id, author_user_id, source_channel, is_internal, body FROM it_ticket_comments WHERE body LIKE 'W11 % reply.' OR body LIKE 'W12 % reply.' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
            'outgoing_identities' => $pdo->query('SELECT id, it_ticket_id, recipient_user_id, status, rfc_message_id_hash, rfc_message_id_recorded_at FROM it_email_deliveries WHERE rfc_message_id_hash IS NOT NULL ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'delivery_outcomes' => $pdo->query('SELECT id, it_ticket_id, it_ticket_comment_id, recipient_user_id, status, provider, attempt_count, retry_of_delivery_id, retry_count, dispatch_finished_at, accepted_at, delivered_at, failed_at, notification_context FROM it_email_deliveries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ];
        $deliveryStatePath = $root.'/delivery-provider-state.json';
        w06BrowserRequire(is_file($deliveryStatePath) && ! is_link($deliveryStatePath)
            && w06BrowserPath((string) realpath($deliveryStatePath)) === w06BrowserPath($deliveryStatePath), 'Owned delivery fixture state escaped.');
        $deliveryHandle = fopen($deliveryStatePath, 'r');
        w06BrowserRequire($deliveryHandle !== false && flock($deliveryHandle, LOCK_SH), 'Could not read settled synthetic delivery evidence.');
        try {
            $deliveryState = json_decode(stream_get_contents($deliveryHandle), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            flock($deliveryHandle, LOCK_UN);
            fclose($deliveryHandle);
        }
        w06BrowserRequire(($deliveryState['token'] ?? null) === $token && ($deliveryState['synthetic_only'] ?? false) === true, 'Delivery fixture identity changed.');
        $evidence['synthetic_delivery_transport'] = $deliveryState;
        if ($phase !== 'observe') {
            w06BrowserRequire((int) $pdo->query('SELECT COUNT(*) FROM it_mailbox_connections WHERE poll_claim_token IS NOT NULL')->fetchColumn() === 0, 'Wait for the existing poll to settle.');
            if ($phase === 'outbound_reply') {
                w06BrowserRequire(! isset($state['merge_scenario']) && ! isset($state['outbound_scenario']) && $receipts === []
                    && $state['unread'] === ['microsoft' => range(1, 43), 'google' => range(1, 43)], 'Only a pristine inbox can stage outbound replies.');
                $outgoingQuery = $pdo->prepare("SELECT d.id, d.it_ticket_id FROM it_email_deliveries d JOIN it_tickets t ON t.id = d.it_ticket_id
                    WHERE d.recipient_email = 'w06-requester@demo.test' AND d.notification_type = 'ticket_created' AND d.audience = 'receipt'
                    AND d.status = 'accepted' AND d.rfc_message_id_hash IS NOT NULL AND t.title = ?");
                $outgoingQuery->execute(['W12 '.$token.' outbound reply verification']);
                $outgoing = $outgoingQuery->fetchAll(PDO::FETCH_ASSOC);
                w06BrowserRequire(count($outgoing) === 1, 'Create and send the unique synthetic requester receipt through the UI first.');
                $state['outbound_scenario'] = ['delivery_id' => (int) $outgoing[0]['id'], 'ticket_id' => (int) $outgoing[0]['it_ticket_id']];
                $state['unread'] = ['microsoft' => [49, 50, 51, 52], 'google' => [49, 50, 51, 52]];
            } elseif ($phase === 'before_merge') {
                w06BrowserRequire(! isset($state['merge_scenario']) && $receipts === []
                    && $state['unread'] === ['microsoft' => range(1, 43), 'google' => range(1, 43)]
                    && $tickets[$sourceId]['merged_into_ticket_id'] === null, 'Only the pristine synthetic inbox may start this scenario.');
                $state['merge_scenario'] = ['phase' => $phase, 'source_id' => $sourceId, 'target_id' => $targetId,
                    'source_reference' => $tickets[$sourceId]['reference']];
                $state['unread'] = ['microsoft' => [44], 'google' => [44]];
            } else {
                w06BrowserRequire(($state['merge_scenario']['phase'] ?? null) === 'before_merge'
                    && (int) $tickets[$sourceId]['merged_into_ticket_id'] === $targetId
                    && $tickets[$sourceId]['status'] === 'closed' && count($receipts) === 2
                    && count(array_filter($receipts, fn ($row) => (int) $row['it_ticket_id'] === $sourceId && $row['status'] === 'processed' && $row['acknowledged_at'] !== null)) === 2
                    && $state['unread'] === ['microsoft' => [], 'google' => []], 'Both original replies and the actual UI merge must finish first.');
                $state['merge_scenario']['phase'] = $phase;
                $state['unread'] = ['microsoft' => [45, 46, 47, 48], 'google' => [45, 46, 47, 48]];
            }
            rewind($stateFile);
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            w06BrowserRequire(ftruncate($stateFile, 0) && fwrite($stateFile, $json) === strlen($json) && fflush($stateFile), 'Synthetic inbox update was not confirmed.');
        }
        echo json_encode(['token' => $token, 'phase' => $phase, 'database_mutations' => false,
            'synthetic_inbox_changed' => $phase !== 'observe', 'tickets' => array_values($tickets), 'receipts' => $receipts,
            ...$evidence, 'provider_state' => $state], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        flock($stateFile, LOCK_UN);
        fclose($stateFile);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['synthetic_merge_scenario_complete' => false, 'exception_class' => $exception::class,
        'failure_file' => basename($exception->getFile()), 'failure_line' => $exception->getLine(), 'private_message_suppressed' => true]).PHP_EOL);
    exit(1);
}
