<?php

use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Exceptions\ItTicketVersionConflict;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Domain\It\Services\ItTicketTriageService;
use App\Domain\It\Services\ItWorkTransitionService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->agent = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    foreach ([$this->agent, $this->worker] as $actor) {
        $actor->roles()->syncWithoutDetaching(Role::query()->where('name', $actor->role)->pluck('id'));
        ensureCanonicalHrStaffProfile($actor, $this->site);
    }
    $this->ticket = ItTicket::factory()->create([
        'requester_user_id' => $this->worker->id,
        'site_id' => $this->site->id,
        'work_type' => 'incident',
        'status' => 'open',
        'workflow_state' => 'submitted',
        'priority' => 'normal',
        'category' => 'hardware',
        'requires_approval' => false,
    ]);
    Notification::fake();
});

test('persisted versions are monotonic even for same-second writes from older model instances', function () {
    $this->freezeTime();
    $first = $this->ticket->fresh();
    $second = $this->ticket->fresh();
    expect($first->lock_version)->toBe(1);

    $first->update(['priority' => 'high']);
    $second->update(['subcategory' => 'Updated by another canonical writer']);

    expect($first->lock_version)->toBe(2)
        ->and($second->lock_version)->toBe(3)
        ->and($this->ticket->fresh()->lock_version)->toBe(3)
        ->and($this->ticket->fresh()->priority)->toBe('high')
        ->and($first->updated_at->equalTo($second->updated_at))->toBeTrue();

    $second->save();
    expect($second->fresh()->lock_version)->toBe(3);
});

test('a property update returns its version and a stale assignment cannot overwrite it', function () {
    $url = "/it/tickets/{$this->ticket->id}";
    $result = $this->actingAs($this->agent)->patchJson($url, [
        'priority' => 'high', 'expected_version' => 1,
    ])->assertOk();
    $version = $result->json('data.lock_version');
    expect($version)->toBeGreaterThan(1);
    $eventCount = $this->ticket->events()->count();
    $auditCount = AuditLog::query()->where('action', 'it.ticket.triage.updated')->count();

    $this->patchJson($url, [
        'assigned_to_user_id' => $this->agent->id, 'expected_version' => 1,
    ])->assertConflict()->assertJsonPath('code', 'stale_ticket')->assertJsonPath('current', [
        'id' => $this->ticket->id,
        'reference' => $this->ticket->reference,
        'lock_version' => $version,
        'url' => $url,
    ]);
    expect($this->ticket->fresh()->assigned_to_user_id)->toBeNull()
        ->and($this->ticket->fresh()->priority)->toBe('high')
        ->and($this->ticket->events()->count())->toBe($eventCount)
        ->and(AuditLog::query()->where('action', 'it.ticket.triage.updated')->count())->toBe($auditCount);

    $this->patchJson($url, [
        'assigned_to_user_id' => $this->agent->id, 'expected_version' => $version,
        'routing_reason' => 'I am taking responsibility for the investigation.',
    ])->assertOk();
    expect((int) $this->ticket->fresh()->assigned_to_user_id)->toBe($this->agent->id)
        ->and($this->ticket->fresh()->lock_version)->toBeGreaterThan($version);
});

test('inertia receives field errors and an authorized reload reference without a success flash', function () {
    $this->ticket->update(['priority' => 'high']);
    $url = "/it/tickets/{$this->ticket->id}";

    $this->actingAs($this->agent)->from($url)->withHeader('X-Inertia', 'true')->patch($url, [
        'priority' => 'urgent', 'expected_version' => 1,
    ])->assertRedirect($url)->assertSessionHasErrors('expected_version')
        ->assertSessionHas('it_ticket_conflict.lock_version', 2)->assertSessionMissing('success');
    expect($this->ticket->fresh()->priority)->toBe('high');
});

test('browser mutation routes require a positive expected version', function (string $action, array $payload) {
    if ($action === 'reopen') {
        $this->ticket->update(['status' => 'resolved', 'workflow_state' => 'resolved', 'resolved_at' => now()]);
    }
    $url = "/it/tickets/{$this->ticket->id}".($action === 'update' ? '' : '/'.$action);
    $method = $action === 'update' ? 'patchJson' : 'postJson';
    $before = $this->ticket->fresh()->getAttributes();

    foreach ([[], ['expected_version' => 0], ['expected_version' => 'invalid']] as $versionInput) {
        $this->actingAs($this->agent)->$method($url, [...$payload, ...$versionInput])
            ->assertUnprocessable()->assertJsonValidationErrors('expected_version');
    }
    expect($this->ticket->fresh()->getAttributes())->toBe($before);
})->with([
    'properties' => ['update', ['priority' => 'high']],
    'workflow' => ['transitions', ['workflow_state' => 'in_progress']],
    'resolve' => ['resolve', ['note' => 'The connection has been restored.']],
    'close' => ['close', ['reason' => 'Requester confirmed the service works.']],
    'reopen' => ['reopen', ['reason' => 'The fault has returned again.']],
]);

test('a versioned lifecycle command cannot be replayed into duplicate activity', function () {
    $url = "/it/tickets/{$this->ticket->id}/transitions";
    $payload = ['workflow_state' => 'in_progress', 'expected_version' => 1];
    $this->actingAs($this->agent)->postJson($url, $payload)->assertOk();
    $version = $this->ticket->fresh()->lock_version;
    $this->postJson($url, $payload)->assertConflict();
    expect($this->ticket->events()->where('type', 'workflow_transitioned')->count())->toBe(1)
        ->and($this->ticket->fresh()->lock_version)->toBe($version);

    $this->postJson($url, [...$payload, 'expected_version' => $version])->assertOk();
    expect($this->ticket->events()->where('type', 'workflow_transitioned')->count())->toBe(1)
        ->and($this->ticket->fresh()->lock_version)->toBe($version);
});

test('resolve close and reopen reject stale browser commands without duplicate notes or transitions', function () {
    $base = "/it/tickets/{$this->ticket->id}";
    $this->actingAs($this->agent)->postJson($base.'/resolve', [
        'expected_version' => 1, 'note' => 'The connection is restored.',
        'resolution_code' => 'restored', 'resolution_verification' => 'The requester completed a successful connection test.',
    ])->assertOk();
    $resolvedVersion = $this->ticket->fresh()->lock_version;
    $this->postJson($base.'/resolve', [
        'expected_version' => 1, 'note' => 'A duplicate resolution note.',
        'resolution_code' => 'restored', 'resolution_verification' => 'The requester completed a successful connection test.',
    ])->assertConflict();
    expect($this->ticket->comments()->count())->toBe(1);

    $this->postJson($base.'/close', [
        'expected_version' => 1, 'reason' => 'An outdated close request.',
    ])->assertConflict();
    $this->postJson($base.'/close', [
        'expected_version' => $resolvedVersion, 'reason' => 'Requester confirmed the restored service.',
    ])->assertOk();
    $closedVersion = $this->ticket->fresh()->lock_version;
    expect($closedVersion)->toBeGreaterThan($resolvedVersion);

    $this->postJson($base.'/reopen', [
        'expected_version' => $resolvedVersion, 'reason' => 'A reopen request from an old tab.',
    ])->assertConflict();
    $this->postJson($base.'/reopen', [
        'expected_version' => $closedVersion, 'reason' => 'Monitoring shows the same fault has returned.',
    ])->assertOk();
    $reopenedVersion = $this->ticket->fresh()->lock_version;
    // A fresh route policy can reject a second reopen before the service runs;
    // the service still rejects a caller holding an earlier authorized model.
    expect(fn () => app(ItTicketInteractionService::class)->resolveWithPublicNote(
        $this->ticket, $this->agent, 'Another obsolete settlement.', $closedVersion,
    ))->toThrow(ItTicketVersionConflict::class);
    expect($this->ticket->comments()->count())->toBe(2)
        ->and($this->ticket->events()->where('type', 'resolved')->count())->toBe(1)
        ->and($this->ticket->events()->where('type', 'closed')->count())->toBe(1)
        ->and($this->ticket->events()->where('type', 'reopened')->count())->toBe(1)
        ->and($this->ticket->fresh()->lock_version)->toBe($reopenedVersion);
});

test('locked commands recheck approval and role instead of trusting a cached actor', function (string $revocation) {
    $this->agent->load('roles.permissions', 'permissionOverrides');
    expect($this->agent->canDo('it.manage'))->toBeTrue();
    if ($revocation === 'approval') {
        User::query()->whereKey($this->agent->id)->update(['approved_at' => null]);
    } else {
        User::query()->whereKey($this->agent->id)->update(['role' => 'support_worker']);
        $this->agent->roles()->sync(Role::query()->where('name', 'support_worker')->pluck('id'));
    }
    $this->ticket->update(['priority' => 'high']);
    $before = $this->ticket->fresh()->getAttributes();

    expect(fn () => app(ItTicketTriageService::class)->update($this->ticket, $this->agent, [
        'priority' => 'urgent', 'expected_version' => 1,
    ]))->toThrow(AuthorizationException::class);
    expect($this->ticket->fresh()->getAttributes())->toBe($before);
})->with(['approval', 'role']);

test('a changed ticket site denies the old actor before returning a version conflict', function () {
    $this->ticket->update(['site_id' => Site::factory()->create()->id]);
    expect(fn () => app(ItTicketTriageService::class)->update($this->ticket, $this->agent, [
        'priority' => 'urgent', 'expected_version' => 1,
    ]))->toThrow(ModelNotFoundException::class);
    $this->actingAs($this->agent)->patchJson("/it/tickets/{$this->ticket->id}", [
        'priority' => 'urgent', 'expected_version' => 1,
    ])->assertNotFound()->assertJsonMissingPath('current');
    expect($this->ticket->fresh()->priority)->toBe('normal');
});

test('a requester can reopen with a current version but cannot change triage properties', function () {
    $this->ticket->update(['status' => 'resolved', 'workflow_state' => 'resolved', 'resolved_at' => now()]);
    $this->actingAs($this->worker)->patchJson("/it/tickets/{$this->ticket->id}", [
        'priority' => 'urgent', 'expected_version' => $this->ticket->lock_version,
    ])->assertForbidden()->assertJsonMissingPath('current');
    $this->postJson("/it/tickets/{$this->ticket->id}/reopen", [
        'expected_version' => 1, 'reason' => 'The connection has failed again.',
    ])->assertConflict();
    $this->postJson("/it/tickets/{$this->ticket->id}/reopen", [
        'expected_version' => $this->ticket->lock_version, 'reason' => 'The connection has failed again.',
    ])->assertOk();
    expect($this->ticket->fresh()->status)->toBe('open')
        ->and($this->ticket->comments()->sole()->is_internal)->toBeFalse();
});

test('a failed atomic command rolls its version and activity back with its ticket changes', function () {
    $before = $this->ticket->fresh()->getAttributes();
    $auditCount = AuditLog::query()->count();
    Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $audit): void {
        if ($audit->action === 'it.work.transitioned') {
            throw new RuntimeException('Isolated audit storage failure');
        }
    });

    expect(fn () => app(ItWorkTransitionService::class)->transition($this->ticket, new ItTransitionInput(
        actor: $this->agent, to: ItWorkflowState::InProgress, expectedVersion: 1,
    )))->toThrow(RuntimeException::class, 'Isolated audit storage failure');

    expect($this->ticket->fresh()->getAttributes())->toBe($before)
        ->and($this->ticket->events()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

test('bulk commands validate one version per selected ticket and skip stale rows independently', function () {
    $other = ItTicket::factory()->create([
        'requester_user_id' => $this->worker->id, 'site_id' => $this->site->id,
        'status' => 'open', 'priority' => 'normal',
    ]);
    $payload = ['ids' => [$this->ticket->id, $other->id], 'action' => 'priority', 'priority' => 'urgent',
        'priority_reason' => 'The service interruption now requires immediate attention.'];
    $this->actingAs($this->agent)->postJson('/it/tickets/bulk', [
        ...$payload, 'expected_versions' => [$this->ticket->id => 1],
    ])->assertUnprocessable()->assertJsonValidationErrors('expected_versions.'.$other->id);
    $this->ticket->update(['priority' => 'high']);

    $this->post('/it/tickets/bulk', [
        ...$payload, 'expected_versions' => [$this->ticket->id => 1, $other->id => 1],
    ])->assertRedirect()->assertSessionHas('warning', '1 ticket(s) reprioritised · 1 unchanged.')
        ->assertSessionHas('it_bulk_result.items.0.status', 'stale')
        ->assertSessionHas('it_bulk_result.items.1.status', 'updated');
    expect($this->ticket->fresh()->priority)->toBe('high')
        ->and($other->fresh()->priority)->toBe('urgent')
        ->and($this->ticket->events()->where('type', 'priority_changed')->count())->toBe(0)
        ->and($other->events()->where('type', 'priority_changed')->count())->toBe(1);
});

test('non-browser canonical adapters remain compatible and still advance persisted versions', function () {
    $updated = app(ItTicketTriageService::class)->update($this->ticket, $this->agent, ['priority' => 'high']);
    expect($updated->lock_version)->toBeGreaterThan(1);
    $version = $updated->lock_version;
    $transitioned = app(ItWorkTransitionService::class)->transition($updated, new ItTransitionInput(
        actor: $this->agent, to: ItWorkflowState::InProgress,
    ));
    expect($transitioned->lock_version)->toBeGreaterThan($version);
});

test('authorized detail and queue payloads expose the persisted ticket version', function () {
    $this->ticket->update(['priority' => 'high']);
    $this->actingAs($this->agent)->getJson("/it/tickets/{$this->ticket->id}")
        ->assertOk()->assertJsonPath('ticket.lock_version', 2);
    $this->get('/it?tab=tickets')->assertOk()->assertInertia(fn ($page) => $page
        ->where('tickets.data.0.id', $this->ticket->id)
        ->where('tickets.data.0.lock_version', 2));
    $this->actingAs($this->worker)->get('/it')->assertOk()->assertInertia(fn ($page) => $page
        ->where('myTickets.0.id', $this->ticket->id)
        ->where('myTickets.0.lock_version', 2));
});

test('migration rollback refuses to discard active concurrency versions', function () {
    $this->ticket->update(['priority' => 'high']);
    $migration = require database_path('migrations/2026_09_09_000002_add_lock_version_to_it_tickets.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'concurrency versions are in use')
        ->and($this->ticket->fresh()->lock_version)->toBe(2);
});
