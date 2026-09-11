<?php

// Reproduce only the failed synthetic fixture inside an outer rollback transaction.
require __DIR__.'/w06-draft-browser-runtime.php';
$token = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || preg_match('/\A[a-f0-9]{16}\z/', $token) !== 1) { exit(2); }
$root = W06_BROWSER_CHECKOUT.'/storage/framework/testing/it-draft-browser-'.$token;
$settings = [
    'IT_DRAFT_BROWSER_TOKEN' => $token, 'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8766',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'LARAVEL_STORAGE_PATH' => $root.'/storage',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => W06_BROWSER_DATABASE_PREFIX.$token,
    'DB_URL' => 'null', 'DB_SOCKET' => '', 'DB_EMULATE_PREPARES' => 'true', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'BROADCAST_CONNECTION' => 'null', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'database',
    'SESSION_COOKIE' => 'it_draft_browser_'.$token, 'SESSION_DOMAIN' => 'null', 'SESSION_SECURE_COOKIE' => 'false',
    'IT_DRAFTS_ENABLED' => 'true', 'IT_DRAFT_RETENTION_DAYS' => '2', 'IT_DRAFT_TERMINAL_RETENTION_DAYS' => '3',
    'IT_MAILBOX_BROWSER_FIXTURES' => 'false', 'IT_API_BROWSER_FIXTURES' => 'false', 'IT_MONITORING_BROWSER_FIXTURES' => 'true',
    'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
];
foreach (simplexml_load_file(W06_BROWSER_CHECKOUT.'/phpunit.xml')->php->env as $setting) {
    if (in_array((string) $setting['name'], ['DB_USERNAME', 'DB_PASSWORD'], true)) { $settings[(string) $setting['name']] = (string) $setting['value']; }
}
foreach (['CONFIG', 'ROUTES', 'EVENTS', 'SERVICES', 'PACKAGES'] as $name) { $settings['APP_'.$name.'_CACHE'] = $root.'/storage/bootstrap-cache/'.strtolower($name).'.php'; }
foreach ($settings as $name => $value) { putenv($name.'='.$value); $_ENV[$name] = $_SERVER[$name] = $value; }
unset($settings);
$context = w06BrowserGuard();
w06BrowserRequire(is_file($root.'/launch-failed.json') && ! is_file($root.'/ready.json'), 'Only the retained failed fixture can be inspected.');
$app = w06BrowserApplication($context);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
w06BrowserRequire(Illuminate\Support\Facades\DB::scalar('SELECT DATABASE()') === $context['database'], 'Database identity changed.');
$fixtures = ['sites' => [], 'actors' => []];
foreach (['a', 'b', 'c'] as $key) { $fixtures['sites'][$key] = App\Models\Site::query()->where('name', 'W06 '.$token.' synthetic Site '.strtoupper($key))->sole()->id; }
foreach (['tech', 'cover'] as $key) { $fixtures['actors'][$key] = ['id' => App\Models\User::query()->where('email', 'w06-'.$key.'@demo.test')->sole()->id]; }
require __DIR__.'/w14-monitoring-browser-fixture.php';
App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot::retrieved(function ($snapshot): void {
    $ticket = App\Models\ItTicket::query()->find($snapshot->it_ticket_id);
    $online = App\Models\ControlRoom\Signal::query()->where('signal_type_code', 'device_online')->latest('id')->first();
    $sourceEvent = $online ? App\Domain\SecurityDevices\Models\DeviceEvent::query()->find(data_get($online->normalized_data, 'device_event_id')) : null;
    echo json_encode(['snapshot' => $snapshot->id, 'checksum' => $snapshot->hasValidChecksum(),
        'alert_status' => $snapshot->alert?->status, 'ticket_status' => $ticket?->status,
        'ticket_recovered' => $ticket?->monitoring_recovered_at !== null,
        'recovery_keys' => array_keys($snapshot->alert?->context['monitoring_recoveries'] ?? []),
        'online_notes' => $online?->processing_notes, 'event_time' => $sourceEvent?->occurred_at?->toIso8601String(),
        'signal_time' => $online?->occurred_at?->toIso8601String(),
        'event_ref_matches' => $online?->external_ref === 'device_event_'.$sourceEvent?->id]).PHP_EOL;
});
Illuminate\Support\Facades\DB::beginTransaction();
try {
    w14BrowserCreateMonitoringFixtures($context, $fixtures);
    echo json_encode(['fixture_passed' => true]).PHP_EOL;
} catch (Throwable $exception) {
    // Messages from the fixture guard contain no source payload or credentials.
    echo json_encode(['class' => $exception::class, 'file' => basename($exception->getFile()), 'line' => $exception->getLine(),
        'fixture_guard' => $exception instanceof RuntimeException && $exception->getFile() === __DIR__.'/w06-draft-browser-runtime.php' ? $exception->getMessage() : null,
        'frames' => array_map(fn ($frame) => ['file' => basename($frame['file'] ?? ''), 'line' => $frame['line'] ?? null], array_slice($exception->getTrace(), 0, 5))]).PHP_EOL;
} finally {
    Illuminate\Support\Facades\DB::rollBack();
    echo json_encode(['rolled_back' => true, 'remaining_devices' => App\Domain\SecurityDevices\Models\Device::query()->count()]).PHP_EOL;
}
