<?php

/** Read-only metadata check; no query text, application bootstrap, or writes. */
require __DIR__.'/w06-draft-browser-runtime.php';
try {
    $token = '2379f5391c4c48c2';
    $root = W06_BROWSER_CHECKOUT.'/storage/framework/testing/it-draft-browser-'.$token;
    $manifest = json_decode(file_get_contents($root.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
    $database = W06_BROWSER_DATABASE_PREFIX.$token;
    w06BrowserRequire(PHP_SAPI === 'cli' && ($manifest['token'] ?? null) === $token
        && ($manifest['database'] ?? null) === $database
        && ($manifest['approval_fingerprint'] ?? null) === '18d3aa7e2acfcd916b320c2d8bd83208c433bcdf2a2428dec12d7f839c75f859'
        && ($manifest['source_hashes'] ?? null) === w06BrowserSources()
        && is_file($root.'/ready.json'), 'Owned ready browser/source identity differs.');
    $xml = simplexml_load_file(W06_BROWSER_CHECKOUT.'/phpunit.xml');
    $access = [];
    foreach ($xml->php->env as $setting) {
        if (in_array((string) $setting['name'], ['DB_USERNAME', 'DB_PASSWORD'], true)) {
            $access[(string) $setting['name']] = (string) $setting['value'];
        }
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database, $access['DB_USERNAME'], $access['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    w06BrowserRequire($pdo->query('SELECT DATABASE()')->fetchColumn() === $database, 'Owned schema differs.');
    $query = $pdo->prepare('SELECT ID, DB, COMMAND, TIME, STATE FROM information_schema.PROCESSLIST WHERE DB = ? OR DB REGEXP ? ORDER BY TIME DESC LIMIT 25');
    $query->execute([$database, '^oblivion_it_support_test_it_[a-f0-9]{16}$']);
    echo json_encode(['at_utc' => gmdate('c'), 'database' => $database, 'threads' => $query->fetchAll(PDO::FETCH_ASSOC), 'database_mutations_performed' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['error_class' => $error::class, 'file' => basename($error->getFile()), 'line' => $error->getLine(), 'database_mutations_performed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
