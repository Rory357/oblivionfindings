<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Domain\It\Services\ItWorkTaskService;
use App\Domain\It\Services\ItWorkTransitionService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\ItWorkTask;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Notification::fake();
    $this->site = Site::factory()->create();
    foreach (['requester' => 'support_worker', 'agent' => 'hr', 'other' => 'support_worker'] as $key => $role) {
        $this->{$key} = $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->sync(Role::query()->where('name', $role)->pluck('id'));
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);
    }
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->requester->id,
        'status' => 'open', 'workflow_state' => 'submitted', 'lock_version' => 1]);
    $this->url = '/it/tickets/'.$this->ticket->id.'/confirm-resolution';
});

test('required work, resolution, requester pushback, second resolution, rating and confirmation share one canonical ticket', function () {
    $interactions = app(ItTicketInteractionService::class);
    $task = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'is_required' => true,
        'evidence_required' => true, 'title' => 'Private restoration verification']);
    app(ItWorkTaskService::class)->complete($this->ticket, $task, $this->agent, ['evidence' => ['Synthetic verification reference']]);
    $resolved = $interactions->resolveWithPublicNote($this->ticket->fresh(), $this->agent, 'Service restored and verified.', resolution: ['resolution_code' => 'restored', 'resolution_verification' => 'Confirmed the restored service.']);
    $this->actingAs($this->requester)->getJson('/it/tickets/'.$this->ticket->id)->assertOk()
        ->assertJsonPath('can.confirmResolution', true)->assertJsonPath('can.manage', false);
    $this->post('/it/tickets/'.$this->ticket->id.'/reopen', ['expected_version' => $resolved->lock_version,
        'reason' => 'The service still fails after signing in.'])->assertRedirect()->assertSessionHasNoErrors();
    $this->ticket->refresh();
    expect($this->ticket->status)->toBe('open');
    $resolved = $interactions->resolveWithPublicNote($this->ticket, $this->agent, 'Verified the corrected sign-in flow.', resolution: ['resolution_code' => 'restored', 'resolution_verification' => 'Confirmed successful sign-in.']);
    $this->post('/it/tickets/'.$this->ticket->id.'/csat', ['actor_user_id' => $this->requester->id,
        'expected_version' => $resolved->lock_version, 'score' => 4, 'comment' => 'Now working.'])->assertRedirect();
    $rated = $this->ticket->fresh();
    $body = ['actor_user_id' => $this->requester->id, 'expected_version' => $rated->lock_version];
    $this->postJson($this->url, $body)->assertOk()->assertJsonPath('status', 'committed')
        ->assertJsonPath('data.operation', 'resolution.confirm')->assertJsonPath('data.viewer_user_id', $this->requester->id)
        ->assertJsonPath('data.status', 'closed')->assertJsonPath('data.lock_version', $rated->lock_version + 1);
    $saved = $this->ticket->fresh();
    expect($saved->status)->toBe('closed')->and($saved->resolution_summary)->toBe('Verified the corrected sign-in flow.')
        ->and($saved->csat_score)->toBe(4)->and($saved->reopened_count)->toBe(1)
        ->and($task->completions()->count())->toBe(1);
    $this->postJson($this->url, $body)->assertForbidden();
    expect($saved->events()->where('type', 'resolution_confirmed')->count())->toBe(1);
    $this->post('/it/tickets/'.$this->ticket->id.'/csat', ['score' => 5])->assertForbidden();
    $this->getJson('/it/tickets/'.$this->ticket->id)->assertOk()->assertJsonPath('can.confirmResolution', false)
        ->assertJsonPath('can.rate', false)->assertDontSee('Private restoration verification')->assertDontSee('Synthetic verification reference');
});

test('confirmation denies nonrequesters, mismatched actors, stale versions and open tickets without side effects', function () {
    $this->actingAs($this->requester)->postJson($this->url, ['actor_user_id' => $this->requester->id, 'expected_version' => 1])->assertForbidden();
    $resolved = app(ItTicketInteractionService::class)->resolveWithPublicNote($this->ticket, $this->agent, 'Confirmed restoration.', resolution: ['resolution_code' => 'restored', 'resolution_verification' => 'Confirmed the restored service.']);
    $before = $resolved->getRawOriginal();
    $this->actingAs($this->agent)->postJson($this->url, ['actor_user_id' => $this->agent->id, 'expected_version' => $resolved->lock_version])->assertForbidden();
    $this->actingAs($this->other)->postJson($this->url, ['actor_user_id' => $this->other->id, 'expected_version' => $resolved->lock_version])->assertNotFound();
    $this->actingAs($this->requester)->postJson($this->url, ['actor_user_id' => $this->agent->id, 'expected_version' => $resolved->lock_version])->assertForbidden();
    $this->postJson($this->url, ['actor_user_id' => $this->requester->id, 'expected_version' => 1])->assertConflict();
    expect(fn () => app(ItWorkTransitionService::class)->transition($resolved, new ItTransitionInput(
        actor: $this->agent, to: ItWorkflowState::Closed, source: 'requester_confirmation',
    )))->toThrow(DomainException::class, 'Only the requester');
    expect($this->ticket->fresh()->getRawOriginal())->toBe($before)
        ->and($this->ticket->events()->where('type', 'resolution_confirmed')->count())->toBe(0);
});

test('unfinished required work refuses requester confirmation without private titles or identifiers', function () {
    // Existing inconsistent resolved work must pass the canonical settlement gate again.
    $this->ticket->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
    ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'is_required' => true, 'title' => 'Private security clearance']);
    $version = $this->ticket->fresh()->lock_version;
    $this->actingAs($this->requester)->postJson($this->url, ['actor_user_id' => $this->requester->id, 'expected_version' => $version])
        ->assertUnprocessable()->assertJsonPath('code', 'confirmation_blocked')->assertDontSee('Private security clearance')
        ->assertJsonMissingPath('blocker');
    expect($this->ticket->fresh()->lock_version)->toBe($version)
        ->and($this->ticket->events()->where('type', 'resolution_confirmed')->count())->toBe(0);
});

test('requester confirmation audit failure rolls back closure and an explicit retry succeeds once', function () {
    $resolved = app(ItTicketInteractionService::class)->resolveWithPublicNote($this->ticket, $this->agent, 'Restoration verified.', resolution: ['resolution_code' => 'restored', 'resolution_verification' => 'Confirmed the restored service.']);
    $event = 'eloquent.creating: '.AuditLog::class;
    Event::listen($event, function (AuditLog $audit): void {
        if ($audit->action === 'it.work.transitioned') {
            throw new RuntimeException('Synthetic confirmation audit failure');
        }
    });
    $this->actingAs($this->requester);
    $input = new ItTransitionInput(actor: $this->requester, to: ItWorkflowState::Closed,
        source: 'requester_confirmation', expectedVersion: $resolved->lock_version);
    try {
        expect(fn () => app(ItWorkTransitionService::class)->transition($resolved, $input))
            ->toThrow(RuntimeException::class, 'Synthetic confirmation audit failure');
    } finally {
        Event::forget($event);
    }
    expect($this->ticket->fresh()->status)->toBe('resolved')
        ->and($this->ticket->events()->where('type', 'resolution_confirmed')->count())->toBe(0);
    expect(app(ItWorkTransitionService::class)->transition($resolved, $input)->status)->toBe('closed')
        ->and($this->ticket->events()->where('type', 'resolution_confirmed')->count())->toBe(1);
});

test('an IT requester keeps the public reopen audience even when private work access is unavailable', function () {
    $this->ticket->forceFill(['requester_user_id' => $this->agent->id, 'status' => 'resolved', 'resolved_at' => now(), 'is_sensitive' => true])->save();
    $this->actingAs($this->agent)->getJson('/it/tickets/'.$this->ticket->id)->assertOk()
        ->assertJsonPath('can.manage', false)->assertJsonPath('can.reopen', true)->assertJsonPath('can.confirmResolution', true);
    $this->post('/it/tickets/'.$this->ticket->id.'/reopen', [
        'expected_version' => $this->ticket->fresh()->lock_version, 'reason' => 'My reported issue still needs attention.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->ticket->fresh()->status)->toBe('open')->and($this->ticket->comments()->sole()->is_internal)->toBeFalse();
});
