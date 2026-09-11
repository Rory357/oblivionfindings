<?php

use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Services\ItTicketTriageService;
use App\Domain\It\Services\ItWorkTransitionService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Notification::fake();
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    foreach (['agent' => 'hr', 'requester' => 'support_worker'] as $name => $role) {
        $actor = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $actor->roles()->syncWithoutDetaching(Role::query()->where('name', $role)->pluck('id'));
        ensureCanonicalHrStaffProfile($actor, $this->site);
        $this->{$name} = $actor;
    }
    $this->ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id, 'requester_user_id' => $this->requester->id,
        'work_type' => 'incident', 'workflow_state' => 'resolved', 'status' => 'resolved',
        'resolved_at' => now()->subDay(), 'resolution_code' => 'restored',
        'resolution_summary' => 'Synthetic repair.', 'resolution_verification' => 'Two checks passed.',
    ]);
    $this->actingAs($this->agent);
});

test('close binds the original actor and acknowledges only the normalized committed operation', function () {
    $version = $this->ticket->lock_version;
    $url = '/it/tickets/'.$this->ticket->id.'/close';
    $this->postJson($url, ['actor_user_id' => $this->requester->id, 'expected_version' => $version, 'reason' => 'Private previous user reason.'])
        ->assertForbidden()->assertJsonMissingPath('data');
    expect($this->ticket->fresh()->status)->toBe('resolved');
    $response = $this->postJson($url, ['actor_user_id' => $this->agent->id, 'expected_version' => $version, 'reason' => '  Confirmed synthetic recovery.  '])
        ->assertOk()->assertJsonPath('status', 'committed')->assertJsonPath('data.operation', 'ticket.close')
        ->assertJsonPath('data.id', $this->ticket->id)->assertJsonPath('data.viewer_user_id', $this->agent->id)
        ->assertJsonPath('data.status', 'closed')->assertJsonPath('data.reason', 'Confirmed synthetic recovery.');
    $saved = $this->ticket->fresh();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->json('data.lock_version'))->toBe($saved->lock_version)->toBe($version + 1)
        ->and($saved->resolution_verification)->toBe('Two checks passed.')
        ->and($saved->events()->where('type', 'closed')->sole()->payload['reason'])->toBe('Confirmed synthetic recovery.');
    $this->postJson($url, ['actor_user_id' => $this->agent->id, 'expected_version' => $version, 'reason' => 'Repeat close.'])
        ->assertConflict()->assertJsonMissingPath('data');
    $this->postJson($url, ['actor_user_id' => $this->agent->id, 'expected_version' => $saved->lock_version, 'reason' => 'Repeat close.'])
        ->assertUnprocessable()->assertJsonPath('code', 'close_unavailable')->assertJsonMissingPath('data');
    expect($saved->events()->where('type', 'closed')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.ticket.closed')->count())->toBe(1);
});

test('close reasons are bounded for HTTP dedicated and generic writers', function (string $reason) {
    $this->postJson('/it/tickets/'.$this->ticket->id.'/close', [
        'actor_user_id' => $this->agent->id, 'expected_version' => $this->ticket->lock_version, 'reason' => $reason,
    ])->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect(fn () => app(ItTicketTriageService::class)->closeWithReason($this->ticket, $this->agent, $reason))
        ->toThrow(DomainException::class, 'Record a reason');
    expect(fn () => app(ItWorkTransitionService::class)->transition($this->ticket, new ItTransitionInput(
        actor: $this->agent, to: ItWorkflowState::Closed, reason: $reason,
    )))->toThrow(DomainException::class, 'Record a reason');
    expect($this->ticket->fresh()->status)->toBe('resolved')->and($this->ticket->events()->count())->toBe(0);
})->with([[''], ['   '], [str_repeat('x', 1001)]]);

test('close audit failure rolls back version state and history before a deliberate retry', function () {
    $fail = true;
    Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $audit) use (&$fail) {
        if ($fail && $audit->action === 'it.ticket.closed') {
            throw new RuntimeException('Synthetic close audit failure');
        }
    });
    $close = fn () => app(ItTicketTriageService::class)->closeWithReason($this->ticket, $this->agent, 'Reviewed synthetic closure.', expectedVersion: $this->ticket->lock_version);
    expect($close)->toThrow(RuntimeException::class);
    expect($this->ticket->fresh()->status)->toBe('resolved')
        ->and($this->ticket->fresh()->lock_version)->toBe($this->ticket->lock_version)
        ->and($this->ticket->events()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'it.work.transitioned')->count())->toBe(0);
    $fail = false;
    $saved = $close();
    expect($saved->status)->toBe('closed')->and($saved->lock_version)->toBe($this->ticket->lock_version + 1)
        ->and($saved->events()->where('type', 'closed')->count())->toBe(1);
});

test('a requester cannot use the private close route and bulk closure reports each selected record honestly', function () {
    $this->actingAs($this->requester)->postJson('/it/tickets/'.$this->ticket->id.'/close', [
        'actor_user_id' => $this->requester->id, 'expected_version' => $this->ticket->lock_version, 'reason' => 'Private work bypass.',
    ])->assertForbidden();
    $other = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->requester->id, 'status' => 'open', 'workflow_state' => 'submitted']);
    $staleVersion = $other->lock_version;
    $other->forceFill(['title' => 'Concurrent synthetic edit'])->save();
    $this->actingAs($this->agent)->postJson('/it/tickets/bulk', [
        'actor_user_id' => $this->agent->id, 'action' => 'close', 'ids' => [$this->ticket->id, $other->id],
        'expected_versions' => [$this->ticket->id => $this->ticket->lock_version, $other->id => $staleVersion],
        'reason' => 'Reviewed synthetic closure.',
    ])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('viewer_user_id', $this->agent->id)
        ->assertJsonPath('result.action', 'close')->assertJsonPath('result.selected', 2)
        ->assertJsonPath('result.updated', 1)->assertJsonPath('result.rejected', 1)
        ->assertJsonPath('result.items.0.id', $this->ticket->id)->assertJsonPath('result.items.0.status', 'updated')
        ->assertJsonPath('result.items.1.id', $other->id)->assertJsonPath('result.items.1.status', 'stale');
    expect($this->ticket->fresh()->status)->toBe('closed')->and($other->fresh()->status)->toBe('open')
        ->and($other->events()->count())->toBe(0);
});
