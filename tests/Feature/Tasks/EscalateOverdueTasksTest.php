<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Site;
use App\Models\TaskWatcher;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

/** A user with the given permission keys granted via overrides. */
function makeEscalationUser(array $permissionKeys, ?Site $site = null): User
{
    $user = User::factory()->create(['approved_at' => now()]);

    foreach ($permissionKeys as $permissionKey) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            ['description' => str_replace('.', ' ', $permissionKey), 'group' => explode('.', $permissionKey)[0]],
        );
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    if ($site) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    return $user;
}

function makeEscalationIncident(Site $site): ClientIncident
{
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);

    return ClientIncident::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'status' => 'submitted',
    ]);
}

it('records a level-1 escalation for an overdue assigned follow-up and is idempotent', function () {
    $site = Site::factory()->create();
    $user = makeEscalationUser(['incidents.viewAny'], $site);
    $incident = makeEscalationIncident($site);
    $followup = $incident->followups()->create([
        'assigned_to_user_id' => $user->id,
        'due_at' => now()->subDay(),
        'notes' => 'Chase GP report',
    ]);

    $this->artisan('tasks:escalate')->assertExitCode(0);

    expect(DB::table('task_escalations')
        ->where('source', 'followup')
        ->where('item_id', $followup->id)
        ->where('level', 1)
        ->whereNotNull('notified_at')
        ->exists())->toBeTrue();

    // Only a day overdue — the 3-day manager escalation must not fire yet.
    expect(DB::table('task_escalations')
        ->where('source', 'followup')
        ->where('item_id', $followup->id)
        ->where('level', 2)
        ->exists())->toBeFalse();

    // The assignee received the nudge.
    expect($user->notifications()->count())->toBeGreaterThan(0);

    // Second run adds nothing — the (source, item, level) row dedupes.
    $rows = DB::table('task_escalations')->count();
    $notifications = DB::table('notifications')->count();

    $this->artisan('tasks:escalate')->assertExitCode(0);

    expect(DB::table('task_escalations')->count())->toBe($rows)
        ->and(DB::table('notifications')->count())->toBe($notifications);
});

it('escalates a 3-day-overdue item to level 2', function () {
    $site = Site::factory()->create();
    $user = makeEscalationUser(['incidents.viewAny'], $site);
    $incident = makeEscalationIncident($site);
    $followup = $incident->followups()->create([
        'assigned_to_user_id' => $user->id,
        'due_at' => now()->subDays(4),
        'notes' => 'Call whānau with outcome',
    ]);

    $this->artisan('tasks:escalate')->assertExitCode(0);

    expect(DB::table('task_escalations')
        ->where('source', 'followup')
        ->where('item_id', $followup->id)
        ->whereIn('level', [1, 2])
        ->count())->toBe(2);
});

it('prunes an out-of-scope watcher before overdue task notifications are sent', function () {
    $localSite = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $assignee = makeEscalationUser([
        'controlRoom.viewAny',
        'controlRoom.alerts.view',
    ], $localSite);
    $staleWatcher = makeEscalationUser([
        'controlRoom.alerts.view',
    ], $foreignSite);
    $alert = ControlRoomAlert::factory()->triaging()->create([
        'site_id' => $localSite->id,
        'assigned_to_user_id' => $assignee->id,
        'due_at' => now()->subDay(),
    ]);
    TaskWatcher::query()->create([
        'source' => 'alert',
        'item_id' => $alert->id,
        'user_id' => $staleWatcher->id,
    ]);

    $this->artisan('tasks:escalate')->assertExitCode(0);

    expect($staleWatcher->fresh()->notifications()->count())->toBe(0)
        ->and(TaskWatcher::query()
            ->where('source', 'alert')
            ->where('item_id', $alert->id)
            ->where('user_id', $staleWatcher->id)
            ->exists())->toBeFalse();
});
