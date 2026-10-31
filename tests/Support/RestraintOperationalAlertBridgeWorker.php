<?php

declare(strict_types=1);

use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

ini_set('memory_limit', '512M');
if (ini_get('memory_limit') !== '512M' || count($argv) !== 6) {
    throw new RuntimeException('The restraint worker requires its bounded memory and exact arguments.');
}
[$script, $database, $encodedPayload, $readyPath, $attemptPath, $releasePath] = $argv;
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_DATABASE') !== $database || ! preg_match('/^(oblivion_hs_goal_test_[0-9]+|oblivion_findings_codex_test_(?:k[0-9a-f]{6}_)?[0-9]+)$/D', $database)) {
    throw new RuntimeException('Restraint workers require the exact dedicated H&S native test schema.');
}
foreach ([$readyPath, $attemptPath, $releasePath] as $path) {
    if (realpath(dirname($path)) !== realpath(sys_get_temp_dir())
        || ! str_starts_with(basename($path), 'hs-restraint-')) {
        throw new RuntimeException('Restraint worker barriers must stay in their test temporary directory.');
    }
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if ($app->environment() !== 'testing' || $connection->getDriverName() !== 'mysql'
    || $connection->getDatabaseName() !== $database
    || $connection->selectOne('SELECT DATABASE() AS name')->name !== $database) {
    throw new RuntimeException('The booted worker connection differs from its admitted schema.');
}
// Suppress only queued notification delivery, never source authority or the writer.
Queue::fake();
$payload = json_decode(base64_decode($encodedPayload, true), true, flags: JSON_THROW_ON_ERROR);
$connectionId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($readyPath.'.tmp', json_encode(['pid' => getmypid(), 'connection_id' => $connectionId], JSON_THROW_ON_ERROR), LOCK_EX);
if (! rename($readyPath.'.tmp', $readyPath)) {
    throw new RuntimeException('The restraint worker ready marker could not be published.');
}
$deadline = microtime(true) + 60;
while (! is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('The restraint delivery barrier timed out.');
    }
    usleep(10000);
}
file_put_contents($attemptPath, (string) $connectionId, LOCK_EX);
try {
    $alert = $app->make(ComprehensiveAlertBridgeService::class)->bridgeOperationalAlert(
        'restraint_event', $payload['severity'], $payload['context'],
    );
    echo json_encode(['status' => 'accepted', 'alert_id' => (int) $alert->id], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    echo json_encode(['status' => 'rejected', 'error_class' => $error::class], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL);
    exit(1);
}
