<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\ItWorkTask;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

function taskCandidateActor(Site $site, string $role = 'hr'): User
{
    $actor = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $actor->roles()->sync(Role::query()->where('name', $role)->pluck('id'));
    HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);

    return $actor;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Notification::fake();
    config(['it.drafts.enabled' => false, 'it.drafts.retention_days' => null]);
    $this->site = Site::factory()->create();
    $this->actor = taskCandidateActor($this->site);
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'open', 'lock_version' => 3]);
    $this->task = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id]);
    $this->path = '/it/tickets/'.$this->ticket->id.'/task-candidates/validate';
    $this->candidate = ['actor_user_id' => $this->actor->id, 'purpose' => 'task_work', 'operation' => 'update',
        'task_id' => $this->task->id, 'memory_uuid' => (string) Str::uuid(), 'candidate_uuid' => (string) Str::uuid(),
        'base_ticket_version' => 3, 'step_index' => 2, 'fields' => ['title' => 'Private unsent task']];
    $this->actingAs($this->actor);
});

test('task memory proof authorizes an exact partial candidate without persisting or returning private content', function () {
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) {
            $writes[] = strtok(trim($query->sql), ' ');
        }
    });
    foreach (['create', 'update', 'complete', 'reopen', 'reorder'] as $operation) {
        $taskId = in_array($operation, ['create', 'reorder'], true) ? null : $this->task->id;
        $input = [...$this->candidate, 'operation' => $operation, 'task_id' => $taskId, 'fields' => []];
        $response = $this->postJson($this->path, $input)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('candidate.kind', 'memory')->assertJsonPath('candidate.authorized', true)
            ->assertJsonPath('candidate.actor_user_id', $this->actor->id)->assertJsonPath('candidate.memory_uuid', $input['memory_uuid'])
            ->assertJsonPath('candidate.candidate_uuid', $input['candidate_uuid'])->assertJsonPath('candidate.purpose', 'task_work')
            ->assertJsonPath('candidate.base_ticket_version', 3)->assertJsonPath('candidate.current_ticket_version', 3)
            ->assertJsonPath('candidate.capabilities.submit', true)->assertJsonPath('candidate.blocker', null);
        $context = 'ticket:'.$this->ticket->id.':task:'.($taskId ?? ($operation === 'reorder' ? 'order' : 'new')).':operation:'.$operation;
        expect($response->json('candidate.context_key'))->toBe($context);
    }
    $response = $this->postJson($this->path, $this->candidate)->assertOk();
    expect($response->getContent())->not->toContain('Private unsent task')->not->toContain('fields')
        ->and($writes)->toBe([])->and($this->ticket->fresh()->lock_version)->toBe(3);
});

test('task proof preserves original version and separates current lifecycle blockers from authorization', function (string $state, string $code) {
    $this->ticket->update(match ($state) {
        'changed' => ['subcategory' => 'A concurrent canonical change'],
        'resolved' => ['status' => 'resolved'],
        'merged' => ['merged_into_ticket_id' => ItTicket::factory()->create(['site_id' => $this->site->id])->id],
    });
    $this->postJson($this->path, $this->candidate)->assertOk()->assertJsonPath('candidate.authorized', true)
        ->assertJsonPath('candidate.base_ticket_version', 3)->assertJsonPath('candidate.capabilities.submit', false)
        ->assertJsonPath('candidate.blocker.code', $code);
    $this->postJson($this->path, [...$this->candidate, 'base_ticket_version' => 100])->assertUnprocessable();
})->with([['changed', 'ticket_changed'], ['resolved', 'ticket_settled'], ['merged', 'ticket_merged']]);

test('task proof rejects foreign nested objects original actor changes and incompatible purpose fields', function () {
    $foreign = ItWorkTask::factory()->create();
    $this->postJson($this->path, [...$this->candidate, 'task_id' => $foreign->id])->assertNotFound();
    $other = taskCandidateActor($this->site);
    $this->postJson($this->path, [...$this->candidate, 'actor_user_id' => $other->id])->assertForbidden();
    foreach ([['purpose' => 'ticket_edit'], ['step_index' => 4], ['task_id' => null], ['operation' => 'create'],
        ['fields' => ['subcategory' => 'Ticket field']], ['fields' => ['dependency_ids' => [$this->task->id.'x']]],
        ['fields' => ['title' => str_repeat('x', 256)]], ['fields' => ['status' => 'completed']]] as $change) {
        $this->postJson($this->path, [...$this->candidate, ...$change])->assertUnprocessable();
    }
    $this->actingAs(taskCandidateActor(Site::factory()->create()))->postJson($this->path, $this->candidate)->assertNotFound();
    $this->actingAs(taskCandidateActor($this->site, 'support_worker'))->postJson($this->path, $this->candidate)->assertForbidden();
});

test('task proof checks every historical binding even after private fields no longer contain the selection', function () {
    $remote = taskCandidateActor(Site::factory()->create());
    $this->postJson($this->path, [...$this->candidate, 'bound_scopes' => [['assigned_to_user_id' => $remote->id]]])->assertForbidden();
    $foreign = ItWorkTask::factory()->create();
    foreach (['dependency_ids', 'ordered_ids'] as $field) {
        $this->postJson($this->path, [...$this->candidate, 'bound_scopes' => [[$field => [$foreign->id]]]])->assertNotFound();
        $this->postJson($this->path, [...$this->candidate, 'bound_scopes' => [[$field => [$this->task->id, $this->task->id]]]])->assertUnprocessable();
    }
    $team = ItTeam::factory()->create(['is_active' => false]);
    $this->postJson($this->path, [...$this->candidate, 'bound_scopes' => [['team_id' => $team->id]]])->assertUnprocessable();
    $this->postJson($this->path, [...$this->candidate, 'bound_scopes' => [['unknown_scope' => 1]]])->assertUnprocessable();
});

test('task memory keeps unchanged canonical historical ownership but rejects newly unavailable responsibility', function () {
    $person = taskCandidateActor($this->site);
    $team = ItTeam::factory()->create(['is_active' => false]);
    $this->task->update(['assigned_to_user_id' => $person->id, 'team_id' => $team->id]);
    HrEmployeeProfile::query()->where('user_id', $person->id)->update(['is_active' => false]);
    $fields = ['assigned_to_user_id' => $person->id, 'team_id' => $team->id];
    $this->postJson($this->path, [...$this->candidate, 'fields' => $fields])->assertOk();
    $this->postJson($this->path, [...$this->candidate, 'operation' => 'create', 'task_id' => null,
        'fields' => ['assigned_to_user_id' => $person->id]])->assertForbidden();
});

test('task pending command proof binds every original identity and all earlier selected objects', function () {
    $pending = ['actor_user_id' => $this->actor->id, 'ticket_id' => $this->ticket->id,
        'request_uuid' => (string) Str::uuid(), 'operation' => 'update', 'task_id' => $this->task->id,
        'expected_version' => 2, 'fields' => ['title' => 'Earlier private proposal']];
    $this->postJson($this->path, [...$this->candidate, 'pending_task' => $pending])->assertOk();
    foreach (['actor_user_id' => $this->actor->id + 100, 'ticket_id' => $this->ticket->id + 100,
        'operation' => 'complete', 'task_id' => null, 'expected_version' => 99, 'extra' => 1] as $field => $value) {
        $this->postJson($this->path, [...$this->candidate, 'pending_task' => [...$pending, $field => $value]])->assertUnprocessable();
    }
    $remote = taskCandidateActor(Site::factory()->create());
    $this->postJson($this->path, [...$this->candidate, 'pending_task' => [...$pending,
        'fields' => ['assigned_to_user_id' => $remote->id]]])->assertForbidden();
    $foreign = ItWorkTask::factory()->create();
    $this->postJson($this->path, [...$this->candidate, 'pending_task' => [...$pending,
        'fields' => ['dependency_ids' => [$foreign->id]]]])->assertNotFound();
});

test('unfinished blank task text is recoverable while final commands still require complete business fields', function () {
    foreach (['create' => ['title' => ''], 'reopen' => ['reason' => ''],
        'complete' => ['completion_note' => '', 'evidence' => ['']]] as $operation => $fields) {
        $taskId = $operation === 'create' ? null : $this->task->id;
        $this->postJson($this->path, [...$this->candidate, 'operation' => $operation,
            'task_id' => $taskId, 'fields' => $fields])->assertOk()->assertJsonPath('candidate.authorized', true);
    }
    $tuple = ['actor_user_id' => $this->actor->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => 3];
    $this->postJson('/it/tickets/'.$this->ticket->id.'/tasks', [...$tuple, 'title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
    $this->postJson('/it/tickets/'.$this->ticket->id.'/tasks/'.$this->task->id.'/reopen', [...$tuple, 'reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
});

test('task candidate validation never flashes private fields even for a non JSON browser request', function () {
    $private = 'Synthetic private candidate content';
    $response = $this->post($this->path, [...$this->candidate, 'fields' => ['title' => $private, 'forbidden' => $private]])
        ->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('code', 'draft_validation');
    expect($response->getContent())->not->toContain($private);
    $this->assertFalse(session()->has('_old_input'));
    $this->assertFalse(session()->has('errors'));
});

test('task candidate infrastructure failures suppress private debug output and report only safe diagnostics', function () {
    config(['app.debug' => true]);
    $safeReports = [];
    Log::shouldReceive('error')->andReturnUsing(function ($message, $context) use (&$safeReports) {
        $safeReports[] = ['message' => $message, 'exception_type' => $context['exception_type'] ?? null,
            'context' => $context];
    });
    $private = 'Synthetic private candidate failure body';
    DB::listen(function ($query) use ($private) {
        if (str_contains($query->sql, 'it_work_tasks')) {
            throw new RuntimeException($private);
        }
    });
    $response = $this->postJson($this->path, [...$this->candidate, 'fields' => ['title' => $private]])
        ->assertServerError()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('code', 'draft_request_failed');
    expect($response->getContent())->not->toContain($private)->not->toContain('exception')->not->toContain('trace');
    // The injected database outage can also fail navigation's canonical task
    // provider before the endpoint. Every report must remain content-free;
    // the endpoint's original failure must be reported exactly once.
    expect(array_filter($safeReports, fn ($report) => $report['exception_type'] === RuntimeException::class))->toHaveCount(1);
    foreach ($safeReports as $report) {
        expect($report['message'])->toBe('IT draft request failed')
            ->and(array_keys($report['context']))->toBe(['failure_id', 'exception_type', 'source_file', 'source_line'])
            ->and(json_encode($report['context']))->not->toContain($private);
    }
    $this->assertFalse(session()->has('_old_input'));
});
