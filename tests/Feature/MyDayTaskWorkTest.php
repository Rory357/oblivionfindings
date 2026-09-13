<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\User;
use App\Support\ShiftTaskSupport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-12 10:30:00', 'Pacific/Auckland')->utc());
    $this->worker = User::factory()->frontlineWorker()->create();
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->worker->id, 'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null,
    ]);
    foreach (['shifts.tasks.createSelf', 'shifts.tasks.updateSelf', 'clients.viewAssigned'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'shifts', 'module' => 'Operations']);
        $this->worker->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    }
    $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
    $this->client->supportWorkers()->attach($this->worker->id);
    $this->shift = Shift::factory()->create([
        'user_id' => $this->worker->id, 'client_id' => $this->client->id,
        'site_id' => $this->site->id, 'status' => 'in_progress',
        'starts_at' => Carbon::parse('2026-09-12 07:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-09-12 15:00:00', 'Pacific/Auckland')->utc(),
        'actual_starts_at' => Carbon::parse('2026-09-12 07:02:00', 'Pacific/Auckland')->utc(),
    ]);
    $this->taskInput = [
        'request_id' => (string) Str::uuid(), 'label' => 'Prepare the activity bag',
        'task_scope' => 'client', 'client_id' => $this->client->id, 'when' => 'anytime',
    ];
    $this->actingAs($this->worker);
});

it('keeps the roster completion route subject to step state versions and current privacy', function () {
    $task = $this->shift->tasks()->create(['label' => 'Prepare activity', 'task_scope' => 'client', 'client_id' => $this->client->id,
        'steps' => [['id' => (string) Str::uuid(), 'label' => 'Pack supplies', 'is_completed' => false]], 'is_completed' => false]);
    $url = "/operations/shifts/{$this->shift->id}/tasks/{$task->id}";
    $this->patchJson($url, ['is_completed' => true, 'expected_version' => 0])->assertUnprocessable();
    $task->update(['steps' => []]);
    $this->patchJson($url, ['is_completed' => true, 'expected_version' => 0])->assertUnprocessable();
    $this->patchJson($url, ['is_completed' => true, 'expected_version' => $task->version])->assertOk();
    $this->client->supportWorkers()->detach($this->worker->id);
    $this->patchJson($url, ['is_completed' => false, 'expected_version' => $task->fresh()->version])->assertForbidden();
    expect($task->fresh()->is_completed)->toBeTrue();
});

it('shows medication occurrences across the whole current shift', function () {
    foreach (['medications.view', 'shifts.viewAssigned'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $this->worker->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    }
    $medication = ClientMedication::factory()->create(['client_id' => $this->client->id, 'name' => 'Synthetic scheduled medication',
        'is_prn' => false, 'controlled_drug' => false, 'active' => true, 'state' => 'active', 'start_date' => '2026-09-01', 'end_date' => null,
        'dose_times' => ['07:15', '14:45']]);
    $this->actingAs($this->worker->fresh())->get('/my-day')->assertOk()->assertInertia(fn ($page) => $page
        ->has('medications_due', 2)->where('medications_due.0.medication_id', $medication->id)
        ->where('medications_due.0.scheduled_for', '2026-09-12T07:15:00+12:00')
        ->where('medications_due.1.scheduled_for', '2026-09-12T14:45:00+12:00'));
});

it('creates an audited person task on the existing shift record and replays one submission', function () {
    $first = $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", $this->taskInput)
        ->assertCreated()->assertJsonPath('task.client_id', $this->client->id)
        ->assertJsonPath('task.assigned_to', $this->worker->id)->assertJsonPath('task.version', 0)
        ->assertJsonPath('task.scheduled_for', null)->json('task.id');
    $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", $this->taskInput)
        ->assertOk()->assertJsonPath('task.id', $first);
    expect(ShiftTask::where('shift_id', $this->shift->id)->count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'shift-task.created', 'auditable_id' => $first, 'user_id' => $this->worker->id]);
});

it('does not reuse a creation key for different work', function () {
    $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", $this->taskInput)->assertCreated();
    $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", [...$this->taskInput, 'label' => 'Different work'])
        ->assertUnprocessable()->assertJsonValidationErrors('request_id');
    expect(ShiftTask::where('shift_id', $this->shift->id)->count())->toBe(1);
});

it('keeps a whole site task unassigned to any individual client', function () {
    unset($this->taskInput['client_id']);
    $this->taskInput['task_scope'] = 'site';
    $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", $this->taskInput)
        ->assertCreated()->assertJsonPath('task.task_scope', 'site')->assertJsonPath('task.client_id', null);
});

it('rejects a private co-resident or a client from another site', function (bool $otherSite) {
    $client = Client::factory()->create(['site_id' => $otherSite ? Site::factory()->create()->id : $this->site->id, 'status' => 'active']);
    $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", [...$this->taskInput, 'client_id' => $client->id])->assertForbidden();
    expect(ShiftTask::where('shift_id', $this->shift->id)->count())->toBe(0);
})->with([false, true]);

it('requires the explicit self-create permission', function () {
    $permission = Permission::where('key', 'shifts.tasks.createSelf')->firstOrFail();
    $this->worker->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", $this->taskInput)->assertForbidden();
});

it('rejects creating or completing a task on another workers shift', function () {
    $other = User::factory()->frontlineWorker()->create();
    $this->shift->update(['user_id' => $other->id]);
    $task = $this->shift->tasks()->create(['label' => 'Other worker task']);
    $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", $this->taskInput)->assertForbidden();
    $this->putJson("/my-day/tasks/{$task->id}/completion", ['is_completed' => true, 'expected_version' => 0])->assertForbidden();
});

it('locks task writes when the shift is finished', function () {
    $task = $this->shift->tasks()->create(['label' => 'Still open']);
    $this->shift->update(['status' => 'completed', 'actual_ends_at' => now(), 'completed_by' => $this->worker->id]);
    $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", $this->taskInput)->assertUnprocessable()->assertJsonValidationErrors('shift');
    $this->putJson("/my-day/tasks/{$task->id}/completion", ['is_completed' => true, 'expected_version' => 0])->assertUnprocessable();
    expect($task->fresh()->is_completed)->toBeFalse();
});

it('preserves the actual day for a task after midnight on an overnight shift', function () {
    $this->travelTo(Carbon::parse('2026-09-13 01:00:00', 'Pacific/Auckland')->utc());
    $this->shift->update([
        'starts_at' => Carbon::parse('2026-09-12 22:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-09-13 07:00:00', 'Pacific/Auckland')->utc(),
    ]);
    $id = $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", [
        ...$this->taskInput, 'when' => 'time', 'scheduled_for' => '2026-09-13T02:00:00+12:00',
    ])->assertCreated()->json('task.id');
    expect(ShiftTask::findOrFail($id)->scheduledFor()->utc()->toIso8601String())->toBe('2026-09-12T14:00:00+00:00');
    $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", [
        ...$this->taskInput, 'request_id' => (string) Str::uuid(), 'when' => 'time', 'scheduled_for' => '2026-09-13T10:00:00+12:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_for');
});

it('uses an idempotent completion state and rejects a stale reversal', function () {
    $task = $this->shift->tasks()->create(['label' => 'Activity bag']);
    $url = "/my-day/tasks/{$task->id}/completion";
    $this->putJson($url, ['is_completed' => true, 'expected_version' => 0])->assertOk()->assertJsonPath('task.version', 1);
    $this->putJson($url, ['is_completed' => true, 'expected_version' => 0])->assertOk()->assertJsonPath('task.version', 1);
    $this->putJson($url, ['is_completed' => false, 'expected_version' => 0])->assertUnprocessable()->assertJsonValidationErrors('task');
    $this->putJson($url, ['is_completed' => false, 'expected_version' => 1])->assertOk()->assertJsonPath('task.is_completed', false)->assertJsonPath('task.version', 2);
});

it('preserves worker-added tasks when a stale roster form synchronises its older list', function () {
    $id = $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", $this->taskInput)->assertCreated()->json('task.id');
    ShiftTaskSupport::syncForShift($this->shift, []);
    expect(ShiftTask::find($id))->not->toBeNull();
    ShiftTaskSupport::syncForShift($this->shift, [['id' => $id, 'label' => 'Stale label', 'scheduled_time' => '14:00']]);
    expect(ShiftTask::findOrFail($id)->label)->toBe($this->taskInput['label']);
});

it('saves an encrypted private draft and rejects stale edits from another window', function () {
    $url = "/my-day/shifts/{$this->shift->id}/task-draft";
    $content = ['request_id' => (string) Str::uuid(), 'label' => 'Prepare activity bag', 'person' => (string) $this->client->id, 'when' => 'anytime', 'scheduled_for' => ''];
    $this->getJson($url)->assertOk()->assertJsonPath('version', 0)->assertJsonPath('content', null);
    $this->putJson($url, ['expected_version' => 0, 'content' => $content])->assertOk()->assertJsonPath('version', 1);
    $this->getJson($url)->assertOk()->assertJsonPath('content.label', $content['label']);
    $raw = DB::table('shift_task_drafts')->where('shift_id', $this->shift->id)->value('content');
    expect($raw)->not->toContain('Prepare activity bag');
    $this->putJson($url, ['expected_version' => 0, 'content' => [...$content, 'label' => 'Stale overwrite']])->assertUnprocessable();
    $this->putJson($url, ['expected_version' => 1, 'content' => null])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('content', null);
    $this->putJson($url, ['expected_version' => 1, 'content' => $content])->assertUnprocessable();
});

it('denies another worker access to a private draft', function () {
    $this->shift->update(['user_id' => User::factory()->create()->id]);
    $url = "/my-day/shifts/{$this->shift->id}/task-draft";
    $this->getJson($url)->assertForbidden();
    $this->putJson($url, ['expected_version' => 0, 'content' => null])->assertForbidden();
});

it('does not let the legacy completion shortcut change a finished shift', function () {
    $task = $this->shift->tasks()->create(['label' => 'Activity bag']);
    $this->shift->update(['status' => 'completed', 'actual_ends_at' => now()]);
    $this->postJson("/my-tasks/shift-task/{$task->id}/complete")->assertUnprocessable();
    expect($task->fresh()->is_completed)->toBeFalse();
});

it('keeps steps with the draft and creates one checklist with stable identities', function () {
    $step = ['id' => (string) Str::uuid(), 'label' => 'Pack the drink bottle'];
    $input = [...$this->taskInput, 'steps' => [$step]];
    $id = $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", $input)->assertCreated()
        ->assertJsonPath('task.steps.0.is_completed', false)->json('task.id');
    $this->postJson("/my-day/shifts/{$this->shift->id}/tasks", $input)->assertOk()->assertJsonPath('task.id', $id);
    expect(ShiftTask::findOrFail($id)->steps)->toHaveCount(1);
    $this->putJson("/my-day/tasks/$id/completion", ['is_completed' => true, 'expected_version' => 0])->assertUnprocessable();
    $this->putJson("/my-day/tasks/$id/steps", [...$step, 'action' => 'complete', 'is_completed' => true, 'expected_version' => 0])
        ->assertOk()->assertJsonPath('task.steps.0.completed_by', $this->worker->id)->assertJsonPath('task.version', 1);
    $this->putJson("/my-day/tasks/$id/completion", ['is_completed' => true, 'expected_version' => 1])->assertOk()->assertJsonPath('task.is_completed', true);
    $this->putJson("/my-day/tasks/$id/steps", [...$step, 'action' => 'complete', 'is_completed' => false, 'expected_version' => 2])->assertUnprocessable();
});

it('adds steps after creation and refuses stale step changes', function () {
    $task = $this->shift->tasks()->create(['label' => 'Activity bag']);
    $input = ['id' => (string) Str::uuid(), 'label' => 'Pack lunch', 'action' => 'add', 'expected_version' => 0];
    $this->putJson("/my-day/tasks/{$task->id}/steps", $input)->assertOk()->assertJsonPath('task.version', 1);
    $this->putJson("/my-day/tasks/{$task->id}/steps", $input)->assertOk();
    $this->putJson("/my-day/tasks/{$task->id}/steps", ['id' => $input['id'], 'action' => 'remove', 'expected_version' => 0])->assertUnprocessable();
    expect($task->fresh()->steps)->toHaveCount(1);
    $this->postJson("/my-tasks/shift-task/{$task->id}/complete", ['is_completed' => true, 'expected_version' => 1])->assertUnprocessable();
});
