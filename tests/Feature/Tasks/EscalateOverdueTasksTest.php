<?php

use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

/** A user with the given permission keys granted via overrides. */
function makeEscalationUser(array $permissionKeys): User
{
    $user = User::factory()->create(['approved_at' => now()]);

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
    $user = makeEscalationUser(['incidents.viewAny']);
    $incident = ClientIncident::factory()->create(['status' => 'submitted']);
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
    $user = makeEscalationUser(['incidents.viewAny']);
    $incident = ClientIncident::factory()->create(['status' => 'submitted']);
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
