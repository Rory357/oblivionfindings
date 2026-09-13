<?php

// Read-only crash recovery observation. This file never boots the application.
try {
    $repo = str_replace('\\', '/', (string) realpath(__DIR__.'/../../../..'));
    $token = '03dee6c781fe4587';
    $fingerprint = '65f611dcff46a7c52347a7ac5ff01f60eaca6c0a69c9f835c7adb5a2a3855a81';
    $database = 'oblivion_it_draft_browser_'.$token;
    $root = $repo.'/storage/framework/testing/it-draft-browser-'.$token;
    if (PHP_SAPI !== 'cli' || str_replace('\\', '/', (string) $repo) !== 'C:/Users/steph/Herd/oblivionfindings'
        || is_link($root) || realpath($root) === false || str_replace('\\', '/', realpath($root)) !== $root) {
        throw new RuntimeException('Owned path mismatch.');
    }
    $owner = json_decode(file_get_contents($root.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
    $stopped = json_decode(file_get_contents($root.'/server-stopped.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($owner['token'] !== $token || $owner['database'] !== $database || $owner['approval_fingerprint'] !== $fingerprint
        || $stopped['token'] !== $token || $stopped['server_settled'] !== true) {
        throw new RuntimeException('Owned identity mismatch.');
    }
    $access = [];
    foreach (simplexml_load_file($repo.'/phpunit.xml')->php->env as $setting) {
        if (in_array((string) $setting['name'], ['DB_USERNAME', 'DB_PASSWORD'], true)) {
            $access[(string) $setting['name']] = (string) $setting['value'];
        }
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306', $access['DB_USERNAME'], $access['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($access);
    $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $query->execute([$database]);
    echo json_encode(['token' => $token, 'database' => $database, 'schema_exists' => (int) $query->fetchColumn() === 1,
        'database_mutations_performed' => false, 'observed_at' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['observation_failed' => true, 'exception_class' => $exception::class, 'private_message_suppressed' => true]).PHP_EOL);
    exit(1);
}
