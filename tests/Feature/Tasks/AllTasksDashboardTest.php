<?php

use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\User;
use App\Services\Tasks\TaskAggregator;
use Database\Seeders\RbacSeeder;

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
