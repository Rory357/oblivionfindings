<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItTicketApprovalService;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\AuditLog;
use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketApprovalNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function approvalCommandActor(Site $site, string $role = 'hr'): User
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
    Bus::fake([DispatchItTicketNotifications::class]);
    $this->site = Site::factory()->create();
    $this->actor = approvalCommandActor($this->site);
    $this->decider = approvalCommandActor($this->site);
    $this->requester = approvalCommandActor($this->site, 'support_worker');
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->requester->id,
        'requires_approval' => true, 'status' => 'open', 'workflow_state' => 'submitted', 'lock_version' => 1]);
    $this->path = '/it/tickets/'.$this->ticket->id;
    $this->tuple = fn (User $actor, int $version = 1) => ['actor_user_id' => $actor->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => $version];
    $this->proposal = fn (int $version = 1) => [...($this->tuple)($this->actor, $version), 'primary_approver_user_id' => $this->decider->id];
    $this->actingAs($this->actor);
});

test('approval JSON requires original command identity and allows only canonical request fields', function () {
    $this->postJson($this->path.'/approvals', ['reason' => 'Keep this proposal'])->assertUnprocessable()
        ->assertJsonValidationErrors(['actor_user_id', 'request_uuid', 'expected_version']);
    $this->post($this->path.'/approvals', ['actor_user_id' => $this->actor->id])->assertUnprocessable()
        ->assertJsonValidationErrors(['request_uuid', 'expected_version'])->assertSessionMissing('_old_input');
    $input = [...($this->proposal)(), 'reason' => '  Private original request  ', 'status' => 'approved',
        'approver_id' => $this->actor->id, 'request_reason' => 'Injected', 'decision_reason' => 'Injected', 'it_ticket_id' => 999999];
    $response = $this->postJson($this->path.'/approvals', $input)->assertCreated()->assertJsonPath('status', 'committed')
        ->assertJsonPath('data.viewer_user_id', $this->actor->id)->assertJsonPath('data.operation', 'approval.request')
        ->assertJsonPath('data.lock_version', 2)->assertJsonPath('data.approval_status', 'pending')
        ->assertJsonPath('data.changed', true)->assertJsonPath('data.replayed', false)->assertHeader('Cache-Control', 'no-store, private');
    $approval = ItTicketApproval::query()->sole();
    expect($approval->id)->toBe($response->json('data.approval_id'))->and($approval->request_reason)->toBe('Private original request')
        ->and($approval->request_reason_recorded_at)->not->toBeNull()->and($approval->decision_reason)->toBeNull()
        ->and($approval->approver_id)->toBeNull()->and($approval->reasonEvidence()['request']['provenance'])->toBe('recorded_at_request');
    $receipt = ItTicketCommandReceipt::query()->sole();
    expect(json_encode($receipt->getRawOriginal()))->not->toContain('Private original request')->not->toContain('Injected');
    $delivery = ItEmailDelivery::query()->sole();
    expect((int) $delivery->recipient_user_id)->toBe((int) $this->decider->id)
        ->and($delivery->notification_context['approval_id'])->toBe($approval->id)->and($delivery->status)->toBe('queued');
    Notification::assertNothingSent();
});

test('request and decision replay return original generation outcome without changing current evidence', function () {
    $request = [...($this->proposal)(), 'reason' => 'Original request'];
    $id = $this->postJson($this->path.'/approvals', $request)->assertCreated()->json('data.approval_id');
    $decision = [...($this->tuple)($this->decider, 2), 'decision' => 'reject', 'reason' => 'Decision explanation'];
    $this->actingAs($this->decider)->postJson($this->path.'/approvals/'.$id.'/decide', $decision)
        ->assertOk()->assertJsonPath('data.lock_version', 3)->assertJsonPath('data.approval_status', 'rejected');
    $approval = ItTicketApproval::query()->findOrFail($id);
    expect($approval->getRawOriginal('reason'))->toBe('Original request')->and($approval->request_reason)->toBe('Original request')
        ->and($approval->decision_reason)->toBe('Decision explanation')->and($approval->reason)->toBe('Decision explanation')
        ->and($approval->reasonEvidence()['decision']['provenance'])->toBe('recorded_at_decision');
    $before = [$approval->getRawOriginal(), AuditLog::count(), ItEmailDelivery::count(), $this->ticket->events()->count()];
    $this->postJson($this->path.'/approvals/'.$id.'/decide', $decision)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.lock_version', 3);
    $this->actingAs($this->actor)->postJson($this->path.'/approvals', $request)->assertOk()->assertJsonPath('data.approval_status', 'pending')
        ->assertJsonPath('data.lock_version', 2)->assertJsonPath('data.approval_id', $id)->assertJsonPath('data.replayed', true);
    $this->getJson($this->path.'/approval-commands/request/'.$request['request_uuid'])->assertOk()->assertJsonPath('data.lock_version', 2)
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson($this->path.'/approvals', [...$request, 'reason' => 'Changed proposal'])->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    expect([$approval->fresh()->getRawOriginal(), AuditLog::count(), ItEmailDelivery::count(), $this->ticket->events()->count()])->toBe($before);
    $this->postJson($this->path.'/approvals', [...($this->proposal)(3), 'reason' => 'A new generation'])->assertCreated()
        ->assertJsonPath('data.lock_version', 4);
    expect(ItTicketApproval::count())->toBe(2)->and($approval->fresh()->getRawOriginal())->toBe($before[0]);
});

test('stale and repeated independent approval proposals never fabricate a new change', function () {
    $this->ticket->update(['subcategory' => 'Concurrent classification']);
    $this->postJson($this->path.'/approvals', [...($this->tuple)($this->actor), 'reason' => 'Stale'])
        ->assertConflict()->assertJsonPath('code', 'stale_ticket')->assertJsonPath('current.lock_version', 2);
    expect(ItTicketApproval::count())->toBe(0)->and(ItTicketCommandReceipt::count())->toBe(0)->and(ItEmailDelivery::count())->toBe(0);
    $id = $this->postJson($this->path.'/approvals', ($this->proposal)(2))->assertCreated()->json('data.approval_id');
    $this->postJson($this->path.'/approvals', ($this->proposal)(3))->assertUnprocessable()->assertJsonPath('code', 'approval_validation_failed');
    $this->actingAs($this->decider)->postJson($this->path.'/approvals/'.$id.'/decide', [...($this->tuple)($this->decider, 2), 'decision' => 'approve'])
        ->assertConflict()->assertJsonPath('code', 'stale_ticket');
    $this->postJson($this->path.'/approvals/'.$id.'/decide', [...($this->tuple)($this->decider, 3), 'decision' => 'approve'])->assertOk();
    $before = [AuditLog::count(), ItEmailDelivery::count(), ItTicketCommandReceipt::count(), $this->ticket->fresh()->lock_version];
    $this->postJson($this->path.'/approvals/'.$id.'/decide', [...($this->tuple)($this->decider, 4), 'decision' => 'approve'])
        ->assertUnprocessable()->assertJsonPath('code', 'approval_validation_failed');
    expect([AuditLog::count(), ItEmailDelivery::count(), ItTicketCommandReceipt::count(), $this->ticket->fresh()->lock_version])->toBe($before);
});

test('an approval command tombstone permanently fences the original request or decision', function (string $operation) {
    $approval = $operation === 'decide' ? ItTicketApproval::query()->create(['it_ticket_id' => $this->ticket->id,
        'requested_by' => $this->decider->id, 'status' => 'pending']) : null;
    $input = [...($this->tuple)($this->actor), ...($approval ? ['decision' => 'approve'] : ['reason' => 'Cancelled unsent request'])];
    $path = $this->path.'/approval-commands/'.$operation.'/'.$input['request_uuid'];
    $identity = ['actor_user_id' => $this->actor->id, ...($approval ? ['approval_id' => $approval->id] : [])];
    $this->postJson($path.'/cancel', $identity)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('data.replayed', false);
    $this->getJson($path.'?'.http_build_query($identity))->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson($this->path.'/approvals'.($approval ? '/'.$approval->id.'/decide' : ''), $input)
        ->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('data.replayed', true);
    $this->postJson($path.'/cancel', $identity)->assertOk()->assertJsonPath('status', 'cancelled');
    expect($this->ticket->fresh()->lock_version)->toBe(1)->and(ItTicketApproval::count())->toBe($approval ? 1 : 0)
        ->and(ItTicketCommandReceipt::count())->toBe(1)->and(ItEmailDelivery::count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'it.ticket.approval.command.cancelled')->count())->toBe(1);
    if ($approval) {
        expect($approval->fresh()->status)->toBe('pending');
    }
})->with(['request', 'decide']);

test('cancelling an already committed approval command returns its original commit', function () {
    $input = [...($this->proposal)(), 'reason' => 'Saved request'];
    $id = $this->postJson($this->path.'/approvals', $input)->assertCreated()->json('data.approval_id');
    $this->postJson($this->path.'/approval-commands/request/'.$input['request_uuid'].'/cancel', ['actor_user_id' => $this->actor->id])
        ->assertOk()->assertJsonPath('status', 'committed')->assertJsonPath('data.approval_id', $id)->assertJsonPath('data.lock_version', 2);
    expect(ItTicketApproval::query()->sole()->status)->toBe('pending')->and($this->ticket->fresh()->lock_version)->toBe(2);
});

test('approval commands preserve original actor and nested identity across recovery and cancellation', function () {
    $input = [...($this->proposal)(), 'reason' => 'Original actor'];
    $this->actingAs($this->decider)->postJson($this->path.'/approvals', $input)->assertForbidden();
    $id = $this->actingAs($this->actor)->postJson($this->path.'/approvals', $input)->assertCreated()->json('data.approval_id');
    $this->postJson($this->path.'/approvals/'.$id.'/decide', [...($this->tuple)($this->actor, 2), 'decision' => 'approve'])->assertForbidden();
    $this->actingAs($this->decider)->getJson($this->path.'/approval-commands/request/'.$input['request_uuid'])->assertNotFound();
    $this->postJson($this->path.'/approval-commands/request/'.$input['request_uuid'].'/cancel', ['actor_user_id' => $this->actor->id])->assertForbidden();
    $other = ItTicket::factory()->create(['site_id' => $this->site->id, 'requires_approval' => true, 'status' => 'open']);
    $this->postJson('/it/tickets/'.$other->id.'/approvals/'.$id.'/decide', [...($this->tuple)($this->decider), 'decision' => 'approve'])->assertNotFound();
    $this->getJson('/it/tickets/'.$other->id.'/approval-commands/decide/'.Str::uuid().'?approval_id='.$id)->assertNotFound();
    $outside = ItTicket::factory()->create(['site_id' => Site::factory()->create()->id, 'requires_approval' => true, 'status' => 'open']);
    $this->postJson('/it/tickets/'.$outside->id.'/approvals', [...($this->tuple)($this->decider)])->assertNotFound();
    expect(ItTicketApproval::count())->toBe(1)->and(ItTicketCommandReceipt::count())->toBe(1);
});

test('approval canonical services reject a stale actor whose approval was revoked', function () {
    $stale = $this->actor->load('roles.permissions');
    expect($stale->canDo('it.manage'))->toBeTrue();
    User::query()->whereKey($stale->id)->update(['approved_at' => null]);
    expect(fn () => app(ItTicketApprovalService::class)->request($this->ticket, $stale, 'Must not persist'))->toThrow(AuthorizationException::class);
    expect(ItTicketApproval::count())->toBe(0)->and(ItEmailDelivery::count())->toBe(0)->and($this->ticket->fresh()->lock_version)->toBe(1);
});

test('settled or merged tickets deny fresh approval mutations but permit exact historical receipt recovery', function (string $state) {
    $input = [...($this->proposal)(), 'reason' => 'Historical request'];
    $id = $this->postJson($this->path.'/approvals', $input)->assertCreated()->json('data.approval_id');
    $changes = ['status' => $state === 'merged' ? 'closed' : $state];
    if ($state === 'merged') {
        $changes['merged_into_ticket_id'] = ItTicket::factory()->create(['site_id' => $this->site->id])->id;
    }
    $this->ticket->refresh()->update($changes);
    $this->getJson($this->path.'/approval-commands/request/'.$input['request_uuid'])->assertOk()->assertJsonPath('data.lock_version', 2);
    $this->actingAs($this->decider)->postJson($this->path.'/approvals/'.$id.'/decide', [...($this->tuple)($this->decider, 3), 'decision' => 'approve'])
        ->assertUnprocessable()->assertJsonPath('code', 'approval_validation_failed');
    expect(ItTicketApproval::query()->findOrFail($id)->status)->toBe('pending');
})->with(['resolved', 'closed', 'merged']);

test('legacy approval reasons remain unattributed while a new decision records only its own evidence', function () {
    $approval = ItTicketApproval::query()->create(['it_ticket_id' => $this->ticket->id,
        'requested_by' => $this->actor->id, 'status' => 'pending', 'reason' => 'Legacy observed text']);
    expect($approval->reasonEvidence()['request']['provenance'])->toBe('legacy_unattributed');
    app(ItTicketApprovalService::class)->decide($approval, $this->decider, 'reject', 'New explicit decision');
    $approval->refresh();
    expect($approval->request_reason)->toBeNull()->and($approval->request_reason_recorded_at)->toBeNull()
        ->and($approval->getRawOriginal('reason'))->toBe('Legacy observed text')
        ->and($approval->reasonEvidence()['legacy_reason'])->toBe('Legacy observed text')
        ->and($approval->reasonEvidence()['decision']['provenance'])->toBe('recorded_at_decision');
    $before = $approval->getRawOriginal();
    expect(fn () => $approval->forceFill(['decision_reason' => 'Rewritten'])->save())->toThrow(LogicException::class);
    expect(fn () => $approval->fresh()->forceFill(['status' => 'pending'])->save())->toThrow(LogicException::class);
    expect(fn () => $approval->fresh()->delete())->toThrow(LogicException::class);
    expect($approval->fresh()->getRawOriginal())->toBe($before);
});

test('an original ownerless receipt remains replayable without creating another ownerless request', function () {
    $input = [...($this->tuple)($this->actor), 'reason' => 'Previously saved original request'];
    $approval = ItTicketApproval::query()->create(['it_ticket_id' => $this->ticket->id,
        'requested_by' => $this->actor->id, 'status' => 'pending', 'reason' => $input['reason']]);
    $this->ticket->forceFill(['lock_version' => 2])->save();
    ItTicketCommandReceipt::query()->create(['actor_user_id' => $this->actor->id, 'channel' => ItTicketCommandReceipt::CHANNEL,
        'operation' => 'approval.request', 'request_uuid' => $input['request_uuid'],
        'request_hash' => hash('sha256', json_encode(['ticket_id' => (int) $this->ticket->id, 'approval_id' => null,
            'operation' => 'request', 'expected_version' => 1, 'payload' => ['reason' => $input['reason']]], JSON_THROW_ON_ERROR)),
        'it_ticket_id' => $this->ticket->id, 'committed_at' => now(), 'committed_ticket_version' => 2,
        'result_metadata' => ['state' => 'committed', 'approval_id' => (int) $approval->id, 'approval_status' => 'pending', 'changed' => true]]);
    $before = [$approval->fresh()->getRawOriginal(), AuditLog::count(), ItEmailDelivery::count(), $this->ticket->events()->count()];
    $this->postJson($this->path.'/approvals', $input)->assertOk()->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.approval_id', $approval->id)->assertJsonPath('data.lock_version', 2);
    expect([$approval->fresh()->getRawOriginal(), AuditLog::count(), ItEmailDelivery::count(), $this->ticket->events()->count()])->toBe($before)
        ->and(ItTicketApproval::count())->toBe(1)->and(ItTicketCommandReceipt::count())->toBe(1);
});

test('approval audit or notification preparation failure rolls back the record receipt version and outbox', function (string $failure) {
    $event = 'eloquent.creating: '.($failure === 'audit' ? AuditLog::class : ItEmailDelivery::class);
    $failureArmed = true;
    Event::listen($event, function ($record) use ($failure, &$failureArmed): void {
        if ($failureArmed && ($failure === 'delivery' || $record->action === 'it.ticket.approval.requested')) {
            throw new RuntimeException('Private simulated persistence detail');
        }
    });
    $input = [...($this->proposal)(), 'reason' => 'Private retryable proposal'];
    $this->postJson($this->path.'/approvals', $input)->assertStatus(500)->assertJsonPath('code', 'approval_outcome_unknown')
        ->assertDontSee('Private simulated persistence detail')->assertDontSee('Private retryable proposal');
    expect(ItTicketApproval::count())->toBe(0)->and(ItTicketCommandReceipt::count())->toBe(0)
        ->and(ItEmailDelivery::count())->toBe(0)->and($this->ticket->fresh()->lock_version)->toBe(1)->and($this->ticket->events()->count())->toBe(0);
    // Disarm only the injected failure; the real model creating hooks must
    // remain present when retrying the identical canonical command.
    $failureArmed = false;
    $this->postJson($this->path.'/approvals', $input)->assertCreated()->assertJsonPath('data.lock_version', 2);
    expect(ItTicketApproval::count())->toBe(1)->and(ItEmailDelivery::count())->toBe(1);
})->with(['audit', 'delivery']);

test('queued approval requests recheck exact generation and current decision audience before sending', function () {
    $approval = app(ItTicketApprovalService::class)->request($this->ticket, $this->actor, 'Awaiting a decision', ['primary_approver_user_id' => $this->decider->id]);
    $requestDelivery = ItEmailDelivery::query()->sole();
    app(ItTicketApprovalService::class)->decide($approval, $this->decider, 'approve');
    app(ItEmailDeliveryService::class)->dispatchPending(100, $this->ticket->id);
    expect($requestDelivery->fresh()->status)->toBe('failed');
    Notification::assertNotSentTo($this->decider, TicketApprovalNotification::class);
    Notification::assertSentTo($this->actor, TicketApprovalNotification::class);
});

test('approval schema rollback refuses recorded evidence before any DDL', function () {
    app(ItTicketApprovalService::class)->request($this->ticket, $this->actor, null, ['primary_approver_user_id' => $this->decider->id]);
    $schema = Schema::getFacadeRoot();
    Schema::shouldReceive('table')->never();
    try {
        $migration = require database_path('migrations/2026_09_09_000012_preserve_it_approval_reason_evidence.php');
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Approval reason evidence must be preserved');
    } finally {
        Schema::swap($schema);
    }
    expect(ItTicketApproval::query()->sole()->request_reason_recorded_at)->not->toBeNull();
});

test('approval failures before the controller are uncached private JSON without flashing proposals', function () {
    $private = 'PRIVATE UNSENT APPROVAL PROPOSAL';
    $input = [...($this->tuple)($this->actor), 'reason' => $private];
    $this->post($this->path.'/approvals', ['actor_user_id' => $this->actor->id, 'reason' => $private])
        ->assertUnprocessable()->assertJsonPath('code', 'approval_validation_failed')
        ->assertJsonValidationErrors(['request_uuid', 'expected_version'])->assertDontSee($private)
        ->assertSessionMissing('_old_input')->assertHeader('Cache-Control', 'no-store, private');
    $this->post('/it/tickets/999999999/approvals', $input)->assertNotFound()
        ->assertJsonPath('code', 'approval_unavailable')->assertDontSee($private)
        ->assertSessionMissing('_old_input')->assertHeader('Cache-Control', 'no-store, private');
    $this->get($this->path.'/approval-commands/request/not-a-uuid')->assertNotFound()
        ->assertJsonPath('code', 'approval_unavailable')->assertHeader('Cache-Control', 'no-store, private');
    $foreign = ItTicketApproval::query()->create(['it_ticket_id' => ItTicket::factory()->create(['site_id' => $this->site->id])->id,
        'requested_by' => $this->decider->id, 'status' => 'pending']);
    $this->post($this->path.'/approvals/'.$foreign->id.'/decide', [...$input, 'decision' => 'reject'])
        ->assertNotFound()->assertJsonPath('code', 'approval_unavailable')->assertDontSee($private)
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($this->requester)->post($this->path.'/approvals', [...$input, 'actor_user_id' => $this->requester->id])
        ->assertForbidden()->assertJsonPath('code', 'access_unavailable')->assertDontSee($private)
        ->assertSessionMissing('_old_input')->assertHeader('Cache-Control', 'no-store, private');
    Auth::logout();
    $this->post($this->path.'/approvals', $input)->assertUnauthorized()->assertJsonPath('code', 'session_expired')
        ->assertDontSee($private)->assertSessionMissing('_old_input')->assertHeader('Cache-Control', 'no-store, private');
    expect(ItTicketCommandReceipt::count())->toBe(0)->and($this->ticket->fresh()->lock_version)->toBe(1);
});

test('approval CSRF and unexpected routing failures conceal debug details before command execution', function () {
    $failure = 'csrf';
    Event::listen(RouteMatched::class, function (RouteMatched $event) use (&$failure): void {
        if (! $event->request->is('it/tickets/*/approvals')) {
            return;
        }
        if ($failure === 'csrf') {
            throw new TokenMismatchException('PRIVATE EXPIRED TOKEN DETAIL');
        }
        throw new RuntimeException('PRIVATE PRECONTROLLER DEBUG DETAIL');
    });
    $input = [...($this->tuple)($this->actor), 'reason' => 'PRIVATE UNSENT APPROVAL'];
    $this->post($this->path.'/approvals', $input)->assertStatus(419)->assertJsonPath('code', 'session_expired')
        ->assertDontSee('PRIVATE EXPIRED TOKEN DETAIL')->assertDontSee('PRIVATE UNSENT APPROVAL')
        ->assertSessionMissing('_old_input')->assertHeader('Cache-Control', 'no-store, private');
    $failure = 'unexpected';
    config(['app.debug' => true]);
    Log::spy();
    $this->post($this->path.'/approvals', $input)->assertStatus(500)->assertJsonPath('code', 'approval_outcome_unknown')
        ->assertDontSee('PRIVATE PRECONTROLLER DEBUG DETAIL')->assertDontSee('PRIVATE UNSENT APPROVAL')
        ->assertJsonMissingPath('exception')->assertSessionMissing('_old_input')->assertHeader('Cache-Control', 'no-store, private');
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context): bool => ! str_contains(json_encode([$message, $context]), 'PRIVATE'));
    expect(ItTicketApproval::count())->toBe(0)->and(ItTicketCommandReceipt::count())->toBe(0)
        ->and(ItEmailDelivery::count())->toBe(0)->and($this->ticket->fresh()->lock_version)->toBe(1);
});

test('legacy approval forms omitting the whole command tuple retain their Inertia validation contract', function () {
    $approval = ItTicketApproval::query()->create(['it_ticket_id' => $this->ticket->id,
        'requested_by' => $this->decider->id, 'status' => 'pending']);
    $this->from($this->path)->post('/it/approvals/'.$approval->id.'/decide', ['decision' => 'reject'])
        ->assertRedirect($this->path)->assertSessionHasErrors('reason');
    expect($approval->fresh()->status)->toBe('pending')->and(ItTicketCommandReceipt::count())->toBe(0);
});
