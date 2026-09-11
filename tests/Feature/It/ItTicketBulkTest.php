<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketAssignedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;

function itBulkUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->hr = itBulkUser('hr');
    $this->manager = itBulkUser('provider_manager');
    $this->worker = itBulkUser('support_worker');

    foreach ([$this->hr, $this->manager, $this->worker] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }
});

test('bulk assign hands a selection to one agent and notifies them once per ticket', function () {
    Notification::fake();
    $tickets = ItTicket::factory()->count(3)->create(['site_id' => $this->site->id]);

    $this->actingAs($this->hr)
        ->post('/it/tickets/bulk', [
            'ids' => $tickets->pluck('id')->all(),
            'expected_versions' => ItTicket::query()->whereIn('id', $tickets->pluck('id')->all())->pluck('lock_version', 'id')->all(),
            'action' => 'assign',
            'routing_reason' => 'The manager is coordinating these related requests.',
            'assigned_to_user_id' => $this->manager->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '3 ticket(s) assigned.');

    foreach ($tickets as $ticket) {
        expect($ticket->refresh()->assigned_to_user_id)->toBe($this->manager->id);
        expect($ticket->events()->where('type', 'assigned')->count())->toBe(1);
    }
    app(ItEmailDeliveryService::class)->dispatchPending();
    Notification::assertSentToTimes($this->manager, TicketAssignedNotification::class, 3);

    // Re-running the same selection changes nothing and stays silent.
    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => $tickets->pluck('id')->all(),
        'expected_versions' => ItTicket::query()->whereIn('id', $tickets->pluck('id')->all())->pluck('lock_version', 'id')->all(),
        'action' => 'assign',
        'routing_reason' => 'The manager is coordinating these related requests.',
        'assigned_to_user_id' => $this->manager->id,
    ])->assertSessionHas('info', '0 ticket(s) assigned · 3 unchanged.')
        ->assertSessionMissing('success');
    Notification::assertSentToTimes($this->manager, TicketAssignedNotification::class, 3);
});

test('bulk assign to yourself never self-notifies', function () {
    Notification::fake();
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id]);

    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$ticket->id],
        'expected_versions' => ItTicket::query()->whereIn('id', [$ticket->id])->pluck('lock_version', 'id')->all(),
        'action' => 'assign',
        'routing_reason' => 'I am taking responsibility for these requests.',
        'assigned_to_user_id' => $this->hr->id,
    ])->assertRedirect();

    expect($ticket->refresh()->assigned_to_user_id)->toBe($this->hr->id);
    Notification::assertNotSentTo($this->hr, TicketAssignedNotification::class);
});

test('bulk priority restamps the SLA clock from the new target', function () {
    // Created through the real write path so due dates are stamped low (4320/10080).
    $this->actingAs($this->worker)->post('/it/tickets', [
        'title' => 'Bulk priority fixture',
        'category' => 'hardware',
        'priority' => 'low',
    ])->assertRedirect();
    $ticket = ItTicket::query()->firstWhere('title', 'Bulk priority fixture');

    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$ticket->id],
        'expected_versions' => ItTicket::query()->whereIn('id', [$ticket->id])->pluck('lock_version', 'id')->all(),
        'action' => 'priority',
        'priority_reason' => 'The service interruption requires immediate attention.',
        'priority' => 'urgent',
    ])->assertRedirect();

    $ticket->refresh();
    expect($ticket->priority)->toBe('urgent');
    // Urgent defaults 60/240, anchored at creation — re-targeted, not restarted.
    expect($ticket->first_response_due_at->equalTo($ticket->created_at->copy()->addMinutes(60)))->toBeTrue();
    expect($ticket->events()->where('type', 'priority_changed')->count())->toBe(1);
});

test('bulk status moves working tickets and starts the waiting pause', function () {
    $open = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $resolved = ItTicket::factory()->resolved()->create(['site_id' => $this->site->id]);

    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$open->id, $resolved->id],
        'expected_versions' => ItTicket::query()->whereIn('id', [$open->id, $resolved->id])->pluck('lock_version', 'id')->all(),
        'action' => 'status',
        'status' => 'waiting',
        'waiting_party' => 'vendor',
        'waiting_reason' => 'The supplier is confirming stock availability.',
        'next_action' => 'Review the supplier response tomorrow.',
    ])->assertSessionHas('warning', '1 ticket(s) updated · 1 unchanged.');

    expect($open->refresh()->status)->toBe('waiting');
    expect($open->waiting_since)->not->toBeNull();
    expect($open->waiting_party)->toBe('vendor');
    expect($open->waiting_reason)->toBe('The supplier is confirming stock availability.');
    expect($open->next_action)->toBe('Review the supplier response tomorrow.');
    expect($resolved->refresh()->status)->toBe('resolved'); // bulk never un-resolves

    $another = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$another->id],
        'expected_versions' => ItTicket::query()->whereIn('id', [$another->id])->pluck('lock_version', 'id')->all(),
        'action' => 'status',
        'status' => 'waiting',
    ])->assertSessionHasErrors(['waiting_party', 'waiting_reason']);
    expect($another->fresh()->status)->toBe('open');

    // Settling by bare status is refused at validation — resolve needs a note.
    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$open->id],
        'expected_versions' => ItTicket::query()->whereIn('id', [$open->id])->pluck('lock_version', 'id')->all(),
        'action' => 'status',
        'status' => 'resolved',
    ])->assertSessionHasErrors('status');
});

test('bulk close requires and preserves one operational reason per ticket', function () {
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id]);

    $this->actingAs($this->hr)
        ->from('/it?tab=tickets')
        ->post('/it/tickets/bulk', [
            'ids' => [$ticket->id],
            'expected_versions' => ItTicket::query()->whereIn('id', [$ticket->id])->pluck('lock_version', 'id')->all(),
            'action' => 'close',
        ])
        ->assertRedirect('/it?tab=tickets')
        ->assertSessionHasErrors('reason');

    expect($ticket->refresh()->status)->toBe('open');

    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$ticket->id],
        'expected_versions' => ItTicket::query()->whereIn('id', [$ticket->id])->pluck('lock_version', 'id')->all(),
        'action' => 'close',
        'reason' => 'Duplicate request confirmed against the surviving ticket.',
    ])->assertSessionHas('success', '1 ticket(s) closed.');

    expect($ticket->refresh()->status)->toBe('closed')
        ->and($ticket->events()->where('type', 'closed')->first()->payload['reason'])
        ->toBe('Duplicate request confirmed against the surviving ticket.')
        ->and(AuditLog::query()
            ->where('action', 'it.ticket.closed')
            ->where('auditable_id', $ticket->id)
            ->count())->toBe(1);
});

test('bulk close settles everything still open and skips the already-closed', function () {
    $open = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $waiting = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'status' => 'waiting',
        'waiting_since' => now()->subMinutes(30),
    ]);
    $closed = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'status' => 'closed',
        'closed_at' => now(),
    ]);

    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$open->id, $waiting->id, $closed->id],
        'expected_versions' => ItTicket::query()->whereIn('id', [$open->id, $waiting->id, $closed->id])->pluck('lock_version', 'id')->all(),
        'action' => 'close',
        'reason' => 'These selected records are no longer actionable.',
    ])->assertSessionHas('warning', '2 ticket(s) closed · 1 unchanged.');

    expect($open->refresh()->status)->toBe('closed');
    $waiting->refresh();
    expect($waiting->status)->toBe('closed');
    expect((int) $waiting->sla_paused_minutes)->toBe(0)
        ->and($waiting->sla_state)->toBe('unmeasured')
        ->and($waiting->sla_policy_snapshot['pause_unit'])->toBe('legacy_unknown');
    expect($open->events()->where('type', 'closed')->count())->toBe(1);
});

test('bulk is agent-only and constrained to canonical Site access', function () {
    $inaccessibleSite = Site::factory()->create();
    $inaccessible = ItTicket::factory()->create(['site_id' => $inaccessibleSite->id]);
    $mine = ItTicket::factory()->create(['site_id' => $this->site->id]);

    $this->actingAs($this->worker)->post('/it/tickets/bulk', [
        'ids' => [$mine->id],
        'expected_versions' => ItTicket::query()->whereIn('id', [$mine->id])->pluck('lock_version', 'id')->all(),
        'action' => 'close',
        'reason' => 'A requester must not be able to close queue work.',
    ])->assertForbidden();

    $this->actingAs($this->hr)->post('/it/tickets/bulk', [
        'ids' => [$inaccessible->id, $mine->id],
        'expected_versions' => ItTicket::query()->whereIn('id', [$inaccessible->id, $mine->id])->pluck('lock_version', 'id')->all(),
        'action' => 'close',
        'reason' => 'Close only the authorised Site work item.',
    ])->assertSessionHas('warning', '1 ticket(s) closed · 1 unchanged.');

    expect($inaccessible->refresh()->status)->toBe('open')
        ->and($mine->refresh()->status)->toBe('closed');
});

test('bulk outcomes preserve selection order and conceal unavailable records while explaining stale and blocked rows', function () {
    $updated = ItTicket::factory()->create(['site_id' => $this->site->id, 'priority' => 'normal']);
    $stale = ItTicket::factory()->create(['site_id' => $this->site->id, 'priority' => 'normal']);
    $blocked = ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'closed']);
    $hidden = ItTicket::factory()->create(['site_id' => Site::factory()->create()->id, 'title' => 'Private hidden title']);
    $participant = ItTicket::factory()->create([
        'site_id' => $this->site->id, 'requester_user_id' => $this->hr->id, 'is_sensitive' => true,
    ]);
    $missingId = $participant->id + 10000;
    $stale->update(['priority' => 'high']);
    $ids = [$missingId, $stale->id, $updated->id, $hidden->id, $blocked->id, $participant->id];

    $response = $this->actingAs($this->hr)->postJson('/it/tickets/bulk', [
        'ids' => $ids, 'expected_versions' => array_fill_keys($ids, 1),
        'action' => 'priority', 'priority' => 'urgent', 'priority_reason' => 'Current service interruption.',
    ])->assertOk()->assertJsonPath('result.selected', 6)->assertJsonPath('result.updated', 1)
        ->assertJsonPath('result.rejected', 5)->assertJsonPath('result.unchanged', 0);

    $items = $response->json('result.items');
    expect(array_column($items, 'id'))->toBe($ids)
        ->and(array_column($items, 'status'))->toBe(['unavailable', 'stale', 'updated', 'unavailable', 'blocked', 'unavailable'])
        ->and($items[0]['message'])->toBe($items[3]['message'])->toBe($items[5]['message']);
    foreach ($items as $item) {
        expect(array_keys($item))->toBe(['id', 'status', 'message']);
    }
    expect($response->getContent())->not->toContain($hidden->title)->not->toContain($hidden->reference)
        ->and($updated->fresh()->priority)->toBe('urgent')
        ->and($stale->fresh()->priority)->toBe('high')
        ->and($hidden->events()->count())->toBe(0)
        ->and($participant->events()->count())->toBe(0);
});

test('bulk unassign accepts an explicit null with its routing reason and rejects missing or duplicate selection input', function () {
    Notification::fake();
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'assigned_to_user_id' => $this->manager->id]);
    $payload = ['ids' => [$ticket->id], 'expected_versions' => [$ticket->id => 1], 'action' => 'assign', 'routing_reason' => 'Return to the accountable queue.'];

    $this->actingAs($this->hr)->postJson('/it/tickets/bulk', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('assigned_to_user_id');
    $this->postJson('/it/tickets/bulk', [...$payload, 'ids' => [$ticket->id, $ticket->id], 'assigned_to_user_id' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('ids.0');
    $this->postJson('/it/tickets/bulk', [...$payload, 'assigned_to_user_id' => null])
        ->assertOk()->assertJsonPath('result.items.0.status', 'updated');
    expect($ticket->fresh()->assigned_to_user_id)->toBeNull()
        ->and($ticket->fresh()->routing_override['reason'])->toBe('Return to the accountable queue.');
    Notification::assertNothingSent();
});

test('one failed bulk transaction is reported without rolling back successful siblings or exposing exception content', function () {
    Exceptions::fake();
    $failed = ItTicket::factory()->create(['site_id' => $this->site->id, 'priority' => 'normal']);
    $sibling = ItTicket::factory()->create(['site_id' => $this->site->id, 'priority' => 'normal']);
    Event::listen('eloquent.updating: '.ItTicket::class, function (ItTicket $ticket) use ($failed): void {
        if ($ticket->id === $failed->id && $ticket->isDirty('priority')) {
            throw new RuntimeException('Private storage failure detail');
        }
    });
    $response = $this->actingAs($this->hr)->postJson('/it/tickets/bulk', [
        'ids' => [$failed->id, $sibling->id], 'expected_versions' => [$failed->id => 1, $sibling->id => 1],
        'action' => 'priority', 'priority' => 'high', 'priority_reason' => 'Team impact reviewed.',
    ])->assertOk()->assertJsonPath('result.items.0.status', 'failed')->assertJsonPath('result.items.1.status', 'updated');
    expect($response->getContent())->not->toContain('Private storage failure detail')
        ->and($failed->fresh()->priority)->toBe('normal')->and($failed->fresh()->lock_version)->toBe(1)
        ->and($failed->events()->count())->toBe(0)->and($sibling->fresh()->priority)->toBe('high');
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Private storage failure detail');
});

test('bulk result receipts are projected only to the actor who performed the selection', function () {
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $this->actingAs($this->hr)->from('/it?tab=tickets')->post('/it/tickets/bulk', [
        'ids' => [$ticket->id], 'expected_versions' => [$ticket->id => 1], 'action' => 'priority',
        'priority' => 'high', 'priority_reason' => 'Reviewed impact.',
    ])->assertRedirect('/it?tab=tickets');
    $receipt = session('it_bulk_result');
    $this->get('/it?tab=tickets')->assertOk()->assertInertia(fn ($page) => $page
        ->where('bulkResult.items.0.id', $ticket->id)->missing('bulkResult.actor_user_id'));
    $this->withSession(['it_bulk_result' => $receipt])->actingAs($this->manager)->get('/it?tab=tickets')
        ->assertOk()->assertInertia(fn ($page) => $page->where('bulkResult', null));
});

test('bulk waiting binds the originating browser actor before writing private operational evidence', function () {
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $before = $ticket->refresh()->getRawOriginal();
    $this->actingAs($this->manager)->postJson('/it/tickets/bulk', [
        'actor_user_id' => $this->hr->id, 'ids' => [$ticket->id],
        'expected_versions' => [$ticket->id => $ticket->lock_version],
        'action' => 'status', 'status' => 'waiting', 'waiting_party' => 'vendor',
        'waiting_reason' => 'Private reason from a previous signed-in account',
    ])->assertForbidden();
    expect($ticket->refresh()->getRawOriginal())->toBe($before)
        ->and($ticket->events()->count())->toBe(0);
});

test('bulk waiting acknowledges the actual viewer and exact selected outcomes in JSON', function () {
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $this->actingAs($this->hr)->postJson('/it/tickets/bulk', [
        'actor_user_id' => $this->hr->id, 'ids' => [$ticket->id],
        'expected_versions' => [$ticket->id => $ticket->lock_version],
        'action' => 'status', 'status' => 'waiting', 'waiting_party' => 'vendor',
        'waiting_reason' => 'Waiting for the agreed replacement',
    ])->assertOk()->assertJsonPath('status', 'completed')
        ->assertJsonPath('viewer_user_id', $this->hr->id)
        ->assertJsonPath('result.action', 'status')->assertJsonPath('result.selected', 1)
        ->assertJsonPath('result.items.0.id', $ticket->id)->assertJsonPath('result.items.0.status', 'updated');
    expect($ticket->refresh()->status)->toBe('waiting');
});
