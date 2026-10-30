<?php

// Guarded, real-process fixture only. Never an application worker or scheduler entry.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$script, $database, $operation, $actorId, $sourceId, $json, $ready, $go] = $argv;
$connection = Illuminate\Support\Facades\DB::connection();
if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql' || ! str_contains(strtolower($database), 'test')
    || $connection->getDatabaseName() !== $database || $connection->transactionLevel() !== 0
    || dirname($ready) !== sys_get_temp_dir() || dirname($go) !== sys_get_temp_dir()
    || ! str_starts_with(basename($ready), 'hs-incident-routing-race-') || ! str_starts_with(basename($go), 'hs-incident-routing-race-')) {
    throw new RuntimeException('Routing worker requires exact admitted testing schema and temporary barrier identities.');
}
Illuminate\Support\Carbon::setTestNow('2026-10-12T03:00:00Z');
Illuminate\Support\Facades\Bus::fake([App\Jobs\DispatchIncidentOwnerDelivery::class]);
$input = json_decode(base64_decode($json, true), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 30;
while (! is_file($go)) { if (microtime(true) >= $deadline) { throw new RuntimeException('Routing barrier timed out.'); } usleep(10000); }
try {
    $receipt = match ($operation) {
        'consume' => app(App\Services\Incidents\IncidentRoutingService::class)->consume((int) $sourceId),
        'settings' => app(App\Services\Incidents\IncidentRoutingSettings::class)->save(App\Models\User::findOrFail((int) $actorId), $input),
        'manual' => (function () use ($actorId, $sourceId, $input): array {
            $change = app(App\Services\Incidents\IncidentOwnershipService::class)->assign(App\Models\User::findOrFail((int) $actorId), App\Models\ClientIncident::findOrFail((int) $sourceId),
                $input['owner_user_id'], $input['expected_revision'], $input['request_uuid'], $input['reason']);
            return ['change_id' => (int) $change->id];
        })(),
        default => throw new RuntimeException('Unknown guarded routing fixture operation.'),
    };
    echo json_encode(['status' => 'accepted', 'receipt' => $receipt], JSON_THROW_ON_ERROR);
} catch (Illuminate\Validation\ValidationException $error) {
    echo json_encode(['status' => 'rejected', 'fields' => array_keys($error->errors())], JSON_THROW_ON_ERROR);
} catch (Illuminate\Database\QueryException $error) {
    if (! in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) { throw $error; }
    echo json_encode(['status' => 'retryable', 'code' => (int) $error->errorInfo[1]], JSON_THROW_ON_ERROR);
}
