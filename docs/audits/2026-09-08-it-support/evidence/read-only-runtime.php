<?php

use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItMailboxConnection;
use App\Models\ItQueue;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

// Audit-only, read-only metadata. Deliberately excludes credentials and message bodies.
require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$data = [
    'base_path' => base_path(),
    'environment' => app()->environment(),
    'mail_default' => config('mail.default'),
    'queue_default' => config('queue.default'),
    'tickets' => ItTicket::query()->orderBy('id')->get(['id', 'reference', 'status', 'workflow_state', 'priority', 'site_id', 'is_organisation_wide', 'assigned_to_user_id', 'owner_user_id', 'queue_id', 'team_id', 'sla_state', 'created_at', 'first_response_due_at', 'resolution_due_at', 'first_responded_at'])->toArray(),
    'mailboxes' => ItMailboxConnection::query()->get(['provider', 'status', 'last_polled_at'])->toArray(),
    'queues' => ItQueue::query()->get(['id', 'name', 'is_active', 'team_id'])->toArray(),
    'teams' => ItTeam::query()->get(['id', 'name', 'is_active'])->toArray(),
    'demo_users' => User::query()->whereIn('email', ['admin@demo.test', 'sw1@demo.test'])->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'can_request' => $u->canDo('it.request'), 'can_view' => $u->canDo('it.view'), 'can_manage' => $u->canDo('it.manage'), 'approved_site_ids' => app(ItWorkAccessService::class)->approvedSiteIds($u)])->toArray(),
];
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
