<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItTicketApprovalService;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\AuditLog;
use App\Models\ItAutomationRun;
use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function responsibilityActor(Site $site): User
{
    $actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $actor->roles()->sync(Role::query()->where('name', 'hr')->pluck('id'));
    HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);

    return $actor;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Notification::fake();
    Bus::fake([DispatchItTicketNotifications::class]);
    $this->site = Site::factory()->create();
    $this->raiser = responsibilityActor($this->site);
    $this->primary = responsibilityActor($this->site);
    $this->cover = responsibilityActor($this->site);
    $this->unrelated = responsibilityActor($this->site);
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'requires_approval' => true,
        'status' => 'open', 'workflow_state' => 'submitted', 'lock_version' => 1]);
    $this->path = '/it/tickets/'.$this->ticket->id;
    $this->tuple = fn (User $actor, int $version) => ['actor_user_id' => $actor->id, 'expected_version' => $version, 'request_uuid' => (string) Str::uuid()];
    $this->proposal = fn () => [...($this->tuple)($this->raiser, (int) $this->ticket->fresh()->lock_version),
        'reason' => 'Canonical assignment request', 'primary_approver_user_id' => $this->primary->id,
        'cover_approver_user_id' => $this->cover->id, 'expires_at' => now()->addDays(2)->toIso8601String(),
        'remind_at' => now()->addDay()->toIso8601String()];
    $this->actingAs($this->raiser);
});

test('named responsibility is persisted separately and only the current owner receives the request', function () {
    $input = ($this->proposal)();
    $this->postJson($this->path.'/approvals', $input)->assertCreated();
    $approval = ItTicketApproval::query()->sole();
    expect($approval->primary_approver_user_id)->toBe($this->primary->id)
        ->and($approval->cover_approver_user_id)->toBe($this->cover->id)
        ->and($approval->assignment_recorded_at)->not->toBeNull()->and($approval->approver_id)->toBeNull()
        ->and(ItEmailDelivery::query()->sole()->recipient_user_id)->toBe($this->primary->id);
    $version = (int) $this->ticket->fresh()->lock_version;
    foreach ([$this->raiser, $this->cover, $this->unrelated] as $denied) {
        $this->actingAs($denied)->postJson($this->path.'/approvals/'.$approval->id.'/decide',
            [...($this->tuple)($denied, $version), 'decision' => 'approve'])->assertForbidden();
    }
    $this->actingAs($this->primary)->postJson($this->path.'/approvals/'.$approval->id.'/decide',
        [...($this->tuple)($this->primary, $version), 'decision' => 'approve'])->assertOk();
    expect($approval->fresh()->decision_authority)->toBe('primary')->and($approval->fresh()->approver_id)->toBe($this->primary->id)
        ->and(ItTicketCommandReceipt::count())->toBe(2);
});

test('new requests require a named approver through JSON legacy forms and the canonical service', function () {
    $input = [...($this->tuple)($this->raiser, 1), 'reason' => 'Missing responsibility'];
    $this->postJson($this->path.'/approvals', $input)->assertUnprocessable()->assertJsonValidationErrors('primary_approver_user_id');
    $this->post($this->path.'/approvals', ['reason' => 'Older form without an owner'])->assertSessionHasErrors('primary_approver_user_id');
    expect(fn () => app(ItTicketApprovalService::class)->request($this->ticket, $this->raiser, 'Direct ownerless request'))
        ->toThrow(ValidationException::class);
    expect(ItTicketApproval::count())->toBe(0)->and(ItTicketCommandReceipt::count())->toBe(0)
        ->and(ItEmailDelivery::count())->toBe(0)->and($this->ticket->fresh()->lock_version)->toBe(1);
});

test('an expired request can be replaced without waiting for the scheduler and invalid replacement leaves evidence unchanged', function () {
    $this->postJson($this->path.'/approvals', ($this->proposal)())->assertCreated();
    $original = ItTicketApproval::query()->sole();
    $this->travel(3)->days();
    expect($original->fresh()->status)->toBe('pending')->and($this->raiser->can('requestApproval', $this->ticket->fresh()))->toBeTrue();
    $this->postJson($this->path.'/approvals', [...($this->proposal)(), 'primary_approver_user_id' => $this->raiser->id])
        ->assertUnprocessable()->assertJsonValidationErrors('primary_approver_user_id');
    expect($original->fresh()->status)->toBe('pending')->and($this->ticket->fresh()->lock_version)->toBe(2);
    $input = ($this->proposal)();
    $this->postJson($this->path.'/approvals', $input)->assertCreated()->assertJsonPath('data.lock_version', 3);
    expect($original->fresh()->status)->toBe('expired')->and($original->fresh()->expired_at)->not->toBeNull()
        ->and($original->fresh()->approver_id)->toBeNull()->and($original->fresh()->decided_at)->toBeNull()
        ->and(ItTicketApproval::count())->toBe(2)->and(ItTicketCommandReceipt::count())->toBe(2);
    expect(app(ItTicketApprovalService::class)->processTiming($original->id))->toBe('skipped');
    $this->postJson($this->path.'/approvals', $input)->assertOk()->assertJsonPath('data.replayed', true);
    expect($this->ticket->events()->where('type', 'approval_expired')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.ticket.approval.expired')->count())->toBe(1)
        ->and($this->ticket->fresh()->lock_version)->toBe(3);
});

test('eligible cover needs current canonical unavailability and retains the actual authority basis', function () {
    $this->postJson($this->path.'/approvals', ($this->proposal)())->assertCreated();
    $approval = ItTicketApproval::query()->sole();
    HrLeaveRequest::factory()->create(['user_id' => $this->primary->id, 'status' => 'approved',
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    expect($this->cover->can('decide', $approval))->toBeTrue()->and($this->primary->can('decide', $approval))->toBeFalse();
    $this->actingAs($this->cover)->postJson($this->path.'/approvals/'.$approval->id.'/decide',
        [...($this->tuple)($this->cover, 2), 'decision' => 'reject', 'reason' => 'Needs revised evidence'])->assertOk();
    expect($approval->fresh()->decision_authority)->toBe('cover_primary_on_approved_leave')
        ->and($approval->fresh()->approver_id)->toBe($this->cover->id)->and($approval->fresh()->decision_reason)->toBe('Needs revised evidence');
});

test('self duplicate out-of-site and inactive assignment choices fail without new canonical records', function () {
    $outside = responsibilityActor(Site::factory()->create());
    foreach ([$this->raiser->id, $outside->id] as $id) {
        $this->postJson($this->path.'/approvals', [...($this->proposal)(), 'primary_approver_user_id' => $id])
            ->assertUnprocessable()->assertJsonValidationErrors('primary_approver_user_id');
    }
    $this->postJson($this->path.'/approvals', [...($this->proposal)(), 'cover_approver_user_id' => $this->primary->id])
        ->assertUnprocessable()->assertJsonValidationErrors('cover_approver_user_id');
    HrEmployeeProfile::query()->where('user_id', $this->primary->id)->update(['is_active' => false]);
    $this->postJson($this->path.'/approvals', ($this->proposal)())->assertUnprocessable()->assertJsonValidationErrors('primary_approver_user_id');
    expect(ItTicketApproval::count())->toBe(0)->and(ItTicketCommandReceipt::count())->toBe(0)
        ->and(ItEmailDelivery::count())->toBe(0)->and((int) $this->ticket->fresh()->lock_version)->toBe(1);
});

test('deadline input is explicit and stable replay survives elapsed timing and changed eligibility', function () {
    $input = ($this->proposal)();
    $this->postJson($this->path.'/approvals', [...$input, 'expires_at' => now()->subMinute()->toIso8601String()])
        ->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    $this->postJson($this->path.'/approvals', [...$input, 'remind_at' => $input['expires_at']])
        ->assertUnprocessable()->assertJsonValidationErrors('remind_at');
    $this->postJson($this->path.'/approvals', [...$input, 'expires_at' => '2026-12-01 10:00:00'])
        ->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    $this->postJson($this->path.'/approvals', $input)->assertCreated();
    $approval = ItTicketApproval::query()->sole();
    $this->travel(3)->days();
    expect($approval->fresh()->effectiveStatus())->toBe('expired')->and($approval->fresh()->status)->toBe('pending');
    $this->actingAs($this->primary)->postJson($this->path.'/approvals/'.$approval->id.'/decide',
        [...($this->tuple)($this->primary, 2), 'decision' => 'approve'])->assertUnprocessable();
    HrEmployeeProfile::query()->where('user_id', $this->primary->id)->update(['is_active' => false]);
    $this->actingAs($this->raiser)->postJson($this->path.'/approvals', $input)->assertOk()
        ->assertJsonPath('data.replayed', true)->assertJsonPath('data.approval_status', 'pending')->assertJsonPath('data.lock_version', 2);
    expect(ItTicketApproval::count())->toBe(1)->and(ItTicketCommandReceipt::count())->toBe(1);
});

test('deadline processing expires once without inventing a human decision and permits a new generation', function () {
    $this->postJson($this->path.'/approvals', ($this->proposal)())->assertCreated();
    $approval = ItTicketApproval::query()->sole();
    $this->travel(3)->days();
    $service = app(ItTicketApprovalService::class);
    expect($service->processTiming($approval->id))->toBe('expired')->and($service->processTiming($approval->id))->toBe('skipped');
    $expired = $approval->fresh();
    expect($expired->status)->toBe('expired')->and($expired->expired_at)->not->toBeNull()
        ->and($expired->approver_id)->toBeNull()->and($expired->decided_at)->toBeNull()->and($expired->decision_reason_recorded_at)->toBeNull()
        ->and($this->ticket->events()->where('type', 'approval_expired')->count())->toBe(1)
        ->and(ItEmailDelivery::where('notification_context->event', 'expired')->count())->toBe(1)
        ->and(AuditLog::where('action', 'it.ticket.approval.expired')->sole()->user_id)->toBeNull()
        ->and((int) $this->ticket->fresh()->lock_version)->toBe(3);
    $this->postJson($this->path.'/approvals', ($this->proposal)())->assertCreated();
    expect(ItTicketApproval::count())->toBe(2)->and($expired->fresh()->getRawOriginal())->toBe($expired->getRawOriginal());
    Notification::assertNothingSent();
});

test('a single reminder prepares a durable intent for active cover and expired work never sends a late reminder', function () {
    $this->postJson($this->path.'/approvals', ($this->proposal)())->assertCreated();
    $approval = ItTicketApproval::query()->sole();
    $this->travel(25)->hours();
    HrLeaveRequest::factory()->create(['user_id' => $this->primary->id, 'status' => 'approved',
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    $service = app(ItTicketApprovalService::class);
    expect($service->processTiming($approval->id))->toBe('reminder_prepared')->and($service->processTiming($approval->id))->toBe('skipped');
    $reminder = ItEmailDelivery::where('notification_context->event', 'reminder')->sole();
    expect($reminder->recipient_user_id)->toBe($this->cover->id)->and($reminder->status)->toBe('queued')
        ->and($approval->fresh()->reminder_prepared_at)->not->toBeNull()->and($this->ticket->events()->where('type', 'approval_reminder_prepared')->count())->toBe(1);
    $this->travel(2)->days();
    expect($service->processTiming($approval->id))->toBe('expired')
        ->and(ItEmailDelivery::where('notification_context->event', 'reminder')->count())->toBe(1);
    Notification::assertNothingSent();
});

test('failed reminder audit rolls back the marker and outbox so the exact due work can retry', function () {
    $this->postJson($this->path.'/approvals', ($this->proposal)())->assertCreated();
    $approval = ItTicketApproval::query()->sole();
    $this->travel(25)->hours();
    $armed = true;
    Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $log) use (&$armed): void {
        if ($armed && $log->action === 'it.ticket.approval.reminder.prepared') {
            throw new RuntimeException('Synthetic audit unavailable');
        }
    });
    $service = app(ItTicketApprovalService::class);
    expect(fn () => $service->processTiming($approval->id))->toThrow(RuntimeException::class);
    expect($approval->fresh()->reminder_prepared_at)->toBeNull()->and(ItEmailDelivery::where('notification_context->event', 'reminder')->count())->toBe(0)
        ->and((int) $this->ticket->fresh()->lock_version)->toBe(2);
    $armed = false;
    expect($service->processTiming($approval->id))->toBe('reminder_prepared');
});

test('cancellation is a separate audited generation outcome with original command recovery', function () {
    $this->postJson($this->path.'/approvals', ($this->proposal)())->assertCreated();
    $approval = ItTicketApproval::query()->sole();
    $url = $this->path.'/approvals/'.$approval->id.'/withdraw';
    $this->actingAs($this->unrelated)->postJson($url, [...($this->tuple)($this->unrelated, 2), 'reason' => 'Not mine'])->assertForbidden();
    $input = [...($this->tuple)($this->raiser, 2), 'reason' => 'Replace the request with revised scope'];
    $this->actingAs($this->raiser)->postJson($url, $input)->assertOk()->assertJsonPath('data.approval_status', 'cancelled')
        ->assertJsonPath('data.operation', 'approval.withdraw')->assertJsonPath('data.lock_version', 3);
    $this->postJson($url, $input)->assertOk()->assertJsonPath('data.replayed', true);
    $this->getJson($this->path.'/approval-commands/withdraw/'.$input['request_uuid'].'?approval_id='.$approval->id)
        ->assertOk()->assertJsonPath('data.approval_status', 'cancelled');
    expect($approval->fresh()->cancellation_reason)->toBe($input['reason'])->and($approval->fresh()->cancelled_by_user_id)->toBe($this->raiser->id)
        ->and($approval->fresh()->approver_id)->toBeNull()->and($this->ticket->events()->where('type', 'approval_cancelled')->count())->toBe(1);
    expect(fn () => $approval->fresh()->update(['expires_at' => now()->addYear()]))->toThrow(LogicException::class);
    expect(fn () => $approval->fresh()->update(['cancellation_reason' => 'Rewritten']))->toThrow(LogicException::class);
});

test('timing command is bounded recorded and returns failure when no eligible reminder owner exists', function () {
    $this->postJson($this->path.'/approvals', ($this->proposal)())->assertCreated();
    $approval = ItTicketApproval::query()->sole();
    $this->travel(25)->hours();
    HrEmployeeProfile::query()->whereIn('user_id', [$this->primary->id, $this->cover->id])->update(['is_active' => false]);
    $this->artisan('it:check-approval-deadlines', ['--limit' => 1])->assertFailed();
    expect(ItAutomationRun::query()->sole()->result_summary['deferred'])->toBe(1)
        ->and($approval->fresh()->reminder_last_checked_at)->not->toBeNull()->and($approval->fresh()->reminder_prepared_at)->toBeNull();
    HrEmployeeProfile::query()->where('user_id', $this->cover->id)->update(['is_active' => true]);
    $this->artisan('it:check-approval-deadlines', ['--limit' => 1])->assertSuccessful();
    expect(ItAutomationRun::query()->latest('id')->first()->status)->toBe('succeeded')
        ->and(ItEmailDelivery::where('notification_context->event', 'reminder')->count())->toBe(1);
    Notification::assertNothingSent();
});

test('pending notification delivery rechecks the current primary cover and deadline boundary', function () {
    $this->postJson($this->path.'/approvals', ($this->proposal)())->assertCreated();
    $delivery = ItEmailDelivery::query()->sole();
    $this->travel(3)->days();
    app(ItEmailDeliveryService::class)->dispatchPending(10);
    expect($delivery->fresh()->status)->not->toBe('accepted')->and($delivery->fresh()->accepted_at)->toBeNull();
    Notification::assertNothingSent();
});

test('responsibility evidence cannot be silently reassigned or removed by a lossy migration', function () {
    $this->postJson($this->path.'/approvals', ($this->proposal)())->assertCreated();
    $approval = ItTicketApproval::query()->sole();
    expect(fn () => $approval->update(['primary_approver_user_id' => $this->unrelated->id]))->toThrow(LogicException::class);
    $migration = require database_path('migrations/2026_09_09_000013_add_it_approval_responsibility_and_timing.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect($approval->fresh()->primary_approver_user_id)->toBe($this->primary->id);
});

test('revoked primary authority is denied on submission and only current eligible cover may replace it', function () {
    $this->postJson($this->path.'/approvals', ($this->proposal)())->assertCreated();
    $approval = ItTicketApproval::query()->sole();
    HrEmployeeProfile::query()->where('user_id', $this->primary->id)->update(['is_active' => false]);
    $this->actingAs($this->primary)->postJson($this->path.'/approvals/'.$approval->id.'/decide',
        [...($this->tuple)($this->primary, 2), 'decision' => 'approve'])->assertNotFound();
    $this->actingAs($this->cover)->postJson($this->path.'/approvals/'.$approval->id.'/decide',
        [...($this->tuple)($this->cover, 2), 'decision' => 'approve'])->assertOk();
    expect($approval->fresh()->decision_authority)->toBe('cover_primary_ineligible');
});
