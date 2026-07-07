<?php

use App\Models\ItTicket;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function itQueueUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->hr = itQueueUser('hr');
});

test('created tickets are stamped with sequential per-tenant references and a created event', function () {
    $this->actingAs($this->hr)->post('/it/tickets', [
        'title' => 'First ticket',
        'category' => 'other',
        'priority' => 'low',
    ])->assertRedirect();

    $this->actingAs($this->hr)->post('/it/tickets', [
        'title' => 'Second ticket',
        'category' => 'other',
        'priority' => 'low',
    ])->assertRedirect();

    $first = ItTicket::query()->firstWhere('title', 'First ticket');
    $second = ItTicket::query()->firstWhere('title', 'Second ticket');

    expect($first->reference)->toBe('IT-000001');
    expect($second->reference)->toBe('IT-000002');
    expect($first->source)->toBe('agent');

    // Every create writes a `created` row on the activity trail.
    expect($first->events()->where('type', 'created')->count())->toBe(1);

    // The generator is max-based, so gaps and manual references never
    // produce collisions.
    ItTicket::factory()->create(['reference' => 'IT-000500']);
    expect(ItTicket::nextReference(1))->toBe('IT-000501');

    // Factory creates (no explicit reference) get one from the hook too.
    $fromFactory = ItTicket::factory()->create();
    expect($fromFactory->reference)->not->toBeNull();
});

test('the tickets queue paginates server-side', function () {
    ItTicket::factory()->count(20)->create(['requester_user_id' => $this->hr->id]);

    $this->actingAs($this->hr)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tickets.data', 15)
            ->where('tickets.total', 20)
            ->where('tickets.last_page', 2)
            ->has('tickets.links'));

    $this->actingAs($this->hr)
        ->get('/it?tickets_page=2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tickets.data', 5)
            ->where('tickets.current_page', 2));
});

test('saved views filter the queue server-side', function () {
    $me = $this->hr;
    $other = User::factory()->create();

    ItTicket::factory()->create(['title' => 'Unassigned open', 'requester_user_id' => $other->id]);
    ItTicket::factory()->create([
        'title' => 'Mine in progress',
        'requester_user_id' => $other->id,
        'assigned_to_user_id' => $me->id,
        'status' => 'in_progress',
    ]);
    ItTicket::factory()->create([
        'title' => 'Waiting on requester',
        'requester_user_id' => $other->id,
        'assigned_to_user_id' => $other->id,
        'status' => 'waiting',
    ]);
    ItTicket::factory()->resolved()->create([
        'title' => 'Fresh resolve',
        'requester_user_id' => $other->id,
    ]);

    $assertOnly = function (string $view, string $expectedTitle) {
        $this->actingAs($this->hr)
            ->get("/it?view={$view}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.title', $expectedTitle)
                ->where('filters.view', $view));
    };

    $assertOnly('unassigned', 'Unassigned open');
    // 'Waiting on requester' is unassigned too — narrow with the mine view.
    $assertOnly('mine', 'Mine in progress');
    $assertOnly('waiting', 'Waiting on requester');
    $assertOnly('recently_resolved', 'Fresh resolve');

    // Unknown views are dropped, not applied.
    $this->actingAs($this->hr)
        ->get('/it?view=everything')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.view', null));
});

test('the summary counts the whole table, not the page', function () {
    $me = $this->hr;
    $other = User::factory()->create();

    // 2 unassigned open (1 urgent) + 1 mine in_progress + 1 waiting + 1 resolved now.
    ItTicket::factory()->create(['requester_user_id' => $other->id]);
    ItTicket::factory()->urgent()->create(['requester_user_id' => $other->id]);
    ItTicket::factory()->create([
        'requester_user_id' => $other->id,
        'assigned_to_user_id' => $me->id,
        'status' => 'in_progress',
    ]);
    ItTicket::factory()->create(['requester_user_id' => $me->id, 'status' => 'waiting']);
    ItTicket::factory()->resolved()->create(['requester_user_id' => $me->id]);

    $this->actingAs($this->hr)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.tickets.open', 4)
            ->where('summary.tickets.unassigned', 3) // 2 open + the waiting one
            ->where('summary.tickets.urgent_unassigned', 1)
            ->where('summary.tickets.waiting', 1)
            ->where('summary.tickets.resolved_30d', 1)
            ->where('summary.tickets.views.mine', 1)
            ->where('summary.tickets.views.recently_resolved', 1)
            ->where('summary.tickets.by_status.in_progress', 1)
            // The viewer's own numbers ride along for the My tickets tab.
            ->where('summary.my.open', 1)
            ->where('summary.my.waiting', 1)
            ->where('summary.my.resolved_30d', 1));
});

test('the summary counts SLA-met settlements for the hero compliance ring', function () {
    // Two tickets settled this month — one met its SLA target, one breached.
    ItTicket::factory()->resolved()->create(['sla_state' => 'met']);
    ItTicket::factory()->resolved()->create(['sla_state' => 'breached']);
    // An older met settlement (>30d) is outside the window and must not count.
    ItTicket::factory()->resolved()->create([
        'sla_state' => 'met',
        'resolved_at' => now()->subDays(40),
    ]);

    $this->actingAs($this->hr)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.tickets.resolved_30d', 2)
            ->where('summary.tickets.met_30d', 1));
});
