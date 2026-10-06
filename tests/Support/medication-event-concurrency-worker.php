<?php

use App\Models\Site;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1' || preg_match('/^oblivion_findings_codex_test_[a-zA-Z0-9_]+$/D', (string) getenv('DB_DATABASE')) !== 1) {
    throw new RuntimeException('Join only the parent process isolated local test schema.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::getDatabaseName() !== getenv('DB_DATABASE') || config('database.connections.mysql.host') !== '127.0.0.1') {
    throw new RuntimeException('Medication chain concurrency uses the isolated local test database only.');
}
[$script, $first, $second, $siteId, $command, $gate] = $argv;
$attempt = 0;
DB::transaction(function () use ($first, $second, $siteId, $command, $gate, &$attempt): void {
    $attempt++;
    file_put_contents($gate.'.attempt', (string) $attempt);
    if ((int) $first > 0) {
        $row = Site::query()->whereKey((int) $first)->lockForUpdate()->firstOrFail();
        $row->update(['name' => $row->name.':'.$command]);
    }
    if ($attempt === 1) {
        file_put_contents($gate.'.ready', 'ready');
        $deadline = microtime(true) + 25;
        while (! is_file($gate.'.release')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Concurrency barrier timed out.');
            }
            usleep(10000);
        }
    }
    if ((int) $second > 0) {
        Site::query()->whereKey((int) $second)->lockForUpdate()->firstOrFail();
    }
    app(MedicationEventRecorder::class)->append(new MedicationEventData((int) $siteId, 'test.concurrent', 'synthetic_command', $command, null, CarbonImmutable::now('UTC'), 'Synthetic command committed', ['attempt' => $attempt]));
}, 5);
echo 'committed';
