<?php

/** Read-only metadata for one explicitly reviewed, already-running browser schema. */
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
    $started = microtime(true);
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306', $access['DB_USERNAME'], $access['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $connectionMs = round((microtime(true) - $started) * 1000, 2);
    $read = function (string $sql) use ($pdo, $database): array {
        $started = microtime(true);
        $query = $pdo->prepare($sql);
        $query->execute([$database]);

        return ['elapsed_ms' => round((microtime(true) - $started) * 1000, 2), 'rows' => $query->fetchAll(PDO::FETCH_ASSOC)];
    };
    $schema = $read('SELECT COUNT(*) AS schema_count FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $tables = $read("SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_ROWS AS estimated_rows FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('users', 'sessions', 'migrations', 'it_tickets', 'it_ticket_comments', 'it_attachment_storage_intents') ORDER BY TABLE_NAME");
    $processes = $read('SELECT ID, COMMAND, TIME AS elapsed_seconds, STATE FROM information_schema.PROCESSLIST WHERE DB = ? ORDER BY ID LIMIT 100');
    echo json_encode(['observed_at' => gmdate(DATE_ATOM), 'token' => $token, 'database' => $database,
        'connection_ms' => $connectionMs, 'schema' => $schema, 'tables' => $tables, 'processes' => $processes,
        'database_mutations_performed' => false, 'raw_sql_and_private_values_emitted' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['health_read_complete' => false, 'exception_class' => $exception::class,
        'private_message_suppressed' => true, 'database_mutations_performed' => false], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
