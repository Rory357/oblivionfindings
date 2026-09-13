<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\HandoverWorkerNotes;
use App\Services\ShiftHandoverService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->worker = User::factory()->frontlineWorker()->create();
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->worker->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null,
    ]);
    foreach (['shifts.viewAssigned', 'clients.viewAssigned', 'handovers.create', 'timesheets.create'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $this->worker->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    $this->people = collect(['Mere', 'James', 'Casey'])->map(function ($name) {
        $client = Client::factory()->create(['first_name' => $name, 'site_id' => $this->site->id, 'status' => 'active']);
        $client->supportWorkers()->attach($this->worker->id);

        return $client;
    });
    $this->shift = Shift::factory()->published()->create([
        'user_id' => $this->worker->id, 'client_id' => $this->people[0]->id, 'site_id' => $this->site->id,
        'status' => 'in_progress', 'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(4),
        'actual_starts_at' => now()->subHours(2),
    ]);
    $this->input = [
        'shift_id' => $this->shift->id, 'meds_completed' => true, 'shift_rating' => null,
        'handover_notes' => '', 'follow_up_needed' => false,
        'worker_notes' => ['shared_notes' => 'Towels are in the cupboard.', 'people' => $this->people->map(fn ($client) => [
            'client_id' => $client->id, 'notes' => $client->first_name.' individual note.',
            'no_updates' => false, 'follow_up_needed' => $client->first_name === 'James',
        ])->all()],
    ];
    $this->actingAs($this->worker);
});

it('saves three person sections without filing other people under the primary client', function () {
    $this->shift->tasks()->create(['label' => 'Private Casey task detail', 'client_id' => $this->people[2]->id, 'task_scope' => 'client', 'is_completed' => false]);
    $this->post('/attendance/handover', $this->input)->assertSessionHasNoErrors()->assertRedirect();
    $handover = ShiftHandover::where('outgoing_shift_id', $this->shift->id)->sole();
    expect($handover->handover_notes)->toBe('Mere individual note.')
        ->and($handover->worker_notes['people'])->toHaveCount(3)
        ->and($handover->worker_notes['people'][1]['follow_up_needed'])->toBeTrue()
        ->and($handover->toArray())->not->toHaveKey('worker_notes');
    expect(DB::table('shift_handovers')->where('id', $handover->id)->value('worker_notes'))->not->toContain('James individual note');
    expect(json_encode($handover->tasks_pending))->not->toContain('Private Casey task detail');
    $this->get('/operations/handovers')->assertOk()->assertInertia(fn ($page) => $page
        ->where('handovers.0.id', $handover->id)->has('handovers.0.worker_notes.people', 3));
    $this->getJson("/attendance/shifts/{$this->shift->id}/handover-draft")
        ->assertOk()->assertJsonCount(3, 'people')->assertJsonCount(3, 'worker_notes.people')
        ->assertJsonPath('status', 'draft')->assertJsonPath('expected_version', $handover->version);
    $this->get('/my-day')->assertOk()->assertInertia(fn ($page) => $page
        ->where('handover_draft.id', $handover->id)
        ->where('handover_draft.review_url', fn ($url) => str_contains($url, 'handover='.$handover->id)));
});

it('rejects a private or foreign person and rolls back the whole handover', function (bool $foreign) {
    $person = Client::factory()->create(['site_id' => $foreign ? Site::factory()->create()->id : $this->site->id]);
    if ($foreign) {
        $person->supportWorkers()->attach($this->worker->id);
    }
    $this->input['worker_notes']['people'][1]['client_id'] = $person->id;
    $this->post('/attendance/handover', $this->input)->assertNotFound();
    expect(ShiftHandover::where('outgoing_shift_id', $this->shift->id)->exists())->toBeFalse();
})->with([false, true]);

it('rejects duplicate person sections', function () {
    $this->input['worker_notes']['people'][1]['client_id'] = $this->people[0]->id;
    $this->post('/attendance/handover', $this->input)->assertSessionHasErrors();
    expect(ShiftHandover::where('outgoing_shift_id', $this->shift->id)->exists())->toBeFalse();
});

it('reloads the saved draft and protects it from stale edits', function () {
    $this->post('/attendance/handover', $this->input)->assertSessionHasNoErrors();
    $handover = ShiftHandover::where('outgoing_shift_id', $this->shift->id)->sole();
    $this->input['expected_version'] = $handover->version;
    $this->input['worker_notes']['people'][1]['notes'] = 'Updated James note.';
    $this->post('/attendance/handover', $this->input)->assertSessionHasNoErrors();
    $this->input['worker_notes']['people'][1]['notes'] = 'Stale James note.';
    $this->post('/attendance/handover', $this->input)->assertSessionHasErrors('handover');
    expect($handover->fresh()->worker_notes['people'][1]['notes'])->toBe('Updated James note.');
});

it('filters each read by current person privacy and preserves sections hidden by a privacy change', function () {
    $this->post('/attendance/handover', $this->input)->assertSessionHasNoErrors();
    $handover = ShiftHandover::where('outgoing_shift_id', $this->shift->id)->sole();
    $this->people[1]->supportWorkers()->detach($this->worker->id);
    $this->actingAs($this->worker->fresh())->getJson("/attendance/shifts/{$this->shift->id}/handover-draft")
        ->assertOk()->assertJsonCount(2, 'people')->assertJsonCount(2, 'worker_notes.people')
        ->assertDontSee('James individual note');
    $this->input['expected_version'] = $handover->version;
    unset($this->input['worker_notes']['people'][1]);
    $this->input['worker_notes']['people'] = array_values($this->input['worker_notes']['people']);
    $this->post('/attendance/handover', $this->input)->assertSessionHasNoErrors();
    expect($handover->fresh()->worker_notes['people'])->toHaveCount(3);
    $presented = app(HandoverWorkerNotes::class)->present($handover->fresh(), $this->worker->fresh());
    expect(array_column($presented['people'], 'client_id'))->not->toContain($this->people[1]->id);
});

it('keeps attendance open when a clock-out note includes an inaccessible person', function () {
    $session = HrAttendanceSession::create([
        'user_id' => $this->worker->id, 'shift_id' => $this->shift->id, 'site_id' => $this->site->id,
        'clock_in_at' => now()->subHours(2), 'status' => 'open', 'source' => 'web', 'created_by' => $this->worker->id,
    ]);
    $this->people[1]->supportWorkers()->detach($this->worker->id);
    $this->actingAs($this->worker->fresh())->post('/attendance/clock-out', [
        'session_id' => $session->id, 'break_minutes' => 0, 'force' => true,
        'override_reason' => 'Team leader will review the outgoing handover.', 'handover' => $this->input,
    ])->assertNotFound();
    expect($session->fresh()->clock_out_at)->toBeNull()
        ->and(ShiftHandover::where('outgoing_shift_id', $this->shift->id)->exists())->toBeFalse();
});

it('shows separate notes to the incoming worker using each persons current privacy', function () {
    $this->post('/attendance/handover', $this->input)->assertSessionHasNoErrors();
    $handover = ShiftHandover::where('outgoing_shift_id', $this->shift->id)->sole();
    $incomingWorker = User::factory()->frontlineWorker()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $incomingWorker->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null,
    ]);
    foreach (['shifts.viewAssigned', 'clients.viewAssigned'] as $key) {
        $incomingWorker->permissionOverrides()->attach(Permission::where('key', $key)->value('id'), ['allowed' => true]);
    }
    $this->people[0]->supportWorkers()->attach($incomingWorker->id);
    $this->people[1]->supportWorkers()->attach($incomingWorker->id);
    $this->shift->update(['ends_at' => now()->subMinute(), 'actual_ends_at' => now()->subMinute(), 'status' => 'completed']);
    $incoming = Shift::factory()->published()->create([
        'user_id' => $incomingWorker->id, 'client_id' => $this->people[0]->id, 'site_id' => $this->site->id,
        'service_context_id' => $this->shift->service_context_id,
        'starts_at' => now()->subMinute(), 'ends_at' => now()->addHours(6), 'status' => 'in_progress',
    ]);
    $handover->update(['incoming_shift_id' => $incoming->id, 'incoming_staff_id' => $incomingWorker->id]);
    app(ShiftHandoverService::class)->submit($handover, $this->worker->fresh());
    $this->actingAs($incomingWorker)->get('/my-day')->assertOk()->assertInertia(fn ($page) => $page
        ->component('my-day/index')->where('handover.id', $handover->id)
        ->has('handover.worker_notes.people', 2)
        ->where('handover.worker_notes.people.1.notes', 'James individual note.'))
        ->assertDontSee('Casey individual note.');
});

it('does not clear medication evidence when saving the person note form', function () {
    $this->post('/attendance/handover', $this->input)->assertSessionHasNoErrors();
    $handover = ShiftHandover::where('outgoing_shift_id', $this->shift->id)->sole();
    $handover->update(['medications_due' => [['label' => 'Existing governed medication follow-up']]]);
    foreach (['medications.controlled.view', 'medications.controlled.record'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $this->worker->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    $this->input['expected_version'] = $handover->version;
    $this->actingAs($this->worker->fresh())->post('/attendance/handover', $this->input)->assertSessionHasNoErrors();
    expect($handover->fresh()->medications_due)->toBe([['label' => 'Existing governed medication follow-up']]);
});

it('saves every named section through the finish-shift workflow', function () {
    $session = HrAttendanceSession::create([
        'user_id' => $this->worker->id, 'shift_id' => $this->shift->id, 'site_id' => $this->site->id,
        'clock_in_at' => now()->subHours(2), 'status' => 'open', 'source' => 'web', 'created_by' => $this->worker->id,
    ]);
    $this->post('/attendance/clock-out', [
        'session_id' => $session->id, 'break_minutes' => 0, 'force' => true,
        'override_reason' => 'Team leader will review the outgoing handover.', 'handover' => $this->input,
    ])->assertSessionHasNoErrors();
    expect($session->fresh()->clock_out_at)->not->toBeNull();
    $handover = ShiftHandover::where('outgoing_shift_id', $this->shift->id)->sole();
    expect($handover->worker_notes['people'])->toHaveCount(3)->and($handover->status)->toBe('draft');
    $this->get('/my-day')->assertOk()->assertInertia(fn ($page) => $page->where('handover_draft.id', $handover->id));
});

it('returns the committed private draft version for autosave and rejects a stale JSON write', function () {
    $saved = $this->postJson('/attendance/handover', $this->input)->assertOk()
        ->assertJsonPath('status', 'draft')->assertJsonStructure(['handover_id', 'expected_version', 'saved_at', 'review_url']);
    $record = ShiftHandover::findOrFail($saved->json('handover_id'));
    expect($saved->json('expected_version'))->toBe($record->version)
        ->and($record->submitted_at)->toBeNull();
    $this->input['expected_version'] = $record->version;
    $this->input['worker_notes']['people'][1]['notes'] = 'Newest individual note.';
    $this->postJson('/attendance/handover', $this->input)->assertOk();
    $this->input['worker_notes']['people'][1]['notes'] = 'Stale content';
    $this->postJson('/attendance/handover', $this->input)->assertUnprocessable()->assertJsonValidationErrors('handover');
    expect($record->fresh()->worker_notes['people'][1]['notes'])->toBe('Newest individual note.');
});

it('records an explicit unsupported person and presents privacy-filtered progress without note contents', function () {
    $this->input['worker_notes']['people'][0] = [
        'client_id' => $this->people[0]->id, 'notes' => '', 'no_updates' => false,
        'follow_up_needed' => false, 'not_supported' => true,
    ];
    array_pop($this->input['worker_notes']['people']);
    $saved = $this->postJson('/attendance/handover', $this->input)->assertOk();
    expect(ShiftHandover::findOrFail($saved->json('handover_id'))->handover_notes)->toBe('Did not support this person this shift.');
    $summary = app(HandoverWorkerNotes::class)->shiftSummary($this->shift, $this->worker);
    expect(array_column($summary['people'], 'state', 'id'))->toEqual([
        $this->people[0]->id => 'not_supported', $this->people[1]->id => 'recorded', $this->people[2]->id => 'not_started',
    ])
        ->and(json_encode($summary))->not->toContain('James individual note.');
    $this->people[1]->supportWorkers()->detach($this->worker->id);
    $summary = app(HandoverWorkerNotes::class)->shiftSummary($this->shift, $this->worker->fresh());
    expect(array_column($summary['people'], 'id'))->not->toContain($this->people[1]->id);
});

it('does not accept contradictory support status or erase its saved notes', function () {
    $this->postJson('/attendance/handover', $this->input)->assertOk();
    $record = ShiftHandover::where('outgoing_shift_id', $this->shift->id)->sole();
    $this->input['expected_version'] = $record->version;
    $this->input['worker_notes']['people'][0]['not_supported'] = true;
    $this->postJson('/attendance/handover', $this->input)->assertUnprocessable();
    expect($record->fresh()->worker_notes['people'][0]['notes'])->toBe('Mere individual note.');
});
