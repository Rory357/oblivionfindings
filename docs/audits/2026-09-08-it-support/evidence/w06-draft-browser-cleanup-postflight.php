<?php

/** Independent read-only observation after the exact guarded browser teardown. */
try {
    $checkout = realpath(__DIR__.'/../../../..');
    $token = $argv[1] ?? '';
    $fingerprint = $argv[2] ?? '';
    if (PHP_SAPI !== 'cli' || count($argv) !== 3
        || strcasecmp(str_replace('\\', '/', (string) $checkout), 'C:/Users/steph/Herd/oblivionfindings') !== 0
        || preg_match('/^[a-f0-9]{16}$/D', $token) !== 1
        || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
        throw new RuntimeException('Invalid observation boundary.');
    }
    $evidencePath = __DIR__.'/w06-draft-browser-cleanup-'.$token.'.json';
    if (! is_file($evidencePath) || is_link($evidencePath)) {
        throw new RuntimeException('Exact cleanup evidence missing.');
    }
    $evidence = json_decode(file_get_contents($evidencePath), true, flags: JSON_THROW_ON_ERROR);
    $database = 'oblivion_it_draft_browser_'.$token;
    $root = $checkout.'/storage/framework/testing/it-draft-browser-'.$token;
    if (($evidence['token'] ?? null) !== $token || ($evidence['database'] ?? null) !== $database
        || ($evidence['owner']['approval_fingerprint'] ?? null) !== $fingerprint
        || ($evidence['database_removed'] ?? null) !== true
        || strcasecmp(str_replace('\\', '/', $evidence['root'] ?? ''), str_replace('\\', '/', $root)) !== 0) {
        throw new RuntimeException('Exact cleanup identity differs.');
    }
    $configuration = simplexml_load_file($checkout.'/phpunit.xml');
    $access = [];
    foreach ($configuration->php->env as $setting) {
        $name = (string) $setting['name'];
        if (in_array($name, ['DB_USERNAME', 'DB_PASSWORD'], true)) {
            $access[$name] = (string) $setting['value'];
        }
    }
    if (! array_key_exists('DB_USERNAME', $access) || ! array_key_exists('DB_PASSWORD', $access)) {
        throw new RuntimeException('Existing local test access source incomplete.');
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306', $access['DB_USERNAME'], $access['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $query->execute([$database]);
    $checks = ['schema_absent' => (int) $query->fetchColumn() === 0, 'owned_directory_absent' => ! file_exists($root) && ! is_link($root)];
    echo json_encode(['token' => $token, 'database' => $database, 'checks' => $checks, 'database_mutations_performed' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(in_array(false, $checks, true) ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['postflight_complete' => false, 'exception_class' => $exception::class, 'private_message_suppressed' => true], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
