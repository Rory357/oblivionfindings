<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItWorkTaskCommandService;
use App\Domain\It\Services\ItWorkTaskService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItWorkTask;
use App\Models\ItWorkTaskCompletion;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'open', 'lock_version' => 1]);
    $this->task = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'title' => 'Original definition',
        'description' => 'Original private description', 'status' => 'pending', 'evidence_required' => true]);
    $this->actingAs($this->actor);
    $this->tuple = fn () => ['actor_user_id' => $this->actor->id, 'request_uuid' => (string) Str::uuid(),
        'expected_version' => $this->ticket->fresh()->lock_version];
    $this->path = '/it/tickets/'.$this->ticket->id.'/tasks/'.$this->task->id;
});

test('completion reopen edit and recompletion preserve immutable generations and exact command replay', function () {
    $input = [...($this->tuple)(), 'completion_note' => "First verification\nExact private note", 'evidence' => ['Reference A', 'https://example.test/manual-evidence']];
    $this->postJson($this->path.'/complete', $input)->assertOk()->assertJsonPath('data.lock_version', 2);
    $first = $this->task->fresh()->currentCompletion;
    $original = $first->getRawOriginal();
    expect($first->source)->toBe('command')->and($first->sequence)->toBe(1)
        ->and($first->task_definition['title'])->toBe('Original definition')
        ->and($first->task_definition['description'])->toBe('Original private description')
        ->and($first->prerequisite_completions)->toBe([])->and($first->approval_id)->toBeNull();
    $this->postJson($this->path.'/complete', $input)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.lock_version', 2);
    expect($this->task->completions()->count())->toBe(1);
    $this->postJson($this->path.'/reopen', [...($this->tuple)(), 'reason' => 'The implementation changed.'])->assertOk();
    expect($this->task->fresh()->current_completion_id)->toBeNull()->and($this->task->fresh()->evidence)->toBeNull();
    $this->patchJson($this->path, [...($this->tuple)(), 'title' => 'Changed definition', 'description' => 'Changed description'])->assertOk();
    $this->postJson($this->path.'/complete', [...($this->tuple)(), 'completion_note' => 'Second verification', 'evidence' => ['Reference B']])->assertOk();
    $second = $this->task->fresh()->currentCompletion;
    expect($second->id)->not->toBe($first->id)->and($second->sequence)->toBe(2)
        ->and($second->task_definition['title'])->toBe('Changed definition')
        ->and($second->evidence)->toBe(['Reference B'])
        ->and($first->fresh()->getRawOriginal())->toBe($original)
        ->and($this->ticket->events()->where('type', 'work_task_completed')->count())->toBe(2)
        ->and($this->ticket->events()->where('type', 'work_task_reopened')->value('payload')['completion_id'])->toBe($first->id);
    expect(fn () => $first->update(['completion_note' => 'Rewritten']))->toThrow(LogicException::class);
    expect(fn () => $first->delete())->toThrow(LogicException::class);
    expect(json_encode(ItTicketCommandReceipt::query()->where('it_ticket_id', $this->ticket->id)->pluck('result_metadata')->all()))
        ->not->toContain('First verification')->not->toContain('Reference A');
});

test('legacy reopen captures only observed facts without inventing missing actor time or prerequisite provenance', function () {
    $this->task->forceFill(['status' => 'completed', 'completed_at' => null, 'completed_by_user_id' => null,
        'evidence' => ['Legacy retained reference'], 'completion_note' => 'Extant legacy note'])->save();
    $this->getJson('/it/tickets/'.$this->ticket->id)->assertOk();
    expect($this->task->completions()->count())->toBe(0);
    $this->postJson($this->path.'/reopen', [...($this->tuple)(), 'reason' => 'Recheck incomplete legacy evidence.'])->assertOk();
    $history = $this->task->completions()->sole();
    expect($history->source)->toBe('legacy_snapshot')->and($history->completed_at)->toBeNull()
        ->and($history->completed_by_user_id)->toBeNull()->and($history->recorded_by_user_id)->toBe($this->actor->id)
        ->and($history->recorded_at)->not->toBeNull()->and($history->prerequisite_completions)->toBeNull()
        ->and($history->approval_id)->toBeNull()->and($history->task_definition['title'])->toBe('Original definition')
        ->and($history->evidence)->toBe(['Legacy retained reference'])->and($history->completion_note)->toBe('Extant legacy note')
        ->and($this->task->fresh()->current_completion_id)->toBeNull()
        ->and($this->ticket->fresh()->lock_version)->toBe(2)
        ->and($this->ticket->events()->where('type', 'work_task_completed')->count())->toBe(0);
});

test('new completion binds the exact legacy prerequisite capture once without forging its earlier dependencies', function () {
    $prerequisite = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'status' => 'completed',
        'completed_at' => now()->subDays(5), 'completed_by_user_id' => $this->actor->id, 'evidence' => ['Known old evidence']]);
    $this->task->dependencies()->attach($prerequisite->id);
    $this->postJson($this->path.'/complete', [...($this->tuple)(), 'evidence' => ['Dependent verification']])->assertOk();
    $legacy = $prerequisite->fresh()->currentCompletion;
    expect($legacy->source)->toBe('legacy_snapshot')->and($legacy->prerequisite_completions)->toBeNull()
        ->and($legacy->completed_at->equalTo($prerequisite->completed_at))->toBeTrue()
        ->and($this->task->fresh()->currentCompletion->prerequisite_completions)->toBe([
            ['task_id' => $prerequisite->id, 'completion_id' => $legacy->id],
        ])->and($this->ticket->fresh()->lock_version)->toBe(2);
    $this->postJson('/it/tickets/'.$this->ticket->id.'/tasks/'.$prerequisite->id.'/reopen', [
        ...($this->tuple)(), 'reason' => 'Recheck original evidence.',
    ])->assertOk();
    expect($prerequisite->completions()->count())->toBe(1)
        ->and($this->task->fresh()->status)->toBe('completed')
        ->and($this->task->fresh()->currentCompletion->prerequisite_completions[0]['completion_id'])->toBe($legacy->id);
});

test('cancellation restoration and required or dependency repair require actual reasons and never erase links', function () {
    $prerequisite = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id]);
    $this->task->dependencies()->attach($prerequisite->id);
    $this->patchJson($this->path, [...($this->tuple)(), 'is_required' => false])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->patchJson($this->path, [...($this->tuple)(), 'status' => 'cancelled', 'reason' => 'Still required'])->assertUnprocessable();
    $this->patchJson($this->path, [...($this->tuple)(), 'is_required' => false, 'status' => 'cancelled', 'reason' => 'Approved optional cancellation'])->assertOk();
    expect($this->task->fresh()->status)->toBe('cancelled')->and($this->task->dependencies()->pluck('it_work_tasks.id')->all())->toBe([$prerequisite->id]);
    $version = $this->ticket->fresh()->lock_version;
    $this->patchJson($this->path, [...($this->tuple)(), 'status' => 'cancelled'])->assertOk()->assertJsonPath('data.changed', false);
    expect($this->ticket->fresh()->lock_version)->toBe($version);
    $this->patchJson($this->path, [...($this->tuple)(), 'status' => 'pending'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->patchJson($this->path, [...($this->tuple)(), 'status' => 'in_progress', 'reason' => 'Skip prerequisite'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->patchJson($this->path, [...($this->tuple)(), 'status' => 'pending', 'reason' => 'The optional check is needed again'])->assertOk();
    $this->patchJson($this->path, [...($this->tuple)(), 'dependency_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->patchJson($this->path, [...($this->tuple)(), 'dependency_ids' => [], 'reason' => 'The reviewed prerequisite is no longer applicable'])->assertOk();
    expect($this->ticket->events()->where('type', 'work_task_cancelled')->count())->toBe(1)
        ->and($this->ticket->events()->where('type', 'work_task_restored')->count())->toBe(1)
        ->and($this->task->fresh()->status)->toBe('pending')
        ->and($this->task->dependencies()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'it.ticket.task.updated')->where('auditable_id', $this->ticket->id)->count())->toBe(3);
});

test('only the same-ticket linked approved request is recorded in a new completion', function () {
    $other = ItTicketApproval::query()->create(['it_ticket_id' => ItTicket::factory()->create(['site_id' => $this->site->id])->id,
        'requested_by' => User::factory()->create()->id, 'status' => 'approved']);
    $this->patchJson($this->path, [...($this->tuple)(), 'approval_id' => $other->id, 'reason' => 'Forged binding'])->assertUnprocessable()->assertJsonValidationErrors('approval_id');
    $approval = ItTicketApproval::query()->create(['it_ticket_id' => $this->ticket->id,
        'requested_by' => User::factory()->create()->id, 'status' => 'pending']);
    $this->patchJson($this->path, [...($this->tuple)(), 'approval_id' => $approval->id, 'reason' => 'Explicit approval prerequisite'])->assertOk();
    $this->postJson($this->path.'/complete', [...($this->tuple)(), 'evidence' => ['Verification']])->assertUnprocessable();
    expect($this->task->completions()->count())->toBe(0);
    $approval->forceFill(['status' => 'approved', 'approver_id' => $this->actor->id, 'decided_at' => now()])->save();
    $this->postJson($this->path.'/complete', [...($this->tuple)(), 'evidence' => ['Verification']])->assertOk();
    expect($this->task->fresh()->currentCompletion->approval_id)->toBe($approval->id);
});

test('a failed history write rolls back pointer task receipt version and events before safe retry', function () {
    $event = 'eloquent.creating: '.ItWorkTaskCompletion::class;
    Event::listen($event, fn () => throw new RuntimeException('Synthetic history write failure'));
    $input = [...($this->tuple)(), 'evidence' => ['Retained evidence']];
    try {
        expect(fn () => app(ItWorkTaskCommandService::class)->execute($this->ticket, $this->actor, 'complete', $input, $this->task))
            ->toThrow(RuntimeException::class, 'Synthetic history write failure');
    } finally {
        Event::forget($event);
    }
    expect($this->task->fresh()->status)->toBe('pending')->and($this->task->fresh()->current_completion_id)->toBeNull()
        ->and($this->task->completions()->count())->toBe(0)->and($this->ticket->fresh()->lock_version)->toBe(1)
        ->and($this->ticket->events()->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()->where('request_uuid', $input['request_uuid'])->exists())->toBeFalse();
    $this->postJson($this->path.'/complete', $input)->assertOk();
    expect($this->task->completions()->count())->toBe(1);
});

test('audit failure during legacy reopen preserves the original mutable and absent historical state', function () {
    $this->task->forceFill(['status' => 'completed', 'evidence' => ['Legacy evidence'], 'completion_note' => 'Do not erase'])->save();
    $original = $this->task->fresh()->getRawOriginal();
    $event = 'eloquent.creating: '.AuditLog::class;
    Event::listen($event, function (AuditLog $entry): void {
        if ($entry->action === 'it.ticket.task.reopened') {
            throw new RuntimeException('Synthetic audit failure');
        }
    });
    try {
        expect(fn () => app(ItWorkTaskService::class)->reopen($this->ticket, $this->task, $this->actor, 'Recheck'))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }
    expect($this->task->fresh()->getRawOriginal())->toBe($original)->and($this->task->completions()->count())->toBe(0)
        ->and($this->ticket->fresh()->lock_version)->toBe(1)->and($this->ticket->events()->count())->toBe(0);
});

test('history restricts parent deletion cross-task pointers and lossy schema rollback', function () {
    $first = app(ItWorkTaskService::class)->complete($this->ticket, $this->task, $this->actor, ['evidence' => ['Permanent history']]);
    $other = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id]);
    expect(fn () => DB::table('it_work_tasks')->where('id', $other->id)->update(['current_completion_id' => $first->current_completion_id]))->toThrow(QueryException::class);
    expect(fn () => DB::table('it_work_tasks')->where('id', $first->id)->delete())->toThrow(QueryException::class);
    $migration = require database_path('migrations/2026_09_09_000011_create_it_work_task_completions.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Retain task completion evidence');
    expect($this->task->completions()->count())->toBe(1)->and(Schema::hasTable('it_work_task_completions'))->toBeTrue();
});

test('missing completion storage fails actionably before erasing legacy facts', function () {
    $this->task->forceFill(['status' => 'completed', 'evidence' => ['Keep legacy bytes']])->save();
    $schema = Schema::getFacadeRoot();
    $missingStorage = Mockery::mock($schema)->makePartial();
    $missingStorage->shouldReceive('hasTable')->with('it_work_task_completions')->andReturnFalse();
    Schema::swap($missingStorage);
    try {
        expect(fn () => app(ItWorkTaskService::class)->reopen($this->ticket, $this->task, $this->actor, 'Recheck'))->toThrow(ValidationException::class);
    } finally {
        Schema::swap($schema);
    }
    expect($this->task->fresh()->status)->toBe('completed')->and($this->task->fresh()->evidence)->toBe(['Keep legacy bytes']);
});

test('completion actor provenance survives deletion of the original account without rewriting history', function () {
    app(ItWorkTaskService::class)->complete($this->ticket, $this->task, $this->actor, ['evidence' => ['Verified before account removal']]);
    $completion = $this->task->fresh()->currentCompletion;
    $original = $completion->getRawOriginal();
    $originalActorId = $this->actor->id;
    $this->actingAs(User::factory()->create(['approved_at' => now()]));
    DB::table('users')->where('id', $originalActorId)->delete();
    expect($completion->fresh()->getRawOriginal())->toBe($original)
        ->and($completion->fresh()->completed_by_user_id)->toBe($originalActorId)
        ->and($completion->fresh()->recorded_by_user_id)->toBe($originalActorId)
        ->and($completion->fresh()->completedBy)->toBeNull()
        ->and($this->task->fresh()->completed_by_user_id)->toBeNull();
});

test('task memory proof checks current pending and every retained approval generation without leaking it', function () {
    $approval = ItTicketApproval::query()->create(['it_ticket_id' => $this->ticket->id,
        'requested_by' => $this->actor->id, 'status' => 'pending', 'reason' => 'Private request reason']);
    $second = ItTicketApproval::query()->create(['it_ticket_id' => $this->ticket->id,
        'requested_by' => $this->actor->id, 'status' => 'rejected', 'reason' => 'Private rejected request']);
    $foreign = ItTicketApproval::query()->create(['it_ticket_id' => ItTicket::factory()->create(['site_id' => $this->site->id])->id,
        'requested_by' => $this->actor->id, 'status' => 'pending']);
    $candidate = ['actor_user_id' => $this->actor->id, 'purpose' => 'task_work', 'operation' => 'update',
        'task_id' => $this->task->id, 'memory_uuid' => (string) Str::uuid(), 'candidate_uuid' => (string) Str::uuid(),
        'base_ticket_version' => 1, 'step_index' => 2, 'fields' => ['approval_id' => null],
        'bound_scopes' => [['approval_ids' => [$approval->id]], ['approval_ids' => [$second->id]]]];
    $path = '/it/tickets/'.$this->ticket->id.'/task-candidates/validate';
    $response = $this->postJson($path, $candidate)->assertOk()->assertJsonPath('candidate.authorized', true);
    expect($response->getContent())->not->toContain('Private request reason')->not->toContain('Private rejected request')
        ->and($this->ticket->fresh()->lock_version)->toBe(1);
    $this->postJson($path, [...$candidate, 'fields' => ['approval_id' => $foreign->id]])->assertNotFound();
    $this->postJson($path, [...$candidate, 'bound_scopes' => [['approval_ids' => [$approval->id]], ['approval_ids' => [$foreign->id]]]])->assertNotFound();
    $this->postJson($path, [...$candidate, 'bound_scopes' => [['approval_ids' => [$approval->id, $approval->id]]]])->assertUnprocessable();
    $pending = ['actor_user_id' => $this->actor->id, 'ticket_id' => $this->ticket->id, 'operation' => 'update',
        'task_id' => $this->task->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => 1,
        'fields' => ['approval_id' => $foreign->id]];
    $this->postJson($path, [...$candidate, 'pending_task' => $pending])->assertNotFound();
    // Deliberately simulate an unavailable historical selection. Ordinary
    // approval deletion remains forbidden by the immutable model lifecycle.
    DB::table('it_ticket_approvals')->where('id', $second->id)->delete();
    $this->postJson($path, $candidate)->assertNotFound();
});

test('starting and recompleting use the same current prerequisite generation without rewriting descendants', function () {
    $a = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'evidence_required' => false]);
    $this->task->dependencies()->attach($a->id);
    $this->patchJson($this->path, [...($this->tuple)(), 'status' => 'in_progress'])->assertUnprocessable()->assertJsonValidationErrors('status');
    expect($this->task->fresh()->status)->toBe('pending');
    $aPath = '/it/tickets/'.$this->ticket->id.'/tasks/'.$a->id;
    $this->postJson($aPath.'/complete', [...($this->tuple)(), 'completion_note' => 'A1'])->assertOk();
    $this->patchJson($this->path, [...($this->tuple)(), 'status' => 'in_progress'])->assertOk();
    $this->postJson($this->path.'/complete', [...($this->tuple)(), 'evidence' => ['B1']])->assertOk();
    $bCompletion = $this->task->fresh()->current_completion_id;
    $c = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'evidence_required' => false]);
    $c->dependencies()->attach($this->task->id);
    $cPath = '/it/tickets/'.$this->ticket->id.'/tasks/'.$c->id;
    $this->postJson($aPath.'/reopen', [...($this->tuple)(), 'reason' => 'Recheck A'])->assertOk();
    $this->postJson($aPath.'/complete', [...($this->tuple)(), 'completion_note' => 'A2'])->assertOk();
    $this->postJson($cPath.'/complete', [...($this->tuple)(), 'completion_note' => 'Invalid dependent'])->assertUnprocessable();
    expect($this->task->fresh()->status)->toBe('completed')->and($this->task->fresh()->current_completion_id)->toBe($bCompletion);
    $this->postJson($this->path.'/reopen', [...($this->tuple)(), 'reason' => 'Recheck B against A2'])->assertOk();
    $this->postJson($this->path.'/complete', [...($this->tuple)(), 'evidence' => ['B2']])->assertOk();
    $this->postJson($cPath.'/complete', [...($this->tuple)(), 'completion_note' => 'Verified against B2'])->assertOk();
    expect($this->task->completions()->count())->toBe(2)->and($a->completions()->count())->toBe(2)
        ->and($c->completions()->count())->toBe(1);
});
