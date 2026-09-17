<?php

require dirname(__DIR__, 4).'/vendor/autoload.php';

$root = dirname(__DIR__, 4);
$settings = Dotenv\Dotenv::createArrayBacked($root, '.env.testing')->load();
$configuration = simplexml_load_file($root.'/phpunit.xml');
foreach ($configuration->php->env as $entry) {
    $settings[(string) $entry['name']] = (string) $entry['value'];
}
$database = ($settings['DB_DATABASE'] ?? 'oblivion_findings_codex_test').'_myday_publish_20260913';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s', $settings['DB_HOST'] ?? '127.0.0.1', $settings['DB_PORT'] ?? '3306'),
    $settings['DB_USERNAME'] ?? 'root',
    $settings['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
$query->execute([$database]);
echo json_encode(['owned_publish_database_remaining' => (int) $query->fetchColumn()]).PHP_EOL;
