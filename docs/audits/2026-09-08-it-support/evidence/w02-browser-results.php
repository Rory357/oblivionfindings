<?php

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
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
    throw new RuntimeException('Expected local test runtime is not active.');
}

DB::statement('SET TRANSACTION READ ONLY');
DB::beginTransaction();
try {
    $tickets = ItTicket::query()->whereIn('requester_user_id', [230, 231])
        ->whereIn('title', [
            'IT plan W02 disposable requester recovery 2026-09-09',
            'IT plan W02 disposable requester cancelled-wait recovery 2026-09-09',
            'IT plan W02 disposable technician recovery 2026-09-09',
            'IT plan W02 disposable expired-session recovery 2026-09-09',
            'IT plan W02 disposable acknowledged-close recovery 2026-09-09',
        ])->orderBy('id')->get(['id', 'reference', 'requester_user_id', 'site_id', 'status', 'source', 'lock_version', 'created_at']);
    $ids = $tickets->pluck('id');
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    echo json_encode([
        'captured_at' => now()->toIso8601String(),
        'base_path' => base_path(),
        'database_mutations_performed' => false,
        'app_asset' => $manifest['resources/js/app.tsx']['file'],
        'app_css' => $manifest['resources/js/app.tsx']['css'] ?? [],
        'it_asset' => $manifest['resources/js/pages/it/index.tsx']['file'],
        'manifest_sha256' => hash_file('sha256', public_path('build/manifest.json')),
        'tickets' => $tickets->toArray(),
        'receipts' => ItTicketCommandReceipt::query()->whereIn('it_ticket_id', $ids)
            ->get(['it_ticket_id', 'actor_user_id', 'channel', 'operation', 'request_uuid', 'committed_at'])->toArray(),
        'deliveries' => ItEmailDelivery::query()->whereIn('it_ticket_id', $ids)
            ->get(['it_ticket_id', 'status', 'attempt_count', 'queued_at', 'sending_at', 'accepted_at', 'dispatch_requested_at', 'dispatch_finished_at'])->toArray(),
        'version_fixture' => ItTicket::query()->whereKey(14)->where('requester_user_id', 231)
            ->where('title', 'IT Support W01 20260909 — Permitted technician work at second site')
            ->select(['id', 'reference', 'priority', 'lock_version', 'updated_at'])->withCount('events')->first()?->toArray(),
        'historical_closed_count' => ItTicket::query()->where('id', '<=', 7)->where('status', 'closed')->count(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    DB::rollBack();
}
