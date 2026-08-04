<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

/** A user with the given permission keys granted via overrides. */
function makeEscalationUser(Site $site, array $permissionKeys, ?string $roleName = null): User
{
    $user = User::factory()->create([
        'approved_at' => now(),
        'role' => $roleName ?? 'support_worker',
    ]);

    if ($roleName !== null) {
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());
    }

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

    foreach ($permissionKeys as $permissionKey) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            ['description' => str_replace('.', ' ', $permissionKey), 'group' => explode('.', $permissionKey)[0]],
        );
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }

    return $user;
}

it('records a level-1 escalation for an overdue assigned follow-up and is idempotent', function () {
    $site = Site::factory()->create();
    $user = makeEscalationUser($site, ['incidents.viewAny']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $incident = ClientIncident::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'reported_by' => $user->id,
        'status' => 'submitted',
    ]);
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
    $user = makeEscalationUser($site, ['incidents.viewAny'], 'provider_manager');
    $client = Client::factory()->create(['site_id' => $site->id]);
    $incident = ClientIncident::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'reported_by' => $user->id,
        'status' => 'submitted',
    ]);
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
