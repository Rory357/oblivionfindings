<?php

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoomAlert;
use App\Models\IncidentFollowup;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);
    Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'Pacific/Auckland')->utc());

    $this->worker = User::factory()->frontlineWorker()->create();
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->worker, $this->site);
    $this->client = Client::factory()->create([
        'site_id' => $this->site->id,
        'first_name' => 'Mere',
        'last_name' => 'Wilson',
    ]);
    $this->client->supportWorkers()->attach($this->worker->id);
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'clients.viewAssigned'],
        ['description' => 'clients.viewAssigned'],
    );
    $this->worker->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('adds the finite detail return only to the existing own recent non-closed incident cohort', function () {
    $attributes = [
        'client_id' => $this->client->id,
        'site_id' => $this->site->id,
        'reported_by' => $this->worker->id,
        'status' => 'draft',
        'source' => 'manual',
    ];
    $recent = ClientIncident::factory()->create([
        ...$attributes,
        'title' => 'Recent own incident',
        'description' => 'Own recorded summary',
        'severity' => 'high',
        'requires_followup' => true,
        'occurred_at' => now()->subMinutes(10),
    ]);
    $boundary = ClientIncident::factory()->create([
        ...$attributes,
        'occurred_at' => now()->subDays(14),
    ]);
    ClientIncident::factory()->create([
        ...$attributes,
        'occurred_at' => now()->subDays(14)->subSecond(),
    ]);
    ClientIncident::factory()->create([
        ...$attributes,
        'status' => 'closed',
        'occurred_at' => now()->subMinute(),
    ]);
    ClientIncident::factory()->create([
        ...$attributes,
        'reported_by' => User::factory()->frontlineWorker()->create()->id,
        'occurred_at' => now()->subMinute(),
    ]);

    // The read link never relays an arbitrary incoming return destination.
    $this->actingAs($this->worker)
        ->get('/my-day?return_to=%2Foperations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->has('incidents', 2)
            ->where('incidents.0.id', $recent->id)
            ->where('incidents.0.url', '/incidents/'.$recent->id.'?return_to=%2Fmy-day')
            ->where('incidents.0.title', 'Recent own incident')
            ->where('incidents.0.description', 'Own recorded summary')
            ->where('incidents.0.client_name', 'Mere Wilson')
            ->where('incidents.0.severity', 'high')
            ->where('incidents.0.status', 'draft')
            ->where('incidents.0.requires_followup', true)
            ->where('incidents.0.occurred_at', $recent->occurred_at->toIso8601String())
            ->where('incidents.1.id', $boundary->id)
            ->where('incidents.1.url', '/incidents/'.$boundary->id.'?return_to=%2Fmy-day')
            ->where('stats.incidents_open', 2)
        );
});

it('links the assigned unfinished follow-up to its incident without borrowing the recent-reporter filter', function () {
    $other = User::factory()->frontlineWorker()->create();
    $incident = ClientIncident::factory()->create([
        'client_id' => $this->client->id,
        'site_id' => $this->site->id,
        'reported_by' => $other->id,
        'status' => 'draft',
        'source' => 'manual',
        'occurred_at' => now()->subDays(30),
        'title' => 'Older incident reported by another worker',
        'severity' => 'medium',
    ]);
    // Choose a real persisted follow-up identity distinct from its incident ID.
    $followupId = max((int) IncidentFollowup::query()->max('id'), (int) $incident->id) + 1;
    $followup = IncidentFollowup::factory()->create([
        'id' => $followupId,
        'client_incident_id' => $incident->id,
        'assigned_to_user_id' => $this->worker->id,
        'created_by' => $other->id,
        'completed_at' => null,
        'notes' => 'Call the recorded contact',
        'due_at' => now()->addHour(),
    ]);
    IncidentFollowup::factory()->completed()->create([
        'client_incident_id' => $incident->id,
        'assigned_to_user_id' => $this->worker->id,
        'created_by' => $other->id,
    ]);
    IncidentFollowup::factory()->create([
        'client_incident_id' => $incident->id,
        'assigned_to_user_id' => $other->id,
        'created_by' => $other->id,
        'completed_at' => null,
    ]);

    expect($followup->id)->not->toBe($incident->id);

    $this->actingAs($this->worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->has('incidents', 0)
            ->has('tasks', 1)
            ->where('tasks.0.id', 'followup-'.$followup->id)
            ->where('tasks.0.type', 'followup')
            ->where('tasks.0.source_url', '/incidents/'.$incident->id.'?return_to=%2Fmy-day')
            ->where('tasks.0.title', 'Incident follow-up: Older incident reported by another worker')
            ->where('tasks.0.description', 'Call the recorded contact')
            ->where('tasks.0.priority', 'medium')
            ->where('tasks.0.status', 'pending')
            ->where('tasks.0.due_at', $followup->due_at->toIso8601String())
            ->where('tasks.0.meta.client_name', 'Mere Wilson')
        );
});

it('leaves both existing non-incident note follow-up destinations unchanged', function () {
    $alert = ControlRoomAlert::factory()->create([
        'site_id' => $this->site->id,
        'status' => 'closed',
        'source' => 'manual',
        'alert_type' => 'Recorded note context',
        'severity' => 'low',
    ]);
    $linked = OperatorNote::query()->create([
        'alert_id' => $alert->id,
        'user_id' => $this->worker->id,
        'type' => OperatorNote::TYPE_NOTE,
        'content' => 'Follow up on this recorded alert',
        'requires_followup' => true,
        'followup_at' => now()->addHour(),
    ]);
    $unlinked = OperatorNote::query()->create([
        'user_id' => $this->worker->id,
        'type' => OperatorNote::TYPE_NOTE,
        'content' => 'Follow up on the shift note',
        'requires_followup' => true,
        'followup_at' => now()->addHours(2),
    ]);

    $this->actingAs($this->worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->has('tasks', 2)
            ->where('tasks.0.id', 'note-'.$linked->id)
            ->where('tasks.0.source_url', '/control-room/alerts/'.$alert->id)
            ->where('tasks.1.id', 'note-'.$unlinked->id)
            ->where('tasks.1.source_url', '/control-room/shifts')
        );
});
