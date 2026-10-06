<?php

declare(strict_types=1);

use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\User;
use App\Services\Medication\Stock\MedicationStockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OwnedTestDatabase;

$root = dirname(__DIR__, 2);
if (count($argv) !== 11 || realpath($argv[1]) !== realpath($root)
    || ! in_array($argv[2], ['dose', 'movement'], true)) {
    throw new LogicException('Stock pack worker requires its exact test workspace and arguments.');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
    || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost'], true)
    || ! OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), (int) $argv[10])
    || DB::selectOne('SELECT DATABASE() AS selected_database')->selected_database !== $connection->getDatabaseName()) {
    throw new LogicException('Stock pack worker requires the exact parent-owned disposable MySQL database.');
}

$stock = ClientMedicationStock::findOrFail((int) $argv[3]);
$actor = User::findOrFail((int) $argv[6]);
$administration = ClientMedicationAdministration::findOrFail((int) $argv[5]);
file_put_contents($argv[7], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[9])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the stock race gate.');
    }
    usleep(10_000);
}
file_put_contents($argv[8], 'attempting');
DB::transaction(function () use ($app, $argv, $stock, $actor, $administration) {
    $service = $app->make(MedicationStockService::class);
    if ($argv[2] === 'dose') {
        $service->ordinaryDose($administration, $actor, '1.00');
    } else {
        $service->move($stock, $actor, [
            'lot_id' => (int) $argv[4], 'kind' => 'damaged', 'quantity' => '1.00',
            'reason' => 'Synthetic concurrency damage', 'request_uuid' => (string) Str::uuid(),
        ]);
    }
}, 5);
echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
