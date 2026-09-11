<?php

use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItEmailDelivery;
use App\Models\ItMailboxConnection;
use App\Models\ItQueue;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * W00 allowlisted local runtime evidence. No migrations, mutations, provider
 * calls, message bodies, addresses, credentials, or configuration values.
 * The MySQL transaction is explicitly read-only and is always rolled back.
 */
require __DIR__.'/../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local') || config('database.default') !== 'mysql') {
    fwrite(STDERR, "Refusing evidence collection outside the expected local MySQL application.\n");
    exit(1);
}

$connection = DB::connection();
$connection->statement('SET TRANSACTION READ ONLY');
$connection->beginTransaction();

try {
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $access = app(ItWorkAccessService::class);
    $users = User::query()
        ->whereIn('email', ['admin@demo.test', 'sw1@demo.test'])
        ->get(['id', 'email', 'role']);

    $data = [
        'captured_at' => now()->toIso8601String(),
        'base_path' => base_path(),
        'environment' => app()->environment(),
        'app_host' => parse_url(config('app.url'), PHP_URL_HOST),
        'database_connection' => config('database.default'),
        'database_name' => $connection->getDatabaseName(),
        'database_server_local' => in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', '::1'], true),
        'mail_default' => config('mail.default'),
        'mail_driver' => config('mail.mailers.'.config('mail.default').'.transport'),
        'queue_default' => config('queue.default'),
        'broadcast_default' => config('broadcasting.default'),
        'session_driver' => config('session.driver'),
        'configuration_cached' => app()->configurationIsCached(),
        'hot_file_exists' => is_file(public_path('hot')),
        'manifest_sha256' => hash_file('sha256', public_path('build/manifest.json')),
        'app_asset' => $manifest['resources/js/app.tsx']['file'],
        'app_css' => $manifest['resources/js/app.tsx']['css'] ?? [],
        'it_asset' => $manifest['resources/js/pages/it/index.tsx']['file'],
        'ticket_count' => ItTicket::query()->count(),
        'requester_submission_fixture' => ItTicket::query()
            ->where('title', 'IT plan W00 disposable requester check 2026-09-09')
            ->get(['id', 'reference', 'status', 'requester_user_id', 'site_id', 'source', 'created_at'])
            ->toArray(),
        'requester_submission_delivery' => ItEmailDelivery::query()
            ->whereHas('ticket', fn ($query) => $query->where('title', 'IT plan W00 disposable requester check 2026-09-09'))
            ->get(['id', 'it_ticket_id', 'status', 'attempt_count', 'queued_at', 'accepted_at'])
            ->toArray(),
        'historical_audit_fixture' => ItTicket::query()->whereKey(7)->first(['id', 'reference', 'status', 'workflow_state', 'site_id'])?->toArray(),
        'active_team_count' => ItTeam::query()->where('is_active', true)->count(),
        'active_queue_count' => ItQueue::query()->where('is_active', true)->count(),
        'mailboxes' => ItMailboxConnection::query()->get(['id', 'provider', 'status', 'last_polled_at'])->toArray(),
        'provider_configured' => collect(['microsoft', 'google'])->mapWithKeys(fn (string $provider): array => [
            $provider => (bool) config('services.'.$provider.'.client_id') && (bool) config('services.'.$provider.'.client_secret'),
        ])->all(),
        'demo_users' => $users->map(fn (User $user): array => [
            'id' => $user->id,
            'fixture' => $user->email === 'admin@demo.test' ? 'demo_admin' : 'support_worker_1',
            'role' => $user->role,
            'can_request' => $user->canDo('it.request'),
            'can_view' => $user->canDo('it.view'),
            'can_manage' => $user->canDo('it.manage'),
            'approved_site_ids' => $access->approvedSiteIds($user),
        ])->all(),
    ];

    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $connection->rollBack();
}
