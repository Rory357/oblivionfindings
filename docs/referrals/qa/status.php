<?php

// Read-only status for an explicitly named disposable schema owned by this task.
$root = dirname(__DIR__, 3);
$environment = [];
foreach (simplexml_load_file($root.'/phpunit.xml')->php->env as $entry) {
    $environment[(string) $entry['name']] = (string) $entry['value'];
}
$base = $environment['DB_DATABASE'] ?? 'oblivion_findings_codex_test';
$database = $argv[1] ?? '';
if (! preg_match('/\A(?:'.preg_quote($base, '/').'|oblivion_referrals_preview)_[1-9][0-9]*\z/', $database)) {
    throw new RuntimeException('An exact disposable schema is required.');
}
$pdo = new PDO('mysql:host='.($environment['DB_HOST'] ?? '127.0.0.1').';port='.($environment['DB_PORT'] ?? '3306'), $environment['DB_USERNAME'], $environment['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$statement = $pdo->prepare('SELECT ID, DB, TIME, STATE, LEFT(INFO, 240) AS operation FROM information_schema.PROCESSLIST WHERE DB = ?');
$statement->execute([$database]);
$processes = $statement->fetchAll(PDO::FETCH_ASSOC);
if (($argv[2] ?? '') === '--migrations') {
    // The schema identifier above is strictly constrained to this task's disposable prefixes.
    $applied = $pdo->query('SELECT migration FROM `'.$database.'`.migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $source = array_map(fn ($path) => basename($path, '.php'), glob($root.'/database/migrations/*.php'));
    echo json_encode(['processes' => $processes, 'applied_count' => count($applied),
        'pending_count' => count(array_diff($source, $applied)), 'latest_applied' => array_slice($applied, -3)], JSON_PRETTY_PRINT).PHP_EOL;
} else {
    echo json_encode($processes, JSON_PRETTY_PRINT).PHP_EOL;
}
