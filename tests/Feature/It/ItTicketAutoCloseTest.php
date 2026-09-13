<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Exceptions\ItSettlementBlocked;
use App\Domain\It\Services\ItWorkTaskService;
use App\Domain\It\Services\ItWorkTransitionService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
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
    $this->actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->actor->roles()->sync(Role::query()->where('name', 'hr')->pluck('id'));
    HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id,
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'resolved',
        'workflow_state' => 'fulfilled', 'resolved_at' => now()->subDays(8), 'closed_at' => null,
        'resolution_code' => 'restored', 'resolution_summary' => 'Verified restoration.', 'lock_version' => 1]);
    $this->transitions = app(ItWorkTransitionService::class);
});

test('auto-close preserves resolution evidence and records exactly one system transition', function () {
    // A current HTTP actor must not be attributed to the scheduler.
    $this->actingAs($this->actor);
    $before = $this->ticket->fresh()->only(['resolved_at', 'resolution_code', 'resolution_summary']);
    expect($this->transitions->autoCloseResolved($this->ticket->id, now()->subDays(7), 7))->toBeTrue();
    $saved = $this->ticket->fresh();
    expect($saved->status)->toBe('closed')->and($saved->workflow_state)->toBe('closed')
        ->and($saved->closed_at)->not->toBeNull()->and($saved->lock_version)->toBe(2)
        ->and($saved->only(array_keys($before)))->toEqual($before);
    expect($this->transitions->autoCloseResolved($this->ticket->id, now()->subDays(7), 7))->toBeFalse()
        ->and($this->ticket->fresh()->lock_version)->toBe(2);
    $event = $saved->events()->where('type', 'closed')->sole();
    expect($event->actor_user_id)->toBeNull()->and($event->payload)->toMatchArray(['via' => 'auto_close', 'after_days' => 7]);
    $audit = AuditLog::query()->where('action', 'it.ticket.auto_closed')->where('auditable_id', $saved->id)->sole();
    expect($audit->user_id)->toBeNull();
    Notification::assertNothingSent();
});

test('stale candidates cannot close reopened, newly resolved, undated or merged tickets', function () {
    $survivor = ItTicket::factory()->create(['site_id' => $this->site->id]);
    foreach ([['status' => 'open'], ['resolved_at' => now()], ['resolved_at' => null],
        ['merged_into_ticket_id' => $survivor->id]] as $changes) {
        $ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'resolved',
            'resolved_at' => now()->subDays(8), ...$changes]);
        $before = $ticket->fresh()->getRawOriginal();
        expect($this->transitions->autoCloseResolved($ticket->id, now()->subDays(7), 7))->toBeFalse()
            ->and($ticket->fresh()->getRawOriginal())->toBe($before)
            ->and($ticket->events()->count())->toBe(0);
    }
});

test('scheduler leaves required work and unapproved requests open while closing eligible tickets', function () {
    $blocked = [];
    foreach (['pending', 'cancelled'] as $status) {
        $ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'resolved', 'resolved_at' => now()->subDays(8)]);
        ItWorkTask::factory()->create(['ticket_id' => $ticket->id, 'status' => $status, 'is_required' => true]);
        $blocked[] = $ticket;
    }
    foreach (['pending', 'rejected', 'expired', 'cancelled'] as $status) {
        $ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'resolved',
            'resolved_at' => now()->subDays(8), 'requires_approval' => true]);
        ItTicketApproval::query()->create(['it_ticket_id' => $ticket->id, 'requested_by' => $this->actor->id, 'status' => $status]);
        $blocked[] = $ticket;
    }
    ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'is_required' => false, 'status' => 'pending']);
    $this->artisan('it:close-resolved')->expectsOutput('Auto-closed 1 resolved ticket(s).')
        ->expectsOutput('Skipped 6 ticket(s) with unfinished work and 0 no longer eligible.')->assertSuccessful();
    expect($this->ticket->fresh()->status)->toBe('closed');
    foreach ($blocked as $ticket) {
        expect($ticket->fresh()->status)->toBe('resolved')->and($ticket->fresh()->closed_at)->toBeNull()
            ->and($ticket->events()->where('type', 'closed')->count())->toBe(0);
    }
});

test('auto-close uses current completion generations instead of completed status alone', function () {
    $this->ticket->forceFill(['status' => 'open', 'workflow_state' => 'submitted'])->save();
    $tasks = app(ItWorkTaskService::class);
    $prerequisite = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'is_required' => false]);
    $dependent = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'is_required' => true]);
    $dependent->dependencies()->attach($prerequisite->id);
    $tasks->complete($this->ticket, $prerequisite, $this->actor, []);
    $tasks->complete($this->ticket, $dependent, $this->actor, []);
    $tasks->reopen($this->ticket, $prerequisite, $this->actor, 'Recheck the prerequisite implementation.');
    $tasks->complete($this->ticket, $prerequisite, $this->actor, []);
    // An existing invalid settled record must not evade the same manual gate.
    $this->ticket->refresh()->forceFill(['status' => 'resolved', 'resolved_at' => now()->subDays(8)])->save();
    $before = $this->ticket->fresh()->getRawOriginal();
    expect(fn () => $this->transitions->autoCloseResolved($this->ticket->id, now()->subDays(7), 7))
        ->toThrow(ItSettlementBlocked::class, 'Required task evidence is no longer current.')
        ->and($this->ticket->fresh()->getRawOriginal())->toBe($before)
        ->and($this->ticket->events()->where('type', 'closed')->count())->toBe(0);
});

test('audit failure rolls back automatic closure and a later retry records it once', function () {
    $before = $this->ticket->fresh()->getRawOriginal();
    $event = 'eloquent.creating: '.AuditLog::class;
    Event::listen($event, function (AuditLog $audit): void {
        if ($audit->action === 'it.ticket.auto_closed') {
            throw new RuntimeException('Synthetic audit outage');
        }
    });
    try {
        expect(fn () => $this->transitions->autoCloseResolved($this->ticket->id, now()->subDays(7), 7))
            ->toThrow(RuntimeException::class, 'Synthetic audit outage');
    } finally {
        Event::forget($event);
    }
    expect($this->ticket->fresh()->getRawOriginal())->toBe($before)
        ->and($this->ticket->events()->where('type', 'closed')->count())->toBe(0);
    expect($this->transitions->autoCloseResolved($this->ticket->id, now()->subDays(7), 7))->toBeTrue()
        ->and($this->ticket->events()->where('type', 'closed')->count())->toBe(1);
});
