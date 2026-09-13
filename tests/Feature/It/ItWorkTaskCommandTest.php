<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\It\Services\ItWorkTaskCommandService;
use App\Domain\It\Services\ItWorkTaskService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItWorkTask;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

function taskCommandActor(Site $site, string $role = 'hr'): User
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
    $this->site = Site::factory()->create();
    $this->actor = taskCommandActor($this->site);
    $this->requester = taskCommandActor($this->site, 'support_worker');
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->requester->id,
        'status' => 'open', 'lock_version' => 1]);
    $this->actingAs($this->actor);
    $this->tuple = fn (int $version = 1) => ['actor_user_id' => $this->actor->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => $version];
    $this->path = '/it/tickets/'.$this->ticket->id;
});

test('task JSON commands require a complete original identity and allowlist model fields', function () {
    $this->postJson($this->path.'/tasks', ['title' => 'Without identity'])->assertUnprocessable()->assertJsonValidationErrors(['actor_user_id', 'request_uuid', 'expected_version']);
    $this->post($this->path.'/tasks', ['title' => 'Partial tuple', 'actor_user_id' => $this->actor->id])->assertSessionHasErrors(['request_uuid', 'expected_version']);
    $input = [...($this->tuple)(), 'title' => 'Approved task', 'description' => 'Private draft text',
        'completed_by_user_id' => $this->requester->id, 'completed_at' => now()->toIso8601String(), 'evidence' => ['Injected'],
        'ticket_id' => 987654, 'parent_task_id' => 987654, 'status' => 'completed'];
    $result = $this->postJson($this->path.'/tasks', $input)->assertCreated()->assertJsonPath('status', 'committed')
        ->assertJsonPath('data.operation', 'task.create')->assertJsonPath('data.viewer_user_id', $this->actor->id)
        ->assertJsonPath('data.lock_version', 2)->assertJsonPath('data.changed', true)->assertJsonPath('data.replayed', false)
        ->assertHeader('Cache-Control', 'no-store, private');
    $task = ItWorkTask::query()->sole();
    expect($task->id)->toBe($result->json('data.task_id'))->and($task->status)->toBe('pending')
        ->and($task->completed_by_user_id)->toBeNull()->and($task->completed_at)->toBeNull()->and($task->evidence)->toBeNull()
        ->and($task->parent_task_id)->toBeNull()->and((int) $task->ticket_id)->toBe((int) $this->ticket->id);
    $receipt = ItTicketCommandReceipt::query()->sole();
    expect(json_encode($receipt->result_metadata))->not->toContain('Private draft text')->not->toContain('Approved task');
});

test('all actual task mutations advance the aggregate exactly once while no-op receipts do not mutate evidence', function () {
    $this->postJson($this->path.'/tasks', [...($this->tuple)(), 'title' => 'First'])->assertCreated()->assertJsonPath('data.lock_version', 2);
    $first = ItWorkTask::query()->sole();
    $this->patchJson($this->path.'/tasks/'.$first->id, [...($this->tuple)(2), 'title' => 'Changed'])->assertOk()->assertJsonPath('data.lock_version', 3);
    $before = [$first->fresh()->updated_at->toISOString(), $this->ticket->events()->count(), AuditLog::count()];
    $this->travel(1)->minutes();
    $this->patchJson($this->path.'/tasks/'.$first->id, [...($this->tuple)(3), 'title' => 'Changed'])->assertOk()->assertJsonPath('data.changed', false)->assertJsonPath('data.lock_version', 3);
    expect([$first->fresh()->updated_at->toISOString(), $this->ticket->events()->count(), AuditLog::count()])->toBe($before);
    $this->postJson($this->path.'/tasks/'.$first->id.'/complete', [...($this->tuple)(3), 'completion_note' => 'Checked'])->assertOk()->assertJsonPath('data.lock_version', 4);
    $this->postJson($this->path.'/tasks/'.$first->id.'/reopen', [...($this->tuple)(4), 'reason' => 'Check the changed requirement'])->assertOk()->assertJsonPath('data.lock_version', 5);
    $this->postJson($this->path.'/tasks', [...($this->tuple)(5), 'title' => 'Second'])->assertCreated()->assertJsonPath('data.lock_version', 6);
    $second = ItWorkTask::query()->whereKeyNot($first->id)->sole();
    $order = [$second->id, $first->id];
    $this->patchJson($this->path.'/tasks/reorder', [...($this->tuple)(6), 'ordered_ids' => $order])->assertOk()->assertJsonPath('data.lock_version', 7)->assertJsonPath('data.task_id', null);
    $this->patchJson($this->path.'/tasks/reorder', [...($this->tuple)(7), 'ordered_ids' => $order])->assertOk()->assertJsonPath('data.lock_version', 7)->assertJsonPath('data.changed', false);
    expect($this->ticket->tasks()->orderBy('sort_order')->pluck('id')->all())->toBe($order)
        ->and($this->ticket->events()->where('type', 'work_tasks_reordered')->count())->toBe(1);
});

test('task replay proves its original commit separately from current props and rejects changed details', function () {
    $input = [...($this->tuple)(), 'title' => 'Original'];
    $response = $this->postJson($this->path.'/tasks', $input)->assertCreated();
    $id = $response->json('data.task_id');
    $this->patchJson($this->path.'/tasks/'.$id, [...($this->tuple)(2), 'title' => 'Later edit'])->assertOk();
    $this->postJson($this->path.'/tasks', $input)->assertOk()->assertJsonPath('data.task_id', $id)->assertJsonPath('data.lock_version', 2)->assertJsonPath('data.replayed', true);
    $this->getJson($this->path.'/task-commands/create/'.$input['request_uuid'])->assertOk()->assertJsonPath('data.lock_version', 2)->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson($this->path.'/tasks', [...$input, 'title' => 'Different'])->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    expect(ItWorkTask::count())->toBe(1)->and($this->ticket->fresh()->lock_version)->toBe(3);
});

test('every task command rejects the stale aggregate before any mutation', function (string $operation) {
    $task = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'status' => $operation === 'reopen' ? 'completed' : 'pending']);
    $this->ticket->update(['subcategory' => 'A concurrent canonical change']);
    $input = [...($this->tuple)(), ...match ($operation) {
        'create', 'update' => ['title' => 'Stale write'], 'complete' => ['completion_note' => 'Stale'],
        'reopen' => ['reason' => 'Stale reopening'], 'reorder' => ['ordered_ids' => [$task->id]],
    }];
    $path = $this->path.'/tasks'.match ($operation) {
        'create' => '', 'update' => '/'.$task->id, 'reorder' => '/reorder', default => '/'.$task->id.'/'.$operation
    };
    $method = in_array($operation, ['update', 'reorder'], true) ? 'patchJson' : 'postJson';
    $this->$method($path, $input)->assertConflict()->assertJsonPath('code', 'stale_ticket')->assertJsonPath('current.lock_version', 2);
    expect(ItTicketCommandReceipt::count())->toBe(0)->and($this->ticket->events()->count())->toBe(0)->and(ItWorkTask::count())->toBe(1);
})->with(['create', 'update', 'complete', 'reopen', 'reorder']);

test('task cancellation permanently fences a delayed original command without changing task state', function (string $operation) {
    $task = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'status' => $operation === 'reopen' ? 'completed' : 'pending']);
    $input = [...($this->tuple)(), ...match ($operation) {
        'create', 'update' => ['title' => 'Cancelled proposal'], 'complete' => ['completion_note' => 'Cancelled proposal'],
        'reopen' => ['reason' => 'Cancelled proposal'], 'reorder' => ['ordered_ids' => [$task->id]],
    }];
    $identity = $this->path.'/task-commands/'.$operation.'/'.$input['request_uuid'];
    $target = in_array($operation, ['create', 'reorder'], true) ? [] : ['task_id' => $task->id];
    $this->getJson($identity.($target ? '?task_id='.$task->id : ''))->assertNotFound();
    $this->postJson($identity.'/cancel', ['actor_user_id' => $this->actor->id, ...$target])->assertOk()
        ->assertJsonPath('status', 'cancelled')->assertJsonPath('data.operation', 'task.'.$operation)->assertJsonPath('data.replayed', false);
    $path = $this->path.'/tasks'.match ($operation) {
        'create' => '', 'update' => '/'.$task->id, 'reorder' => '/reorder', default => '/'.$task->id.'/'.$operation
    };
    $method = in_array($operation, ['update', 'reorder'], true) ? 'patchJson' : 'postJson';
    $this->$method($path, $input)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('data.replayed', true);
    $this->getJson($identity.($target ? '?task_id='.$task->id : ''))->assertOk()->assertJsonPath('status', 'cancelled');
    expect(ItWorkTask::count())->toBe(1)->and($this->ticket->fresh()->lock_version)->toBe(1)->and($this->ticket->events()->count())->toBe(0);
})->with(['create', 'update', 'complete', 'reopen', 'reorder']);

test('a commit that wins cancellation remains committed and cancellation cannot bind another task', function () {
    $task = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id]);
    $other = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id]);
    $input = [...($this->tuple)(), 'title' => 'Committed edit'];
    $this->patchJson($this->path.'/tasks/'.$task->id, $input)->assertOk();
    $url = $this->path.'/task-commands/update/'.$input['request_uuid'];
    $this->postJson($url.'/cancel', ['actor_user_id' => $this->actor->id, 'task_id' => $other->id])->assertNotFound();
    $this->postJson($url.'/cancel', ['actor_user_id' => $this->actor->id, 'task_id' => $task->id])->assertOk()->assertJsonPath('status', 'committed');
    $this->getJson($url.'?task_id='.$task->id)->assertOk()->assertJsonPath('data.task_id', $task->id);
});

test('task commands conceal foreign nested objects and reject partial reorder and dependency coercion', function () {
    $own = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id]);
    $foreign = ItWorkTask::factory()->create();
    $this->patchJson($this->path.'/tasks/'.$foreign->id, [])->assertNotFound();
    foreach ([[], [$own->id, $own->id], [$foreign->id], ['1abc']] as $ids) {
        $this->patchJson($this->path.'/tasks/reorder', [...($this->tuple)(), 'ordered_ids' => $ids])->assertUnprocessable();
    }
    $this->postJson($this->path.'/tasks', [...($this->tuple)(), 'title' => 'Foreign dependency', 'dependency_ids' => [$foreign->id]])->assertUnprocessable();
    $this->postJson($this->path.'/tasks', [...($this->tuple)(), 'title' => 'Coerced dependency', 'dependency_ids' => [$own->id.'abc']])->assertUnprocessable();
    expect($this->ticket->fresh()->lock_version)->toBe(1)->and(ItTicketCommandReceipt::count())->toBe(0);
});

test('task commands recheck original actor and current Site permissions including receipt recovery', function () {
    $input = [...($this->tuple)(), 'title' => 'Scoped work'];
    $this->postJson($this->path.'/tasks', [...$input, 'actor_user_id' => $this->requester->id])->assertForbidden();
    $this->postJson($this->path.'/tasks', $input)->assertCreated();
    $this->getJson($this->path.'/task-commands/create/'.$input['request_uuid'].'?actor_user_id='.$this->requester->id)->assertForbidden();
    // The already-loaded actor must not retain permission after canonical approval is removed.
    User::query()->whereKey($this->actor->id)->update(['approved_at' => null]);
    expect(fn () => app(ItWorkTaskCommandService::class)->recover($this->ticket, $this->actor, 'create', $input['request_uuid']))->toThrow(AuthorizationException::class);
    User::query()->whereKey($this->actor->id)->update(['approved_at' => now()]);
    $this->actingAs(taskCommandActor(Site::factory()->create()))->getJson($this->path.'/task-commands/create/'.$input['request_uuid'])->assertNotFound();
    $this->actingAs($this->requester)->getJson($this->path.'/task-commands/create/'.$input['request_uuid'])->assertForbidden();
});

test('new task responsibility requires current employment scope and availability while unchanged historical ownership remains editable', function () {
    $assignee = taskCommandActor($this->site);
    $task = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'assigned_to_user_id' => $assignee->id]);
    HrEmployeeProfile::query()->where('user_id', $assignee->id)->update(['is_active' => false]);
    $this->patchJson($this->path.'/tasks/'.$task->id, [...($this->tuple)(), 'description' => 'Keep historical owner', 'assigned_to_user_id' => $assignee->id])->assertOk();
    $this->postJson($this->path.'/tasks', [...($this->tuple)(2), 'title' => 'New responsibility', 'assigned_to_user_id' => $assignee->id])->assertUnprocessable();
    $remote = taskCommandActor(Site::factory()->create());
    $this->postJson($this->path.'/tasks', [...($this->tuple)(2), 'title' => 'Remote responsibility', 'assigned_to_user_id' => $remote->id])->assertUnprocessable();
    $absent = taskCommandActor($this->site);
    HrLeaveRequest::factory()->create(['user_id' => $absent->id, 'status' => 'approved',
        'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
    $this->postJson($this->path.'/tasks', [...($this->tuple)(2), 'title' => 'Unavailable technician', 'assigned_to_user_id' => $absent->id])->assertUnprocessable();
});

test('task audit failure rolls back the task aggregate and its receipt and allows exact retry', function () {
    $input = [...($this->tuple)(), 'title' => 'Atomic task'];
    $event = 'eloquent.creating: '.AuditLog::class;
    Event::listen($event, fn () => throw new RuntimeException('Synthetic task audit failure'));
    try {
        expect(fn () => app(ItWorkTaskCommandService::class)->execute($this->ticket, $this->actor, 'create', $input))->toThrow(RuntimeException::class, 'Synthetic task audit failure');
    } finally {
        Event::forget($event);
    }
    expect(ItWorkTask::count())->toBe(0)->and(ItTicketCommandReceipt::count())->toBe(0)->and($this->ticket->fresh()->lock_version)->toBe(1)->and($this->ticket->events()->count())->toBe(0);
    $this->postJson($this->path.'/tasks', $input)->assertCreated();
});

test('legacy task forms and canonical adapters advance version without mass assigning transport or completion fields', function () {
    $this->post($this->path.'/tasks', ['title' => 'Legacy form'])->assertRedirect()->assertSessionHasNoErrors();
    $task = ItWorkTask::query()->sole();
    app(ItWorkTaskService::class)->update($this->ticket, $task, $this->actor, ['description' => 'Adapter edit', 'ticket_id' => 99, 'completed_at' => now(), 'request_uuid' => 'ignored']);
    expect($this->ticket->fresh()->lock_version)->toBe(3)->and($task->fresh()->completed_at)->toBeNull()
        ->and((int) $task->fresh()->ticket_id)->toBe((int) $this->ticket->id)->and(ItTicketCommandReceipt::count())->toBe(0);
});
