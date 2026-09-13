<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\ShiftHandoverService;
use App\Support\ShiftTaskSupport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-12 10:30', 'Pacific/Auckland')->utc());
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->worker = User::factory()->frontlineWorker()->create();
    $this->outgoingWorker = User::factory()->frontlineWorker()->create();
    HrEmployeeProfile::factory()->create(['user_id' => $this->worker->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null]);
    HrEmployeeProfile::factory()->create(['user_id' => $this->outgoingWorker->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null]);
    foreach (['shifts.tasks.createSelf', 'shifts.viewAssigned', 'clients.viewAssigned'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'shifts', 'module' => 'Operations']);
        $this->worker->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    }
    $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
    $this->client->supportWorkers()->attach($this->worker->id);
    $this->outgoing = Shift::factory()->published()->create(['user_id' => $this->outgoingWorker->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
        'starts_at' => now()->subHours(5), 'ends_at' => now()->subHour(), 'actual_starts_at' => now()->subHours(5), 'actual_ends_at' => now()->subHour(), 'status' => 'completed']);
    $this->incoming = Shift::factory()->published()->create(['user_id' => $this->worker->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
        'service_context_id' => $this->outgoing->service_context_id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3), 'status' => 'in_progress']);
    $this->source = $this->outgoing->tasks()->create(['label' => 'Prepare tomorrow’s activity', 'client_id' => $this->client->id, 'task_scope' => 'client', 'is_completed' => false,
        'steps' => [['id' => (string) Str::uuid(), 'label' => 'Pack materials', 'is_completed' => false], ['id' => (string) Str::uuid(), 'label' => 'Choose activity', 'is_completed' => true]]]);
    $this->handover = ShiftHandover::create(['outgoing_shift_id' => $this->outgoing->id, 'incoming_shift_id' => $this->incoming->id,
        'client_id' => $this->client->id, 'outgoing_staff_id' => $this->outgoingWorker->id, 'incoming_staff_id' => $this->worker->id,
        'status' => 'submitted', 'submitted_at' => now()->subHour(), 'handover_notes' => 'Please follow up the activity.',
        'tasks_pending' => [['id' => $this->source->id, 'label' => $this->source->label]], 'follow_up_items' => [['label' => 'Check the activity time']]]);
    $this->actingAs($this->worker);
    $this->key = hash('sha256', 'tasks_pending:0');
});

it('creates a linked follow-up once and copies only remaining steps without marking handover read', function () {
    $id = $this->postJson("/my-day/handovers/{$this->handover->id}/follow-ups", ['item_key' => $this->key])->assertCreated()
        ->assertJsonPath('task.source_handover_id', $this->handover->id)->assertJsonPath('task.source_label', 'Handover follow-up')
        ->assertJsonCount(1, 'task.steps')->assertJsonPath('task.steps.0.label', 'Pack materials')->json('task.id');
    $this->postJson("/my-day/handovers/{$this->handover->id}/follow-ups", ['item_key' => $this->key])->assertOk()->assertJsonPath('task.id', $id);
    expect($this->incoming->tasks()->count())->toBe(1)->and($this->handover->fresh()->status)->toBe('submitted')->and($this->source->fresh()->is_completed)->toBeFalse();
    $rows = app(ShiftHandoverService::class)->myDayFollowUps($this->handover->fresh(), $this->worker);
    expect($rows[0]['task_id'])->toBe($id)->and($rows[1]['task_id'])->toBeNull();
    ShiftTaskSupport::syncForShift($this->incoming, []);
    expect($this->incoming->tasks()->whereKey($id)->exists())->toBeTrue();
});

it('still offers follow-ups after the handover is acknowledged', function () {
    $this->handover->update(['status' => 'acknowledged', 'acknowledged_by' => $this->worker->id, 'acknowledged_at' => now()]);
    $this->postJson("/my-day/handovers/{$this->handover->id}/follow-ups", ['item_key' => $this->key])->assertCreated();
});

it('shows linked work in My Day while applying the canonical medication snapshot privacy rule', function () {
    $this->handover->update(['medications_due' => [['label' => 'Private controlled medication detail']]]);
    $this->get('/my-day')->assertOk()->assertInertia(fn ($page) => $page->component('my-day/index')
        ->has('handover.follow_ups', 2)->where('handover.flags', fn ($flags) => ! collect($flags)->contains('label', 'Private controlled medication detail')));
    $permission = Permission::firstOrCreate(['key' => MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY], ['description' => 'View controlled medications']);
    $this->worker->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $this->actingAs($this->worker->fresh())->get('/my-day')->assertOk()->assertInertia(fn ($page) => $page
        ->where('handover.flags', fn ($flags) => collect($flags)->contains('label', 'Private controlled medication detail')));
});

it('rejects invented source items and drafts', function (string $fault) {
    if ($fault === 'draft') {
        $this->handover->update(['status' => 'draft', 'submitted_at' => null]);
    }
    $this->postJson("/my-day/handovers/{$this->handover->id}/follow-ups", ['item_key' => $fault === 'key' ? str_repeat('a', 64) : $this->key])
        ->assertStatus($fault === 'draft' ? 422 : 404);
    expect($this->incoming->tasks()->count())->toBe(0);
})->with(['key', 'draft']);

it('denies current assignment privacy and create-permission revocation', function (string $fault) {
    if ($fault === 'assignment') {
        $this->incoming->update(['user_id' => User::factory()->frontlineWorker()->create()->id]);
    }
    if ($fault === 'privacy') {
        $this->client->supportWorkers()->detach($this->worker->id);
    }
    if ($fault === 'permission') {
        $this->worker->permissionOverrides()->updateExistingPivot(Permission::where('key', 'shifts.tasks.createSelf')->value('id'), ['allowed' => false]);
    }
    $this->postJson("/my-day/handovers/{$this->handover->id}/follow-ups", ['item_key' => $this->key])->assertForbidden();
    expect($this->incoming->tasks()->count())->toBe(0);
})->with(['assignment', 'privacy', 'permission']);
