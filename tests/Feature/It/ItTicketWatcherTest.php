<?php

use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Models\AuditLog;
use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketRepliedNotification;
use App\Notifications\It\TicketReopenedNotification;
use App\Notifications\It\TicketResolvedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->requester = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    foreach ([$this->requester, $this->manager] as $actor) {
        $actor->roles()->sync(Role::query()->where('name', $actor->role)->pluck('id'));
        ensureCanonicalHrStaffProfile($actor, $this->site);
    }
    $this->viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->viewer->permissionOverrides()->attach(Permission::query()->where('key', 'it.view')->sole()->id, ['allowed' => true]);
    ensureCanonicalHrStaffProfile($this->viewer, $this->site);
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id,
        'requester_user_id' => $this->requester->id, 'assigned_to_user_id' => $this->manager->id]);
    $this->path = "/it/tickets/{$this->ticket->id}/watchers/{$this->viewer->id}";
    $this->input = ['actor_user_id' => $this->manager->id, 'expected_version' => $this->ticket->lock_version, 'watching' => true];
    Notification::fake();
});

test('an eligible watcher is added and removed with exact actor version acknowledgements and one audit per change', function () {
    $first = $this->actingAs($this->manager)->patchJson($this->path, $this->input)->assertOk()
        ->assertJsonPath('status', 'committed')->assertJsonPath('data.id', $this->ticket->id)
        ->assertJsonPath('data.viewer_user_id', $this->manager->id)->assertJsonPath('data.watcher_user_id', $this->viewer->id)
        ->assertJsonPath('data.watching', true)->assertJsonPath('data.changed', true);
    expect($first->json('data.lock_version'))->toBeGreaterThan($this->input['expected_version']);
    $this->patchJson($this->path, $this->input)->assertOk()->assertJsonPath('data.changed', false)
        ->assertJsonPath('data.lock_version', $first->json('data.lock_version'));
    $this->patchJson($this->path, [...$this->input, 'watching' => false])->assertConflict();
    $this->patchJson($this->path, [...$this->input, 'watching' => false,
        'expected_version' => $first->json('data.lock_version')])->assertOk()->assertJsonPath('data.watching', false);
    expect($this->ticket->watchers()->count())->toBe(0)
        ->and($this->ticket->events()->whereIn('type', ['watcher_added', 'watcher_removed'])->count())->toBe(2)
        ->and(AuditLog::query()->whereIn('action', ['it.ticket.watcher.added', 'it.ticket.watcher.removed'])->count())->toBe(2);
});

test('watcher administration requires the original actor and current per-record work access', function () {
    $this->actingAs($this->viewer)->patchJson($this->path, $this->input)->assertForbidden();
    $this->patchJson($this->path, [...$this->input, 'actor_user_id' => $this->viewer->id])->assertForbidden();
    $this->manager->load('roles.permissions', 'permissionOverrides');
    User::query()->whereKey($this->manager->id)->update(['approved_at' => null]);
    expect(fn () => app(ItTicketInteractionService::class)->watch($this->ticket, $this->manager))->toThrow(AuthorizationException::class);
    expect($this->ticket->watchers()->count())->toBe(0);
});

test('watcher commands validate identity and future versions and conceal unavailable targets', function () {
    $this->actingAs($this->manager)->patchJson($this->path, ['expected_version' => $this->ticket->lock_version, 'watching' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('actor_user_id');
    $this->patchJson($this->path, [...$this->input, 'watching' => false, 'expected_version' => $this->ticket->lock_version + 10])->assertConflict();
    $this->patchJson("/it/tickets/{$this->ticket->id}/watchers/999999999", $this->input)->assertNotFound();
    $this->ticket->forceFill(['site_id' => Site::factory()->create()->id, 'assigned_to_user_id' => null,
        'owner_user_id' => null, 'team_id' => null, 'queue_id' => null])->save();
    $this->patchJson($this->path, $this->input)->assertNotFound();
    expect($this->ticket->watchers()->count())->toBe(0);
});

test('fresh watcher and manager permission checks reject stale loaded grants', function () {
    $this->viewer->load('roles.permissions', 'permissionOverrides');
    $this->viewer->permissionOverrides()->updateExistingPivot(Permission::query()->where('key', 'it.view')->sole()->id, ['allowed' => false]);
    $this->actingAs($this->manager)->patchJson($this->path, $this->input)->assertUnprocessable();
    $this->manager->load('roles.permissions', 'permissionOverrides');
    $this->manager->permissionOverrides()->attach(Permission::query()->where('key', 'it.manage')->sole()->id, ['allowed' => false]);
    expect(fn () => app(ItTicketInteractionService::class)->watch($this->ticket, $this->manager))->toThrow(AuthorizationException::class);
    expect($this->ticket->watchers()->count())->toBe(0);
});

test('an audit failure rolls back the watcher membership event and ticket version', function () {
    $event = 'eloquent.creating: '.AuditLog::class;
    Event::listen($event, function (AuditLog $audit): void {
        if ($audit->action === 'it.ticket.watcher.added') {
            throw new RuntimeException('Synthetic watcher audit failure');
        }
    });
    try {
        $this->actingAs($this->manager)->patchJson($this->path, $this->input)->assertServerError();
        expect($this->ticket->watchers()->count())->toBe(0)
            ->and($this->ticket->events()->where('type', 'watcher_added')->count())->toBe(0)
            ->and($this->ticket->fresh()->lock_version)->toBe($this->input['expected_version']);
    } finally {
        Event::forget($event);
    }
});

test('watcher eligibility never grants unrelated requester-only or out-of-site accounts ticket access', function () {
    $unrelated = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $unrelated->roles()->sync(Role::query()->where('name', 'support_worker')->pluck('id'));
    ensureCanonicalHrStaffProfile($unrelated, $this->site);
    $this->actingAs($this->manager)->patchJson("/it/tickets/{$this->ticket->id}/watchers/{$unrelated->id}", $this->input)->assertUnprocessable();
    $this->viewer->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]);
    $this->patchJson($this->path, $this->input)->assertUnprocessable();
    $this->ticket->watchers()->attach($this->viewer->id); // Historical membership is not an entitlement.
    $this->actingAs($this->viewer)->getJson('/it/tickets/'.$this->ticket->id)->assertNotFound();
    $this->actingAs($this->manager)->getJson('/it/tickets/'.$this->ticket->id)->assertOk()
        ->assertJsonPath('ticket.watchers.0.receives_updates', false)
        ->assertJsonPath('watcherOptions', fn (array $options): bool => ! collect($options)->contains('id', $this->viewer->id));
    $this->patchJson($this->path, [...$this->input, 'watching' => false])->assertOk();
});

test('intake only attaches watchers who can read the resulting canonical ticket', function () {
    $input = ['request_uuid' => (string) Str::uuid(), 'title' => 'Synthetic watcher intake', 'category' => 'hardware',
        'site_id' => $this->site->id, 'priority' => 'normal', 'requester_user_id' => $this->manager->id,
        'watchers' => [$this->requester->id]];
    expect(fn () => app(ItTicketIntakeService::class)->createCommand($this->manager, $input))->toThrow(AuthorizationException::class);
    expect(ItTicket::query()->where('title', $input['title'])->count())->toBe(0);
    $created = app(ItTicketIntakeService::class)->createCommand($this->manager,
        [...$input, 'request_uuid' => (string) Str::uuid(), 'watchers' => [$this->viewer->id]])->ticket;
    expect($created->watchers()->pluck('users.id')->all())->toBe([$this->viewer->id]);
});

test('a sensitive participant can receive public updates without gaining internal watcher administration', function () {
    $this->manager->permissionOverrides()->attach(Permission::query()->where('key', 'it.viewSensitive')->sole()->id, ['allowed' => true]);
    $this->ticket->forceFill(['is_sensitive' => true, 'requested_for_user_id' => $this->viewer->id])->save();
    $this->viewer->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]);
    $this->actingAs($this->manager)->patchJson($this->path, [...$this->input,
        'expected_version' => $this->ticket->fresh()->lock_version])->assertOk();
    $this->actingAs($this->viewer)->getJson('/it/tickets/'.$this->ticket->id)->assertOk()
        ->assertJsonPath('can.manageWatchers', false)->assertJsonPath('can.internal', false)
        ->assertJsonCount(0, 'watcherOptions')->assertJsonMissingPath('ticket.watchers.0.receives_updates');
    $this->patchJson($this->path, ['actor_user_id' => $this->viewer->id,
        'expected_version' => $this->ticket->fresh()->lock_version, 'watching' => false])->assertForbidden();
});

test('removing a watcher suppresses a queued public reply while current assignee delivery remains eligible', function () {
    $this->ticket->watchers()->attach($this->viewer->id);
    $delivery = app(ItEmailDeliveryService::class);
    $delivery->prepare([$this->viewer, $this->manager], new TicketRepliedNotification($this->ticket, 'agent_side'));
    $this->actingAs($this->manager)->patchJson($this->path, [...$this->input, 'watching' => false])->assertOk();
    expect(ItEmailDelivery::query()->where('recipient_user_id', $this->viewer->id)->sole()->status)->toBe('failed');
    $delivery->dispatchPending(10, $this->ticket->id);
    Notification::assertNotSentTo($this->viewer, TicketRepliedNotification::class);
    Notification::assertSentTo($this->manager, TicketRepliedNotification::class);
    expect(ItEmailDelivery::query()->where('recipient_user_id', $this->viewer->id)->sole()->status)->toBe('failed');
});

test('watcher removal atomically stops pending watcher audiences while preserving provider outcomes and independent recipients', function () {
    $this->ticket->watchers()->attach($this->viewer->id);
    $service = app(ItEmailDeliveryService::class);
    $service->prepare($this->viewer, new TicketRepliedNotification($this->ticket, 'agent_side'));
    $service->prepare($this->viewer, new TicketResolvedNotification($this->ticket, 'watcher'));
    $service->prepare($this->viewer, new TicketReopenedNotification($this->ticket));
    $queuedIds = ItEmailDelivery::query()->pluck('id');
    $service->prepare($this->requester, new TicketResolvedNotification($this->ticket));
    $service->prepare($this->manager, new TicketReopenedNotification($this->ticket));
    $service->prepare($this->viewer, new TicketRepliedNotification($this->ticket, 'agent_side'));
    $sending = ItEmailDelivery::query()->latest('id')->firstOrFail();
    $sending->forceFill(['status' => 'sending', 'sending_at' => now(), 'last_error' => 'An earlier attempt requires reconciliation.'])->save();
    $service->prepare($this->viewer, new TicketReopenedNotification($this->ticket));
    $accepted = ItEmailDelivery::query()->latest('id')->firstOrFail();
    $accepted->forceFill(['status' => 'accepted', 'provider_status_at' => now(), 'accepted_at' => now()])->save();

    $this->actingAs($this->manager)->patchJson($this->path, [...$this->input, 'watching' => false])->assertOk();
    expect(ItEmailDelivery::query()->whereKey($queuedIds)->pluck('status')->all())->toBe(['failed', 'failed', 'failed'])
        ->and(ItEmailDelivery::query()->whereIn('recipient_user_id', [$this->requester->id, $this->manager->id])->pluck('status')->all())->toBe(['queued', 'queued'])
        ->and($sending->fresh()->status)->toBe('sending')->and($sending->fresh()->failed_at)->toBeNull()
        ->and($sending->fresh()->last_error)->toBe('An earlier attempt requires reconciliation.')
        ->and($accepted->fresh()->status)->toBe('accepted')
        ->and(AuditLog::query()->where('action', 'it.email.delivery.access_revoked')->count())->toBe(3);
});

test('watcher removal audit failure also rolls back queued delivery suppression', function () {
    $this->ticket->watchers()->attach($this->viewer->id);
    app(ItEmailDeliveryService::class)->prepare($this->viewer, new TicketResolvedNotification($this->ticket, 'watcher'));
    $event = 'eloquent.creating: '.AuditLog::class;
    Event::listen($event, function (AuditLog $audit): void {
        if ($audit->action === 'it.ticket.watcher.removed') {
            throw new RuntimeException('Synthetic watcher removal audit failure');
        }
    });
    try {
        $this->actingAs($this->manager)->patchJson($this->path, [...$this->input, 'watching' => false])->assertServerError();
        expect($this->ticket->watchers()->whereKey($this->viewer->id)->exists())->toBeTrue()
            ->and(ItEmailDelivery::query()->sole()->status)->toBe('queued')
            ->and($this->ticket->events()->where('type', 'watcher_removed')->count())->toBe(0)
            ->and($this->ticket->fresh()->lock_version)->toBe($this->input['expected_version'])
            ->and(AuditLog::query()->where('action', 'it.email.delivery.access_revoked')->count())->toBe(0);
    } finally {
        Event::forget($event);
    }
});

test('settled tickets retain watcher administration but merged originals reject membership changes', function (string $state) {
    $this->ticket->forceFill(['status' => $state === 'merged' ? 'closed' : $state,
        'merged_into_ticket_id' => $state === 'merged'
            ? ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->requester->id])->id : null])->save();
    $response = $this->actingAs($this->manager)->patchJson($this->path,
        [...$this->input, 'expected_version' => $this->ticket->fresh()->lock_version]);
    if ($state === 'merged') {
        $response->assertUnprocessable()->assertJsonValidationErrors('watcher_user_id');
        expect($this->ticket->watchers()->count())->toBe(0);
    } else {
        $response->assertOk()->assertJsonPath('data.watching', true);
        $this->getJson('/it/tickets/'.$this->ticket->id)->assertOk()->assertJsonPath('can.manageWatchers', true);
    }
})->with(['resolved', 'closed', 'merged']);
