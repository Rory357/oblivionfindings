<?php

require __DIR__.'/inspect-delivery.php';

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

// Both processes are restricted by inspect-delivery's exact preview/fictional-publication guard.
if (($argv[2] ?? '') === 'writer') {
    $person = Client::whereKey((int) ($argv[3] ?? 0))->where('first_name', $argv[4] ?? '')->sole();
    if (! str_starts_with($person->first_name, 'Fictional concurrency ') || $person->status !== 'onboarding' || ! $person->site_id) {
        throw new RuntimeException('New fictional unstarted person required.');
    }
    echo "WRITER_READY\n";
    flush();
    if (trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Writer barrier not released.');
    }
    echo "WRITER_ATTEMPT\n";
    flush();
    $at = microtime(true);
    try {
        $person->forceFill(['site_id' => null])->saveQuietly();
        throw new RuntimeException('Stale scope change was accepted.');
    } catch (ValidationException $e) {
        if (! isset($e->errors()['status'])) {
            throw $e;
        }
        echo 'WRITER_BLOCKED '.json_encode(['seconds' => microtime(true) - $at])."\n";
    }
    exit(0);
}

$person = null;
$process = null;
$input = new InputStream;
$waitFor = function (string $marker) use (&$process): void {
    $deadline = microtime(true) + 15;
    while (! str_contains($process->getOutput(), $marker)) {
        if (! $process->isRunning() || microtime(true) > $deadline) {
            throw new RuntimeException('Barrier failed: '.$marker.' '.$process->getOutput().' '.$process->getErrorOutput());
        }
        usleep(20000);
    }
};
try {
    $siteId = Client::findOrFail(2)->site_id;
    $name = 'Fictional concurrency '.Str::uuid();
    $person = Client::create(['first_name' => $name, 'last_name' => 'Disposable verification', 'site_id' => $siteId]);
    $process = new Process([PHP_BINARY, __FILE__, $database, 'writer', (string) $person->id, $name], $root);
    $process->setInput($input);
    $process->setTimeout(25);
    $process->start();
    $waitFor('WRITER_READY');
    DB::beginTransaction();
    // Test-only synthetic prior-state writer: no real commencement or approval is being fabricated.
    DB::table('clients')->where('id', $person->id)->where('first_name', $name)->update(['status' => 'active']);
    $input->write("GO\n");
    $waitFor('WRITER_ATTEMPT');
    usleep(600000);
    if (! $process->isRunning() || str_contains($process->getOutput(), 'WRITER_BLOCKED')) {
        throw new RuntimeException('The second writer did not wait for the canonical row lock.');
    }
    DB::commit();
    $input->close();
    $process->wait();
    if (! $process->isSuccessful() || ! preg_match('/WRITER_BLOCKED (.+)/', $process->getOutput(), $matches)) {
        throw new RuntimeException('Writer result failed: '.$process->getOutput().' '.$process->getErrorOutput());
    }
    $result = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    $current = $person->fresh();
    if ($result['seconds'] < 0.5 || $current->status !== 'active' || (int) $current->site_id !== (int) $siteId) {
        throw new RuntimeException('Concurrent state was overwritten or locking was not observed.');
    }
    $verification = ['passed' => ['Separate process reads original onboarding state', 'Protected scope save waits for competing canonical row transaction', 'Committed activation invalidates stale scope save', 'Current status and site remain unchanged by stale writer'], 'writer_wait_seconds' => $result['seconds']];
} finally {
    $input->close();
    if ($process?->isRunning()) {
        $process->stop(1);
    }
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    // Only this invocation's exact synthetic person is removed; all established preview people remain.
    if ($person && $person->first_name === ($name ?? null)) {
        Client::whereKey($person->id)->where('first_name', $name)->first()?->forceDelete();
    }
}

if (Client::withTrashed()->whereKey($person->id)->exists()) {
    throw new RuntimeException('Temporary concurrency person was not removed.');
}
$verification['passed'][] = 'Temporary synthetic person removed after verification';
$verification['count'] = count($verification['passed']);
echo json_encode($verification).PHP_EOL;
