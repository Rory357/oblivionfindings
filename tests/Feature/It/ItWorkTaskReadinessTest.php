<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Services\ItWorkTaskReadinessService;
use App\Domain\It\Services\ItWorkTaskService;
use App\Domain\It\Services\ItWorkTransitionService;
use App\Models\ItTicket;
use App\Models\ItWorkTask;
use App\Models\ItWorkTaskCompletion;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Notification::fake();
    $this->site = Site::factory()->create();
    $this->actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->actor->roles()->sync(Role::query()->where('name', 'hr')->pluck('id'));
    HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id,
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'open',
        'workflow_state' => 'submitted', 'lock_version' => 1]);
    $this->task = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'title' => 'Private work', 'is_required' => false]);
    $this->actingAs($this->actor);
    $this->historyPath = '/it/tickets/'.$this->ticket->id.'/tasks/'.$this->task->id.'/history';
    $this->review = ['actor_user_id' => $this->actor->id, 'review_nonce' => (string) Str::uuid()];
});

test('history reads are actor-bound private snapshots and do not fabricate legacy evidence', function () {
    $this->task->forceFill(['status' => 'completed', 'completion_note' => 'Known legacy facts', 'evidence' => ['Private legacy reference']])->save();
    $before = $this->ticket->fresh()->lock_version;
    $response = $this->getJson($this->historyPath.'?'.http_build_query($this->review))->assertOk()
        ->assertJsonPath('data.viewer_user_id', $this->actor->id)
        ->assertJsonPath('data.review_nonce', $this->review['review_nonce'])
        ->assertJsonPath('data.task_id', $this->task->id)
        ->assertJsonPath('data.lock_version', $before)
        ->assertJsonPath('data.history.entries', [])
        ->assertJsonPath('data.history.total_count', 0)
        ->assertJsonPath('data.readiness.completion', 'unknown');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($this->task->completions()->count())->toBe(0)
        ->and($this->task->fresh()->current_completion_id)->toBeNull()
        ->and($this->ticket->fresh()->lock_version)->toBe($before);
    $this->getJson($this->historyPath.'?'.http_build_query([...$this->review, 'actor_user_id' => $this->actor->id + 10000]))
        ->assertForbidden()->assertJsonPath('code', 'access_unavailable')->assertDontSee('Private legacy reference');
});

test('history requires its explicit review nonce and never redirects invalid private requests', function () {
    $response = $this->get($this->historyPath.'?actor_user_id='.$this->actor->id)->assertUnprocessable()
        ->assertJsonPath('code', 'history_validation')->assertJsonValidationErrors('review_nonce');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->getJson($this->historyPath.'?'.http_build_query([...$this->review, 'before_sequence' => 0]))
        ->assertUnprocessable()->assertJsonValidationErrors('before_sequence');
    Auth::logout();
    $response = $this->get($this->historyPath.'?'.http_build_query($this->review))->assertUnauthorized()
        ->assertJsonPath('code', 'session_expired');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('participant-only access cannot read private completion history or task projections', function () {
    app(ItWorkTaskService::class)->complete($this->ticket, $this->task, $this->actor, ['evidence' => ['Private current evidence']]);
    $this->ticket->forceFill(['is_sensitive' => true, 'requester_user_id' => $this->actor->id])->save();
    $this->getJson($this->historyPath.'?'.http_build_query($this->review))
        ->assertForbidden()->assertJsonPath('code', 'access_unavailable')->assertDontSee('Private current evidence');
    $this->getJson('/it/tickets/'.$this->ticket->id)->assertOk()
        ->assertJsonPath('linked_context.tasks', [])->assertJsonPath('approvals', [])
        ->assertDontSee('Private current evidence');
});

test('history rejects unapproved sites and nested tasks from another canonical ticket', function () {
    $other = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $otherTask = ItWorkTask::factory()->create(['ticket_id' => $other->id, 'title' => 'Other private task']);
    $this->getJson('/it/tickets/'.$this->ticket->id.'/tasks/'.$otherTask->id.'/history?'.http_build_query($this->review))
        ->assertNotFound()->assertJsonPath('code', 'history_unavailable')->assertDontSee('Other private task');
    $this->ticket->forceFill(['site_id' => Site::factory()->create()->id])->save();
    $this->getJson($this->historyPath.'?'.http_build_query($this->review))
        ->assertNotFound()->assertJsonPath('code', 'history_unavailable');
});

test('history is bounded and cursor pagination preserves immutable generations without mutating the ticket', function () {
    for ($sequence = 1; $sequence <= 25; $sequence++) {
        ItWorkTaskCompletion::query()->create(['task_id' => $this->task->id, 'sequence' => $sequence, 'source' => 'command',
            'recorded_at' => now(), 'completed_at' => now(), 'completed_by_user_id' => $this->actor->id,
            'recorded_by_user_id' => $this->actor->id, 'task_definition' => ['title' => 'Generation '.$sequence],
            'prerequisite_completions' => [], 'evidence' => ['Retained reference '.$sequence]]);
    }
    $version = $this->ticket->fresh()->lock_version;
    $first = $this->getJson($this->historyPath.'?'.http_build_query($this->review))->assertOk()
        ->assertJsonCount(20, 'data.history.entries')->assertJsonPath('data.history.total_count', 25)
        ->assertJsonPath('data.history.has_more', true)->assertJsonPath('data.history.next_before_sequence', 6)
        ->assertJsonPath('data.history.entries.0.sequence', 25)
        ->assertJsonPath('data.history.entries.0.evidence', ['Retained reference 25'])
        ->assertJsonPath('data.history.entries.0.completed_by.id', $this->actor->id);
    $second = $this->getJson($this->historyPath.'?'.http_build_query([...$this->review, 'before_sequence' => 6]))->assertOk()
        ->assertJsonCount(5, 'data.history.entries')->assertJsonPath('data.history.has_more', false)
        ->assertJsonPath('data.history.next_before_sequence', null)->assertJsonPath('data.history.entries.0.sequence', 5);
    expect(array_merge(array_column($first->json('data.history.entries'), 'sequence'), array_column($second->json('data.history.entries'), 'sequence')))
        ->toBe(range(25, 1))->and($this->ticket->fresh()->lock_version)->toBe($version);
});

test('reopened prerequisites block transitive work and settlement until dependent completion generations are renewed', function () {
    $tasks = app(ItWorkTaskService::class);
    $readiness = app(ItWorkTaskReadinessService::class);
    $dependent = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'title' => 'Dependent verification', 'is_required' => true]);
    $last = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'title' => 'Final verification', 'is_required' => true]);
    $dependent->dependencies()->attach($this->task->id);
    $last->dependencies()->attach($dependent->id);
    $tasks->complete($this->ticket, $this->task, $this->actor, ['evidence' => ['Initial result']]);
    $tasks->complete($this->ticket, $dependent, $this->actor, ['evidence' => ['Initial dependent result']]);
    $tasks->complete($this->ticket, $last, $this->actor, ['evidence' => ['Initial final result']]);
    $oldDependent = $dependent->fresh()->current_completion_id;
    $this->getJson($this->historyPath.'?'.http_build_query($this->review))->assertOk()
        ->assertJsonPath('data.affected_tasks.0.id', $dependent->id)->assertJsonPath('data.affected_tasks.1.id', $last->id);
    $tasks->reopen($this->ticket, $this->task, $this->actor, 'The original implementation changed.');
    $tasks->complete($this->ticket, $this->task, $this->actor, ['evidence' => ['Revised result']]);
    $state = $readiness->forTicket($this->ticket->fresh(), $this->actor);
    expect($state['verdicts'][$dependent->id]['completion'])->toBe('invalid')
        ->and($state['verdicts'][$last->id]['completion'])->toBe('invalid')
        ->and($dependent->fresh()->status)->toBe('completed')->and($dependent->fresh()->current_completion_id)->toBe($oldDependent);
    $resolve = fn () => app(ItWorkTransitionService::class)->transition($this->ticket, new ItTransitionInput(
        actor: $this->actor, to: ItWorkflowState::Resolved, resolutionCode: 'restored', resolutionSummary: 'Verified restoration.',
        resolutionVerification: 'Required task evidence was refreshed and the restored service was checked.',
    ));
    expect($resolve)->toThrow(DomainException::class, 'Required task evidence is no longer current.');
    expect($this->ticket->fresh()->status)->toBe('open');
    $versionBeforeRejection = $this->ticket->fresh()->lock_version;
    $this->postJson('/it/tickets/'.$this->ticket->id.'/resolve', ['resolution_code' => 'restored', 'resolution_verification' => 'Synthetic verification confirmed the expected result.',
        'actor_user_id' => $this->actor->id, 'expected_version' => $versionBeforeRejection,
        'note' => 'Retain this proposal while required evidence needs review.', 'notify_requester' => false,
    ])->assertUnprocessable()
        ->assertJsonPath('blocker.ticket_id', $this->ticket->id)
        ->assertJsonPath('blocker.viewer_user_id', $this->actor->id)
        ->assertJsonPath('blocker.kind', 'task')
        ->assertJsonPath('blocker.record_id', $dependent->id)
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($this->ticket->fresh()->lock_version)->toBe($versionBeforeRejection)
        ->and($this->ticket->comments()->count())->toBe(0);
    $tasks->reopen($this->ticket, $last, $this->actor, 'Repeat final verification against the new implementation.');
    expect(fn () => $tasks->complete($this->ticket, $last, $this->actor, []))->toThrow(ValidationException::class);
    $tasks->reopen($this->ticket, $dependent, $this->actor, 'Repeat dependent verification.');
    $tasks->complete($this->ticket, $dependent, $this->actor, ['evidence' => ['Revised dependent result']]);
    $tasks->complete($this->ticket, $last, $this->actor, ['evidence' => ['Revised final result']]);
    // Unrelated optional work must not become an accidental resolution requirement.
    ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'is_required' => false]);
    expect($resolve()->status)->toBe('resolved')
        ->and($dependent->completions()->count())->toBe(2)->and($last->completions()->count())->toBe(2);
});

test('current task projection does not leak an unavailable prerequisite identifier or title', function () {
    $other = ItTicket::factory()->create(['site_id' => Site::factory()->create()->id]);
    $foreign = ItWorkTask::factory()->create(['ticket_id' => $other->id, 'title' => 'Private foreign prerequisite']);
    // Simulate a pre-existing corrupt pivot; canonical write paths already reject it.
    $this->task->dependencies()->attach($foreign->id);
    $this->getJson('/it/tickets/'.$this->ticket->id)->assertOk()
        ->assertJsonPath('linked_context.tasks.0.dependencies', [])
        ->assertJsonPath('linked_context.tasks.0.readiness.blockers.0.code', 'dependency_unavailable')
        ->assertJsonPath('linked_context.tasks.0.readiness.blockers.0.task_id', null)
        ->assertDontSee('Private foreign prerequisite');
});

test('missing task history setup disables empty-ticket creation and rejects every affected write without changing records', function () {
    $empty = ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'open']);
    $schema = Schema::getFacadeRoot();
    $missing = Mockery::mock($schema)->makePartial();
    $missing->shouldReceive('hasTable')->with('it_work_task_completions')->andReturnFalse();
    Schema::swap($missing);
    try {
        $this->getJson('/it/tickets/'.$empty->id)->assertOk()->assertJsonPath('linked_context.tasks', [])
            ->assertJsonPath('task_work', ['storage_ready' => false, 'can_create' => false, 'can_reorder' => false]);
        $this->getJson($this->historyPath.'?'.http_build_query($this->review))->assertStatus(503)
            ->assertJsonPath('code', 'history_request_failed');
        $tuple = fn () => ['actor_user_id' => $this->actor->id, 'request_uuid' => (string) Str::uuid(),
            'expected_version' => $this->ticket->fresh()->lock_version];
        $base = '/it/tickets/'.$this->ticket->id.'/tasks';
        $this->postJson($base, [...$tuple(), 'title' => 'Retain proposed task', 'approval_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('form');
        $this->patchJson($base.'/'.$this->task->id, [...$tuple(), 'title' => 'Retain proposed correction'])
            ->assertUnprocessable()->assertJsonValidationErrors('form');
        $this->patchJson($base.'/reorder', [...$tuple(), 'ordered_ids' => [$this->task->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('form');
    } finally {
        Schema::swap($schema);
    }
    expect($this->ticket->tasks()->count())->toBe(1)->and($this->task->fresh()->title)->toBe('Private work')
        ->and($this->ticket->fresh()->lock_version)->toBe(1);
});
