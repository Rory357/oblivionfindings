<?php

// Read-only preflight: no Laravel bootstrap, migrations, seed, DDL or external calls.
$root = dirname(__DIR__, 5);
$fail = static function (string $reason): never {
    fwrite(STDERR, "ISOLATION FAILED: $reason\n");
    exit(1);
};
if (realpath(getcwd()) !== realpath($root)) $fail('Run from the package worktree root.');
foreach (['.env', '.env.testing', 'bootstrap/cache/config.php'] as $path) {
    if (is_file($root.'/'.$path)) $fail('Unexpected environment or cached configuration: '.$path);
}
foreach (['TEST_TOKEN', 'PARALLEL_PROCESS', 'PROCESS_TOKEN', 'DB_URL', 'DATABASE_URL', 'APP_CONFIG_CACHE'] as $key) {
    if (getenv($key) !== false && getenv($key) !== '') $fail('Inherited override present: '.$key);
}
$xml = new DOMDocument;
if (! $xml->load($root.'/phpunit.pkg02b.xml') || ! $xml->schemaValidate($root.'/vendor/phpunit/phpunit/phpunit.xsd')) $fail('Invalid package PHPUnit profile.');
$env = [];
foreach ($xml->getElementsByTagName('env') as $node) {
    if ($node->getAttribute('force') !== 'true') $fail('Every PHPUnit environment value must be forced.');
    $env[$node->getAttribute('name')] = $node->getAttribute('value');
}
$expected = ['APP_ENV'=>'testing', 'DB_CONNECTION'=>'mysql', 'DB_HOST'=>'127.0.0.1', 'DB_DATABASE'=>'oblivion_findings_pkg02b_5b0a_test', 'DB_URL'=>'', 'DB_SOCKET'=>'', 'MAIL_MAILER'=>'array', 'BROADCAST_CONNECTION'=>'null', 'QUEUE_CONNECTION'=>'null', 'CACHE_STORE'=>'array', 'SESSION_DRIVER'=>'array'];
foreach ($expected as $key=>$value) if (($env[$key] ?? null) !== $value) $fail('Unexpected '.$key);
$loader = require $root.'/vendor/autoload.php';
foreach (['App\\Models\\Asset'=>'app/Models/Asset.php', 'Tests\\TestCase'=>'tests/TestCase.php'] as $class=>$relative) {
    if (realpath($loader->findFile($class)) !== realpath($root.'/'.$relative)) $fail('Autoload resolves outside this worktree: '.$class);
}
$pdo = new PDO('mysql:host='.$env['DB_HOST'].';port='.$env['DB_PORT'], $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$names = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
$matching = array_values(array_filter($names, static fn($name)=>str_starts_with($name, $env['DB_DATABASE'])));
echo json_encode(['status'=>'PASS', 'root'=>$root, 'php'=>PHP_VERSION, 'databaseBase'=>$env['DB_DATABASE'], 'processToken'=>'Actual test PID; inherited tokens prohibited', 'existingPackageSchemas'=>$matching, 'autoload'=>'App and Tests resolve locally', 'appBootstrapped'=>false, 'databaseWrites'=>false, 'testCaseSha256'=>hash_file('sha256',$root.'/tests/TestCase.php'), 'profileSha256'=>hash_file('sha256',$root.'/phpunit.pkg02b.xml')], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
