<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\IncidentFollowup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\TaskAggregator;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function makeTasksUser(array $permissionKeys): User
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

function scopeAllTasksUserToSite(User $user, Site $site): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'tenant_id' => $user->organization_id,
        'user_id' => $user->id,
        'position_role' => $user->role ?? 'coordinator',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);
}

it('renders the dashboard with an open incident for a permitted user', function () {
    $user = makeTasksUser(['incidents.viewAny']);
    $incident = ClientIncident::factory()->create(['status' => 'submitted']);

    $this->actingAs($user)
        ->get('/tasks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('tasks/index')
            ->has('stats')
            ->where('items', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['id'] === 'incident-'.$incident->id
                    && str_starts_with((string) $item['ref'], 'INC-'))));
});

it('shows no sources or items to a user with no module permissions', function () {
    $user = makeTasksUser([]);
    ClientIncident::factory()->create(['status' => 'submitted']);

    $this->actingAs($user)
        ->get('/tasks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('tasks/index')
            ->where('sources', [])
            ->where('items', []));
});

it('excludes closed items by default and includes them with done=1', function () {
    $user = makeTasksUser(['incidents.viewAny']);
    $closed = ClientIncident::factory()->create(['status' => 'closed']);

    $inList = fn ($items) => collect($items)->contains(fn ($i) => $i['id'] === 'incident-'.$closed->id);

    $this->actingAs($user)->get('/tasks')
        ->assertInertia(fn ($page) => $page->where('items', fn ($items) => ! $inList($items)));

    $this->actingAs($user)->get('/tasks?done=1')
        ->assertInertia(fn ($page) => $page->where('items', $inList));
});

it('task7_final_gap uses the actionable allowlist for control room work items', function () {
    $user = makeTasksUser(['controlRoom.viewAny']);
    $site = Site::factory()->create(['tenant_id' => $user->organization_id]);
    scopeAllTasksUserToSite($user, $site);
    $active = ControlRoomAlert::factory()->open()->create(['site_id' => $site->id]);
    ControlRoomAlert::factory()->resolved()->create(['site_id' => $site->id]);
    ControlRoomAlert::factory()->closed()->create(['site_id' => $site->id]);
    ControlRoomAlert::factory()->create([
        'site_id' => $site->id,
        'status' => ControlRoomAlert::STATUS_DISMISSED,
    ]);
    $legacy = ControlRoomAlert::factory()->open()->create(['site_id' => $site->id]);
    DB::table('control_room_alerts')->where('id', $legacy->id)->update(['status' => 'legacy_unknown']);

    $ids = collect((new TaskAggregator)->itemsFor($user, []))
        ->filter(fn ($item) => $item->source === 'alert')
        ->pluck('id')
        ->all();

    expect($ids)->toBe(['alert-'.$active->id]);
});

it('task7_spec_followup refuses to split a closed incident into new work', function () {
    $actor = makeTasksUser(['incidents.viewAny', 'incidents.followups.manage']);
    $assignee = makeTasksUser(['incidents.viewAny']);
    $incident = ClientIncident::factory()->create(['status' => 'closed']);

    $this->actingAs($actor)
        ->post("/tasks/incident/{$incident->id}/split", [
            'title' => 'Must not reopen work implicitly',
            'assignee_id' => $assignee->id,
        ])
        ->assertRedirect()
        ->assertSessionHas(
            'error',
            'Closed incidents cannot receive new follow-ups. Reopen the incident before creating more work.',
        );

    expect(IncidentFollowup::query()->where('client_incident_id', $incident->id)->count())->toBe(0)
        ->and($incident->fresh()->status)->toBe('closed')
        ->and($assignee->fresh()->notifications()->count())->toBe(0);
});

it('task7_spec_followup locks the incident before split followup creation and preserves the assignee fyi', function () {
    $actor = makeTasksUser(['incidents.viewAny', 'incidents.followups.manage']);
    $assignee = makeTasksUser(['incidents.viewAny']);
    $incident = ClientIncident::factory()->reviewed()->create();
    $baselineTransactionLevel = DB::transactionLevel();
    $queries = [];
    $captureQueries = true;
    DB::listen(function (QueryExecuted $query) use (&$captureQueries, &$queries): void {
        if (! $captureQueries) {
            return;
        }

        $queries[] = [
            'sql' => strtolower(str_replace(['`', '"'], '', $query->sql)),
            'bindings' => $query->bindings,
            'transaction_level' => DB::transactionLevel(),
        ];
    });

    $this->actingAs($actor)
        ->post("/tasks/incident/{$incident->id}/split", [
            'title' => 'Locked follow-up work',
            'assignee_id' => $assignee->id,
            'due_at' => now()->addDay()->toDateTimeString(),
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Child task created.');
    $captureQueries = false;

    $lockIndex = collect($queries)->search(
        fn (array $query): bool => str_contains($query['sql'], 'from client_incidents')
            && str_contains($query['sql'], 'for update')
            && in_array($incident->id, $query['bindings'], true),
    );
    $insertIndex = collect($queries)->search(
        fn (array $query): bool => str_starts_with($query['sql'], 'insert into incident_followups'),
    );
    $followup = IncidentFollowup::query()
        ->where('client_incident_id', $incident->id)
        ->first();
    $assignmentNotification = $assignee->fresh()->notifications()->latest()->first();

    expect($lockIndex)->not->toBeFalse()
        ->and($queries[$lockIndex]['transaction_level'])->toBeGreaterThan($baselineTransactionLevel)
        ->and($insertIndex)->toBeGreaterThan($lockIndex)
        ->and($queries[$insertIndex]['transaction_level'])->toBeGreaterThan($baselineTransactionLevel)
        ->and($followup)->not->toBeNull()
        ->and($followup?->assigned_to_user_id)->toBe($assignee->id)
        ->and(data_get($assignmentNotification?->data, 'event_key'))->toBe('tasks.assigned');
});

it('finds an item by its ticket number via q', function () {
    $user = makeTasksUser(['incidents.viewAny']);
    $incident = ClientIncident::factory()->create(['status' => 'submitted']);
    ClientIncident::factory()->create(['status' => 'submitted']);

    $this->actingAs($user)
        ->get('/tasks?q='.$incident->fresh()->reference_number)
        ->assertInertia(fn ($page) => $page
            ->where('items', fn ($items) => count($items) === 1
                && $items[0]['id'] === 'incident-'.$incident->id));
});

it('gates every provider on the module permission', function () {
    $aggregator = new TaskAggregator;

    $incidentOnly = makeTasksUser(['incidents.viewAny']);
    $sources = collect($aggregator->sourcesFor($incidentOnly))->pluck('key');

    expect($sources)->toContain('incident')
        ->and($sources)->toContain('followup')
        ->and($sources)->not->toContain('safeguarding')
        ->and($sources)->not->toContain('hr_case')
        ->and($sources)->not->toContain('alert');
});

it('filters to unassigned items', function () {
    $user = makeTasksUser(['incidents.viewAny']);
    $unassigned = ClientIncident::factory()->create(['status' => 'submitted', 'investigation_assigned_to' => null]);
    ClientIncident::factory()->create(['status' => 'submitted', 'investigation_assigned_to' => $user->id]);

    $this->actingAs($user)
        ->get('/tasks?assigned=unassigned')
        ->assertInertia(fn ($page) => $page
            ->where('items', fn ($items) => collect($items)->pluck('id')->all() === ['incident-'.$unassigned->id]));
});

it('filters to items due within the week', function () {
    $user = makeTasksUser(['incidents.viewAny']);
    $incident = ClientIncident::factory()->create(['status' => 'submitted']);
    $dueSoon = $incident->followups()->create([
        'assigned_to_user_id' => $user->id,
        'due_at' => now()->addDays(3),
        'notes' => 'Call whānau with outcome',
    ]);
    $incident->followups()->create([
        'assigned_to_user_id' => $user->id,
        'due_at' => now()->addDays(30),
        'notes' => 'Quarterly review',
    ]);

    $this->actingAs($user)
        ->get('/tasks?due=week')
        ->assertInertia(fn ($page) => $page
            ->where('items', fn ($items) => collect($items)->pluck('id')->all() === ['followup-'.$dueSoon->id]));
});

it('streams the filtered queue as csv', function () {
    $user = makeTasksUser(['incidents.viewAny']);
    $incident = ClientIncident::factory()->create(['status' => 'submitted']);

    $response = $this->actingAs($user)->get('/tasks?format=csv');

    $response->assertOk();
    expect((string) $response->headers->get('Content-Type'))->toContain('text/csv');
    expect($response->streamedContent())
        ->toContain('Ticket')
        ->toContain((string) $incident->fresh()->reference_number);
});

it('sorts overdue items first', function () {
    $user = makeTasksUser(['incidents.viewAny']);
    ClientIncident::factory()->create(['status' => 'submitted', 'severity' => 'high']);
    $overdueFollowupIncident = ClientIncident::factory()->create(['status' => 'submitted', 'severity' => 'low']);
    $overdueFollowupIncident->followups()->create([
        'assigned_to_user_id' => $user->id,
        'due_at' => now()->subDay(),
        'notes' => 'Chase GP report',
    ]);

    $items = (new TaskAggregator)->itemsFor($user, []);

    expect($items[0]->isOverdue())->toBeTrue();
});

it('assigns a control room alert under lock with history and audit in the same transaction', function () {
    $actor = makeTasksUser([
        'controlRoom.viewAny',
        'controlRoom.alerts.assign',
        'reports.viewAny',
    ]);
    $actor->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'admin')->value('id'),
    ]);

    $assignee = User::factory()->create([
        'role' => 'coordinator',
        'approved_at' => now(),
        'organization_id' => $actor->organization_id,
    ]);
    $assignee->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'coordinator')->value('id'),
    ]);

    $site = Site::factory()->create(['tenant_id' => $actor->organization_id]);
    $alert = ControlRoomAlert::factory()->open()->create([
        'site_id' => $site->id,
        'context' => ['existing_key' => 'preserved'],
    ]);
    $baselineTransactionLevel = DB::transactionLevel();
    $queries = [];
    $captureQueries = true;
    DB::listen(function (QueryExecuted $query) use (&$captureQueries, &$queries): void {
        if (! $captureQueries) {
            return;
        }

        $queries[] = [
            'sql' => strtolower(str_replace(['`', '"'], '', $query->sql)),
            'bindings' => $query->bindings,
            'transaction_level' => DB::transactionLevel(),
        ];
    });

    $this->actingAs($actor)
        ->post("/tasks/alert/{$alert->id}/assign", ['assignee_id' => $assignee->id])
        ->assertRedirect()
        ->assertSessionHas('success', 'Task assigned.');
    $captureQueries = false;

    $lockIndex = collect($queries)->search(
        fn (array $query): bool => str_contains($query['sql'], 'from control_room_alerts')
            && str_contains($query['sql'], 'for update'),
    );
    $eligibilityIndex = collect($queries)->search(
        fn (array $query, int $index): bool => $index > $lockIndex
            && str_contains($query['sql'], 'from users')
            && str_contains($query['sql'], 'role_user')
            && str_contains($query['sql'], 'for update')
            && in_array($assignee->id, $query['bindings'], true),
    );
    $unscopedAssigneeLockIndex = collect($queries)->search(
        fn (array $query, int $index): bool => $index > $lockIndex
            && str_contains($query['sql'], 'from users')
            && ! str_contains($query['sql'], 'role_user')
            && str_contains($query['sql'], 'for update')
            && in_array($assignee->id, $query['bindings'], true),
    );
    $updateIndex = collect($queries)->search(
        fn (array $query): bool => str_starts_with($query['sql'], 'update control_room_alerts'),
    );
    $auditIndex = collect($queries)->search(
        fn (array $query): bool => str_starts_with($query['sql'], 'insert into audit_logs'),
    );

    expect($lockIndex)->not->toBeFalse()
        ->and($queries[$lockIndex]['transaction_level'])->toBeGreaterThan($baselineTransactionLevel)
        ->and($eligibilityIndex)->not->toBeFalse()
        ->and($queries[$eligibilityIndex]['transaction_level'])->toBeGreaterThan($baselineTransactionLevel)
        ->and($unscopedAssigneeLockIndex)->toBeFalse()
        ->and($updateIndex)->toBeGreaterThan($lockIndex)
        ->and($queries[$updateIndex]['transaction_level'])->toBeGreaterThan($baselineTransactionLevel)
        ->and($auditIndex)->toBeGreaterThan($updateIndex)
        ->and($queries[$auditIndex]['transaction_level'])->toBeGreaterThan($baselineTransactionLevel);

    $alert->refresh();
    expect($alert->assigned_to_user_id)->toBe($assignee->id)
        ->and($alert->context['existing_key'] ?? null)->toBe('preserved')
        ->and(data_get(collect($alert->context['assignment_history'] ?? [])->last(), 'action'))->toBe('assigned')
        ->and(data_get(collect($alert->context['assignment_history'] ?? [])->last(), 'to_user_id'))->toBe($assignee->id);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'controlRoom.alert.assign',
        'auditable_id' => $alert->id,
    ]);
});

it('rejects a control room task assignment when cached preflight site access was revoked before the transaction', function () {
    $actor = makeTasksUser([
        'controlRoom.viewAny',
        'controlRoom.alerts.assign',
    ]);
    $actor->forceFill(['role' => 'coordinator'])->save();
    $actor->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'coordinator')->value('id'),
    ]);
    $assignee = User::factory()->create([
        'role' => 'coordinator',
        'approved_at' => now(),
        'organization_id' => $actor->organization_id,
    ]);
    $assignee->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'coordinator')->value('id'),
    ]);
    $authorizedSite = Site::factory()->create(['tenant_id' => $actor->organization_id]);
    $revokedToSite = Site::factory()->create(['tenant_id' => $actor->organization_id]);
    scopeAllTasksUserToSite($actor, $authorizedSite);
    scopeAllTasksUserToSite($assignee, $authorizedSite);

    $actor->load('hrEmployeeProfile');
    HrEmployeeProfile::query()
        ->where('user_id', $actor->id)
        ->update([
            'primary_site_id' => $revokedToSite->id,
            'secondary_site_ids' => [],
        ]);

    $alert = ControlRoomAlert::factory()->open()->create([
        'site_id' => $authorizedSite->id,
    ]);

    $this->actingAs($actor)
        ->post("/tasks/alert/{$alert->id}/assign", ['assignee_id' => $assignee->id])
        ->assertRedirect()
        ->assertSessionHas('error', 'Alert not found or outside your site access.');

    expect($alert->fresh()->assigned_to_user_id)->toBeNull()
        ->and($alert->fresh()->context['assignment_history'] ?? [])->toBe([]);
});

it('rolls back a control room task assignment when strict audit writing fails', function () {
    $actor = makeTasksUser([
        'controlRoom.viewAny',
        'controlRoom.alerts.assign',
        'reports.viewAny',
    ]);
    $actor->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'admin')->value('id'),
    ]);
    $assignee = User::factory()->create([
        'role' => 'coordinator',
        'approved_at' => now(),
        'organization_id' => $actor->organization_id,
    ]);
    $assignee->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'coordinator')->value('id'),
    ]);
    $site = Site::factory()->create(['tenant_id' => $actor->organization_id]);
    $alert = ControlRoomAlert::factory()->open()->create(['site_id' => $site->id]);
    $eventName = 'eloquent.creating: '.AuditLog::class;
    Event::listen($eventName, static function (): never {
        throw new RuntimeException('Simulated strict audit write failure.');
    });
    $caught = null;

    $this->withoutExceptionHandling();
    try {
        $this->actingAs($actor)
            ->post("/tasks/alert/{$alert->id}/assign", ['assignee_id' => $assignee->id]);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    } finally {
        $this->withExceptionHandling();
        Event::forget($eventName);
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught?->getMessage())->toBe('Simulated strict audit write failure.')
        ->and($alert->fresh()->assigned_to_user_id)->toBeNull()
        ->and($alert->fresh()->context['assignment_history'] ?? [])->toBe([]);
    $this->assertDatabaseMissing('audit_logs', [
        'action' => 'controlRoom.alert.assign',
        'auditable_id' => $alert->id,
    ]);
});
