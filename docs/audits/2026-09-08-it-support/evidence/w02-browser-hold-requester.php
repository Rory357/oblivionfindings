<?php

// Bounded, read-only row contention for one explicitly synthetic browser actor.
require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (! app()->environment('local')
    || strcasecmp(str_replace('\\', '/', realpath(base_path())), 'C:/Users/steph/Herd/oblivionfindings') !== 0
    || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'oblivion_findings_codex_test'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('mail.default') !== 'array'
    || config('queue.default') !== 'sync'
    || app()->configurationIsCached()) {
    throw new RuntimeException('Expected isolated local browser runtime is not active.');
}

$technician = in_array('--technician', $argv, true);
$actor = User::query()->whereKey($technician ? 230 : 231)
    ->where('email', $technician ? 'it-support-w01-20260909-tech@demo.test' : 'it-support-w01-20260909-requester@demo.test')->firstOrFail();
$releasePath = __DIR__.($technician ? '/w02-technician-lock.release' : '/w02-requester-lock.release');
if (file_exists($releasePath)) {
    throw new RuntimeException('Release marker already exists; refusing to start a new lock.');
}

echo json_encode(['preflight' => true, 'synthetic_actor_id' => $actor->id, 'maximum_seconds' => 45, 'database_writes' => false]).PHP_EOL;
if (! in_array('--hold', $argv, true)) {
    exit(0);
}

DB::beginTransaction();
try {
    User::query()->whereKey($actor->id)->where('email', $actor->email)->lockForUpdate()->firstOrFail();
    $started = microtime(true);
    echo json_encode(['row_lock_held' => true, 'held_at' => now()->toIso8601String()]).PHP_EOL;
    flush();
    while (microtime(true) - $started < 45 && ! file_exists($releasePath)) {
        usleep(100000);
        clearstatcache(true, $releasePath);
    }
    echo json_encode(['release_requested' => file_exists($releasePath), 'elapsed_seconds' => round(microtime(true) - $started, 3)]).PHP_EOL;
} finally {
    DB::rollBack();
    echo json_encode(['row_lock_released' => true, 'database_writes' => false]).PHP_EOL;
}
