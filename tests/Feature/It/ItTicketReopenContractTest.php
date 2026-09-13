<?php

use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Exceptions\ItTicketVersionConflict;
use App\Domain\It\Services\ItTicketInteractionService;
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
        'resolution_summary' => 'Historical synthetic repair.', 'resolution_verification' => 'Two synthetic checks passed.',
        'resolution_breached_at' => now()->subDays(2),
    ]);
    $this->actingAs($this->agent);
});

test('generic reopening records one normalized reason and preserves prior evidence for every supported work type', function (string $type, string $from, string $to, string $status) {
    $this->ticket->forceFill(['work_type' => $type, 'workflow_state' => $from, 'status' => $status])->save();
    $breachedAt = $this->ticket->resolution_breached_at->toIso8601String();
    $saved = app(ItWorkTransitionService::class)->transition($this->ticket, new ItTransitionInput(
        actor: $this->agent, to: ItWorkflowState::from($to), reason: '  Synthetic fault returned after verification.  ',
        expectedVersion: $this->ticket->lock_version, source: 'workspace',
    ));
    $event = $saved->events()->where('type', 'reopened')->sole();
    $comment = $saved->comments()->sole();
    expect($saved->workflow_state)->toBe($to)->and($saved->reopened_count)->toBe(1)
        ->and($saved->resolved_at)->toBeNull()->and($saved->closed_at)->toBeNull()
        ->and($saved->resolution_code)->toBeNull()->and($saved->resolution_summary)->toBeNull()
        ->and($saved->resolution_verification)->toBeNull()
        ->and($saved->resolution_breached_at->toIso8601String())->toBe($breachedAt)
        ->and($comment->is_internal)->toBeTrue()->and($comment->speaker_side)->toBe('it')
        ->and($comment->body)->toBe('Synthetic fault returned after verification.')
        ->and($event->payload['comment_id'])->toBe($comment->id)
        ->and($event->payload['previous_resolution']['resolution_verification'])->toBe('Two synthetic checks passed.')
        ->and(AuditLog::query()->where('action', 'it.ticket.reopened')->count())->toBe(1);
    $this->actingAs($this->requester)->getJson('/it/tickets/'.$saved->id)->assertOk()
        ->assertDontSee('Synthetic fault returned after verification.')
        ->assertDontSee('Historical synthetic repair.');
})->with([
    ['incident', 'resolved', 'submitted', 'resolved'],
    ['service_request', 'fulfilled', 'submitted', 'resolved'],
    ['security_request', 'fulfilled', 'submitted', 'resolved'],
    ['problem', 'closed', 'submitted', 'closed'],
    ['task', 'completed', 'submitted', 'resolved'],
    ['change', 'closed', 'draft', 'closed'],
    ['major_incident', 'resolved', 'responding', 'resolved'],
]);

test('post-implementation review retains settlement evidence and does not invent a reopened incident', function (string $type, string $from, string $status) {
    $this->ticket->forceFill(['work_type' => $type, 'workflow_state' => $from, 'status' => $status])->save();
    $saved = app(ItWorkTransitionService::class)->transition($this->ticket, new ItTransitionInput(
        actor: $this->agent, to: ItWorkflowState::Review, reason: 'Review the completed work.', expectedVersion: $this->ticket->lock_version,
    ));
    expect($saved->workflow_state)->toBe('review')->and($saved->reopened_count)->toBe(0)
        ->and($saved->resolution_verification)->toBe('Two synthetic checks passed.')
        ->and($saved->events()->where('type', 'reopened')->count())->toBe(0)
        ->and($saved->comments()->count())->toBe(0);
})->with([['change', 'completed', 'resolved'], ['change', 'failed', 'closed'], ['change', 'backed_out', 'closed'], ['major_incident', 'resolved', 'resolved']]);

test('all reopen writers reject missing short or oversized reasons without changing work', function (?string $reason) {
    $transition = fn () => app(ItWorkTransitionService::class)->transition($this->ticket, new ItTransitionInput(
        actor: $this->agent, to: ItWorkflowState::Submitted, reason: $reason, expectedVersion: $this->ticket->lock_version,
    ));
    expect($transition)->toThrow(DomainException::class, '5 to 2000');
    expect(fn () => app(ItTicketInteractionService::class)->reopenWithReason($this->ticket, $this->agent, $reason ?? '', $this->ticket->lock_version))
        ->toThrow(DomainException::class, '5 to 2000');
    expect($this->ticket->fresh()->status)->toBe('resolved')->and($this->ticket->comments()->count())->toBe(0)
        ->and($this->ticket->events()->count())->toBe(0);
})->with([[null], ['    '], ['four'], [str_repeat('x', 2001)]]);

test('a reopen source cannot authorize requester closure or ignore an expired requester window', function () {
    foreach ([ItWorkflowState::Closed, ItWorkflowState::Resolved] as $target) {
        expect(fn () => app(ItWorkTransitionService::class)->transition($this->ticket, new ItTransitionInput(
            actor: $this->requester, to: $target, reason: 'Forged reopen label.', source: 'reopen',
        )))->toThrow(DomainException::class);
    }
    $this->ticket->forceFill(['resolved_at' => now()->subDays(8)])->save();
    expect(fn () => app(ItWorkTransitionService::class)->transition($this->ticket, new ItTransitionInput(
        actor: $this->requester, to: ItWorkflowState::Submitted, reason: 'Expired reopen request.', source: 'legacy_reopen',
    )))->toThrow(DomainException::class);
    expect($this->ticket->fresh()->status)->toBe('resolved')->and($this->ticket->events()->count())->toBe(0);
});

test('current actor acknowledgement preserves the public audience when the agent is also requester', function () {
    $this->ticket->forceFill(['requester_user_id' => $this->agent->id])->save();
    $version = $this->ticket->lock_version;
    $this->postJson('/it/tickets/'.$this->ticket->id.'/reopen', [
        'actor_user_id' => $this->requester->id, 'expected_version' => $version, 'reason' => 'Previous user private draft.',
    ])->assertForbidden()->assertJsonMissingPath('data');
    expect($this->ticket->fresh()->status)->toBe('resolved');
    $response = $this->postJson('/it/tickets/'.$this->ticket->id.'/reopen', [
        'actor_user_id' => $this->agent->id, 'expected_version' => $version, 'reason' => '  The fault returned after sign-in.  ',
    ])->assertOk()->assertJsonPath('status', 'committed')->assertJsonPath('data.operation', 'ticket.reopen')
        ->assertJsonPath('data.viewer_user_id', $this->agent->id)->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.visibility', 'public')->assertJsonPath('data.reason', 'The fault returned after sign-in.');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $comment = $this->ticket->comments()->sole();
    expect($comment->is_internal)->toBeFalse()->and($comment->speaker_side)->toBe('requester')
        ->and($response->json('data.comment_id'))->toBe($comment->id)
        ->and($this->ticket->fresh()->last_public_comment_id)->toBe($comment->id)
        ->and($this->ticket->fresh()->next_response_party)->toBe('it');
    $this->postJson('/it/tickets/'.$this->ticket->id.'/reopen', [
        'actor_user_id' => $this->agent->id, 'expected_version' => $version, 'reason' => 'The fault returned after sign-in.',
    ])->assertConflict();
    $this->postJson('/it/tickets/'.$this->ticket->id.'/reopen', [
        'actor_user_id' => $this->agent->id, 'expected_version' => $this->ticket->fresh()->lock_version, 'reason' => 'The fault returned after sign-in.',
    ])->assertUnprocessable()->assertJsonPath('code', 'reopen_unavailable')->assertJsonMissingPath('data');
    expect($this->ticket->comments()->count())->toBe(1)->and($this->ticket->fresh()->reopened_count)->toBe(1);
});

test('reopen audit failure rolls back state comments history and version before an explicit retry', function () {
    $fail = true;
    Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $audit) use (&$fail) {
        if ($fail && $audit->action === 'it.ticket.reopened') {
            throw new RuntimeException('Synthetic reopen audit failure');
        }
    });
    $reopen = fn () => app(ItWorkTransitionService::class)->transition($this->ticket, new ItTransitionInput(
        actor: $this->agent, to: ItWorkflowState::Submitted, reason: 'Synthetic fault returned.', expectedVersion: $this->ticket->lock_version,
    ));
    expect($reopen)->toThrow(RuntimeException::class);
    expect($this->ticket->fresh()->status)->toBe('resolved')->and($this->ticket->fresh()->lock_version)->toBe($this->ticket->lock_version)
        ->and($this->ticket->comments()->count())->toBe(0)->and($this->ticket->events()->count())->toBe(0);
    $fail = false;
    $saved = $reopen();
    expect($saved->reopened_count)->toBe(1)->and($saved->comments()->count())->toBe(1)
        ->and($saved->events()->where('type', 'reopened')->count())->toBe(1);
    expect($reopen)->toThrow(ItTicketVersionConflict::class);
});

test('a merged source cannot be reopened through the generic transition', function () {
    $survivor = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $this->ticket->forceFill(['merged_into_ticket_id' => $survivor->id])->save();
    expect(fn () => app(ItWorkTransitionService::class)->transition($this->ticket, new ItTransitionInput(
        actor: $this->agent, to: ItWorkflowState::Submitted, reason: 'Attempt to reopen the merged source.',
    )))->toThrow(DomainException::class, 'surviving ticket');
    expect($this->ticket->fresh()->status)->toBe('resolved')->and($this->ticket->comments()->count())->toBe(0);
});
