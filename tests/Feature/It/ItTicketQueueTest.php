<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

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
    $this->site = Site::factory()->create();
    $this->hr = itQueueUser('hr');
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
});

test('created tickets are stamped with sequential application references and a created event', function () {
    $this->actingAs($this->hr)->post('/it/tickets', [
        'title' => 'First ticket',
        'category' => 'other',
        'priority' => 'low',
        'site_id' => $this->site->id,
    ])->assertRedirect();

    $this->actingAs($this->hr)->post('/it/tickets', [
        'title' => 'Second ticket',
        'category' => 'other',
        'priority' => 'low',
        'site_id' => $this->site->id,
    ])->assertRedirect();

    $first = ItTicket::query()->firstWhere('title', 'First ticket');
    $second = ItTicket::query()->firstWhere('title', 'Second ticket');

    expect($first->reference)->toBe('IT-000001');
    expect($second->reference)->toBe('IT-000002');
    expect($first->source)->toBe('agent');

    // Every create writes a `created` row on the activity trail.
    expect($first->events()->where('type', 'created')->count())->toBe(1);

    // The application sequence raises its global floor above imported/manual
    // references before allocating under the shared row lock.
    ItTicket::factory()->create(['reference' => 'IT-000500']);
    expect(ItTicket::nextReference())->toBe('IT-000501');
    expect(DB::table('reference_sequences')->where('scope', 'IT')->value('next_value'))->toBe(502);

    // Factory creates (no explicit reference) get one from the hook too.
    $fromFactory = ItTicket::factory()->create();
    expect($fromFactory->reference)->not->toBeNull();
});

test('the tickets queue paginates server-side', function () {
    ItTicket::factory()->count(20)->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->hr->id,
    ]);

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

    ItTicket::factory()->create(['site_id' => $this->site->id, 'title' => 'Unassigned open', 'requester_user_id' => $other->id]);
    ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'title' => 'Mine in progress',
        'requester_user_id' => $other->id,
        'assigned_to_user_id' => $me->id,
        'status' => 'in_progress',
    ]);
    ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'title' => 'Waiting on requester',
        'requester_user_id' => $other->id,
        'assigned_to_user_id' => $other->id,
        'status' => 'waiting',
    ]);
    ItTicket::factory()->resolved()->create([
        'site_id' => $this->site->id,
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
    ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $other->id]);
    ItTicket::factory()->urgent()->create(['site_id' => $this->site->id, 'requester_user_id' => $other->id]);
    ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $other->id,
        'assigned_to_user_id' => $me->id,
        'status' => 'in_progress',
    ]);
    ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $me->id, 'status' => 'waiting']);
    ItTicket::factory()->resolved()->create(['site_id' => $this->site->id, 'requester_user_id' => $me->id]);

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
    ItTicket::factory()->resolved()->create(['site_id' => $this->site->id, 'sla_state' => 'met']);
    ItTicket::factory()->resolved()->create(['site_id' => $this->site->id, 'sla_state' => 'breached']);
    // An older met settlement (>30d) is outside the window and must not count.
    ItTicket::factory()->resolved()->create([
        'sla_state' => 'met',
        'site_id' => $this->site->id,
        'resolved_at' => now()->subDays(40),
    ]);

    $this->actingAs($this->hr)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.tickets.resolved_30d', 2)
            ->where('summary.tickets.met_30d', 1));
});

test('the overview board serves agents needs-attention lanes and hides from requesters', function () {
    // A breached open ticket → SLA lane (normal priority, unassigned).
    ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'open', 'sla_state' => 'breached']);
    // An unassigned urgent open ticket, no first response → awaiting + urgent chip.
    ItTicket::factory()->urgent()->create(['site_id' => $this->site->id, 'status' => 'open']);
    // A responded ticket — 60 minutes to first reply — feeds the avg.
    ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'created_at' => now()->subMinutes(120),
        'first_responded_at' => now()->subMinutes(60),
    ]);

    $this->actingAs($this->hr)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('overview')
            ->where('overview.avg_first_response_mins', 60)
            ->has('overview.sla_lane', 1)
            ->where('overview.sla_lane.0.sla_state', 'breached')
            ->where('overview.unassigned_by_priority.urgent', 1));

    // The self-service payload never carries the agent overview board.
    $worker = itQueueUser('support_worker');
    $this->actingAs($worker)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('overview'));
});

test('the overview activity feed surfaces recent ticket events for agents', function () {
    // Raise a ticket through the real write path → a `created` event lands.
    $this->actingAs($this->hr)
        ->post('/it/tickets', [
            'title' => 'Feed fixture',
            'category' => 'other',
            'priority' => 'normal',
            'site_id' => $this->site->id,
        ])
        ->assertRedirect();

    $this->actingAs($this->hr)
        ->get('/it')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('overview.recent_activity', 1)
            ->where('overview.recent_activity.0.type', 'created')
            ->where('overview.recent_activity.0.actor', $this->hr->name)
            ->where('overview.recent_activity.0.reference', 'IT-000001'));
});
