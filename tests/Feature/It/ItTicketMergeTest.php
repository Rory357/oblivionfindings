<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Presenters\ItTicketActivityPresenter;
use App\Domain\It\Services\ItTicketMergeService;
use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketEvent;
use App\Models\ItWorkTask;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * §P-S2 — ticket merge authorisation (ItTicketPolicy@merge). The fold itself
 * (reparenting the thread/events/watchers) lands in S6; here we pin the guards.
 */

function itMergeUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->agent = itMergeUser('hr');              // holds it.manage
    $this->worker = itMergeUser('support_worker'); // it.request only
    $this->otherWorker = itMergeUser('support_worker');
    $this->site = Site::factory()->create();
    foreach ([$this->agent, $this->worker, $this->otherWorker] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }
});

function mergeTicket(array $overrides = []): ItTicket
{
    return ItTicket::factory()->create([
        'site_id' => test()->site->id,
        'requester_user_id' => test()->worker->id,
        'requested_for_user_id' => test()->worker->id,
        ...$overrides,
    ]);
}

function mergePreviewUrl(ItTicket $source, ItTicket $target, array $overrides = []): string
{
    return '/it/tickets/'.$source->id.'/merge-preview?'.http_build_query([
        'actor_user_id' => test()->agent->id, 'target_ticket_id' => $target->id,
        'source_version' => $source->lock_version, 'target_version' => $target->lock_version,
        'review_nonce' => '560fda43-afaf-4a7b-afad-f928bec51111', ...$overrides,
    ]);
}

function mergeCommandInput(ItTicket $source, ItTicket $target, array $overrides = []): array
{
    $source->refresh();
    $target->refresh();
    $preview = app(ItTicketMergeService::class)->preview($source, $target, test()->agent,
        $source->lock_version, $target->lock_version);

    return ['actor_user_id' => test()->agent->id, 'target_ticket_id' => $target->id,
        'source_version' => $source->lock_version, 'target_version' => $target->lock_version,
        'request_uuid' => (string) Str::uuid(), 'review_token' => $preview['review_token'],
        'reason' => 'Duplicate report of the same access issue', ...$overrides];
}

test('merge preview inventories canonical evidence without moving or disclosing its contents', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $source->comments()->create(['author_user_id' => $this->worker->id, 'body' => 'Private public report fixture', 'is_internal' => false]);
    $note = $source->comments()->create(['author_user_id' => $this->agent->id, 'body' => 'Private investigation fixture', 'is_internal' => true]);
    foreach ([$source, $note] as $parent) {
        ItAttachment::create(['attachable_type' => $parent->getMorphClass(), 'attachable_id' => $parent->id,
            'path' => 'synthetic-preview-only.txt', 'original_name' => 'private-preview-name.txt',
            'mime' => 'text/plain', 'size' => 10, 'uploaded_by' => $this->agent->id]);
    }
    $source->watchers()->attach($this->worker->id);
    $source->tasks()->create(['title' => 'Synthetic outstanding work', 'status' => 'pending', 'is_required' => true]);
    $source->approvals()->create(['requested_by' => $this->agent->id, 'status' => 'pending',
        'request_reason' => 'Private approval reason', 'expires_at' => now()->subMinute()]);
    $before = [$source->fresh()->getAttributes(), $target->fresh()->getAttributes(),
        ItTicketEvent::count(), AuditLog::count()];

    $response = $this->actingAs($this->agent)->getJson(mergePreviewUrl($source, $target))
        ->assertOk()->assertJsonPath('status', 'reviewed')
        ->assertJsonPath('data.viewer_user_id', $this->agent->id)
        ->assertJsonPath('data.review_nonce', '560fda43-afaf-4a7b-afad-f928bec51111')
        ->assertJsonPath('data.source.inventory', ['public_comments' => 1, 'internal_notes' => 1,
            'ticket_files' => 1, 'comment_files' => 1, 'watchers' => 1, 'links' => 0,
            'tasks' => 1, 'unfinished_required_tasks' => 1, 'approval_requests' => 1,
            'pending_approval_requests' => 0, 'expired_approval_requests' => 1])
        ->assertJsonPath('data.target.inventory.public_comments', 0)
        ->assertJsonPath('data.lifecycle_blockers', ['Complete the original ticket’s required work and complete or cancel its remaining tasks before merging.']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    expect($response->getContent())->not->toContain('Private investigation fixture')
        ->not->toContain('Private public report fixture')->not->toContain('Private approval reason')
        ->not->toContain('private-preview-name.txt')->not->toContain('synthetic-preview-only.txt');
    expect([$source->fresh()->getAttributes(), $target->fresh()->getAttributes(),
        ItTicketEvent::count(), AuditLog::count()])->toBe($before)
        ->and($source->comments()->count())->toBe(2)->and($target->comments()->count())->toBe(0);
});

test('merge preview refuses stale source or target versions without writes', function (string $field) {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $changed = $field === 'source_version' ? $source : $target;
    $original = $changed->lock_version;
    $changed->update(['title' => 'Changed after selection']);
    $this->actingAs($this->agent)->getJson(mergePreviewUrl($source, $target, [$field => $original]))
        ->assertStatus(409)->assertJsonPath('code', 'stale_ticket')->assertJsonPath('current.id', $changed->id);
    expect($source->fresh()->merged_into_ticket_id)->toBeNull()->and($target->comments()->count())->toBe(0);
})->with(['source_version', 'target_version']);

test('merge preview binds its browser actor and conceals an inaccessible target', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $this->actingAs($this->agent)->getJson(mergePreviewUrl($source, $target, ['actor_user_id' => $this->worker->id]))->assertForbidden();
    // The existing staff route group rejects requesters before object lookup.
    $this->actingAs($this->worker)->getJson(mergePreviewUrl($source, $target, ['actor_user_id' => $this->worker->id]))->assertForbidden();
    $private = mergeTicket(['site_id' => Site::factory()->create()->id, 'title' => 'Unapproved target secret']);
    $this->actingAs($this->agent)->getJson(mergePreviewUrl($source, $private))->assertNotFound()->assertDontSee('Unapproved target secret');
});

test('merge preview reports lifecycle blockers and scope differences without granting a merge', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'closed', 'assigned_to_user_id' => $this->agent->id]);
    $this->actingAs($this->agent)->getJson(mergePreviewUrl($source, $target))->assertOk()
        ->assertJsonPath('data.lifecycle_blockers', ['The target ticket is closed. Choose an open ticket.'])
        ->assertJsonPath('data.access_scope_differences.0.field', 'assigned_to_user_id')
        ->assertJsonMissingPath('data.can_merge');
    expect($source->fresh()->merged_into_ticket_id)->toBeNull();
});

test('merge service preview refreshes a previously approved actor', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    User::query()->whereKey($this->agent)->update(['approved_at' => null]);
    expect(fn () => app(ItTicketMergeService::class)->preview($source, $target, $this->agent, 1, 1))
        ->toThrow(AuthorizationException::class);
});

test('merge preview rejects incomplete version and review identities', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $this->actingAs($this->agent)->getJson(mergePreviewUrl($source, $target, [
        'source_version' => 0, 'target_version' => 'invalid', 'review_nonce' => 'invalid',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['source_version', 'target_version', 'review_nonce']);
    expect($source->fresh()->merged_into_ticket_id)->toBeNull();
});

test('an agent may merge two distinct live tickets', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);

    expect($this->agent->can('merge', [$source, $target]))->toBeTrue();
});

test('a requester cannot merge tickets', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);

    expect($this->worker->can('merge', [$source, $target]))->toBeFalse();
});

test('tickets with different requester audiences cannot be merged', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket([
        'status' => 'open',
        'requester_user_id' => $this->otherWorker->id,
        'requested_for_user_id' => $this->otherWorker->id,
    ]);

    expect($this->agent->can('merge', [$source, $target]))->toBeFalse();

    $this->actingAs($this->agent)
        ->from(route('it.tickets.show', $source))
        ->post("/it/tickets/{$source->id}/merge", mergeCommandInput($source, $target, [
            'reason' => 'Same technical issue',
        ]))
        ->assertRedirect()
        ->assertSessionHas('error', 'Tickets with different requesters cannot be merged because their conversations are private.');

    expect($source->refresh()->merged_into_ticket_id)->toBeNull()
        ->and($source->status)->toBe('open');
});

test('a ticket cannot be merged into itself', function () {
    $ticket = mergeTicket(['status' => 'open']);

    expect($this->agent->can('merge', [$ticket, $ticket]))->toBeFalse();
});

test('an already-merged source cannot be merged again', function () {
    $target = mergeTicket(['status' => 'open']);
    $source = mergeTicket([
        'status' => 'closed',
        'merged_into_ticket_id' => $target->id,
        'merged_at' => now(),
    ]);
    $other = mergeTicket(['status' => 'open']);

    expect($this->agent->can('merge', [$source, $other]))->toBeFalse();
});

test('a closed target cannot receive a merge', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'closed']);

    expect($this->agent->can('merge', [$source, $target]))->toBeFalse();
});

test('merging folds the conversation and watchers onto the survivor and closes the source', function () {
    $source = mergeTicket([
        'status' => 'open',
        'requester_user_id' => $this->worker->id,
    ]);
    $target = mergeTicket(['status' => 'open']);

    $source->comments()->create([
        'author_user_id' => $this->worker->id,
        'body' => 'Same issue as the other ticket',
        'is_internal' => false,
    ]);
    $source->watchers()->syncWithoutDetaching([$this->worker->id]);

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$source->id}/merge", mergeCommandInput($source, $target))
        ->assertRedirect(route('it.tickets.show', $target));

    $source->refresh();
    expect($source->status)->toBe('closed');
    expect($source->merged_into_ticket_id)->toBe($target->id);
    expect($source->merged_at)->not->toBeNull();

    // Conversation + watcher moved to the survivor; the source is emptied.
    expect($target->comments()->count())->toBe(1);
    expect($source->comments()->count())->toBe(0);
    expect($target->watchers()->where('users.id', $this->worker->id)->exists())->toBeTrue();

    // A merged marker on each side.
    expect($source->events()->where('type', 'merged')->count())->toBe(1);
    expect($target->events()->where('type', 'merged')->count())->toBe(1);
    expect($source->events()->where('type', 'merged')->where('payload->reason', 'Duplicate report of the same access issue')->exists())->toBeTrue()
        ->and(AuditLog::query()
            ->where('action', 'it.ticket.merged')
            ->where('auditable_id', $source->id)
            ->exists())->toBeTrue();
});

test('merge requires a reason and a repeated merge cannot write duplicate history', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$source->id}/merge", mergeCommandInput($source, $target, ['reason' => '']))
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->agent)
        ->post("/it/tickets/{$source->id}/merge", mergeCommandInput($source, $target, [
            'reason' => 'Duplicate report',
        ]))
        ->assertRedirect(route('it.tickets.show', $target));

    $this->actingAs($this->agent)
        ->from(route('it.tickets.show', $source))
        ->post("/it/tickets/{$source->id}/merge", mergeCommandInput($source, $target, [
            'reason' => 'Repeated stale request',
        ]))
        ->assertRedirect()
        ->assertSessionHas('error', 'This ticket has already been merged.');

    expect($source->events()->where('type', 'merged')->count())->toBe(1)
        ->and($target->events()->where('type', 'merged')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.ticket.merged')->count())->toBe(1);
});

test('a merged source cannot be reopened', function () {
    $target = mergeTicket(['status' => 'open']);
    $source = mergeTicket([
        'status' => 'closed',
        'merged_into_ticket_id' => $target->id,
        'merged_at' => now(),
    ]);

    $this->actingAs($this->agent)
        ->from(route('it.tickets.show', $source))
        ->post("/it/tickets/{$source->id}/reopen", [
            'expected_version' => $source->fresh()->lock_version,
            'reason' => 'Attempting to reopen stale merged work.',
        ])
        ->assertForbidden();

    expect($source->refresh()->status)->toBe('closed')
        ->and($source->comments()->count())->toBe(0)
        ->and($source->events()->count())->toBe(0);
});

test('the workspace offers live merge targets to agents but not requesters', function () {
    $me = mergeTicket([
        'status' => 'open',
        'requester_user_id' => $this->worker->id,
    ]);
    $candidate = mergeTicket(['status' => 'open']);
    mergeTicket([
        'status' => 'open',
        'requester_user_id' => $this->otherWorker->id,
        'requested_for_user_id' => $this->otherWorker->id,
    ]);
    // A closed-away merged ticket must never be offered as a target.
    mergeTicket([
        'status' => 'closed',
        'merged_into_ticket_id' => $candidate->id,
        'merged_at' => now(),
    ]);

    $this->actingAs($this->agent)
        ->get(route('it.tickets.show', $me))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.merge', true)
            ->has('mergeTargets', 1)
            ->where('mergeTargets.0.id', $candidate->id)
            ->where('mergeTargets.0.lock_version', $candidate->lock_version));

    $this->actingAs($this->worker)
        ->get(route('it.tickets.show', $me))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.merge', false)
            ->has('mergeTargets', 0));
});

test('old browser links follow the authorized final survivor and retain a link to original history', function () {
    $survivor = mergeTicket(['status' => 'open']);
    $middle = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $survivor->id]);
    $source = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $middle->id]);
    $sourceVersion = $source->lock_version;

    $destination = route('it.tickets.show', ['ticket' => $survivor->id, 'merged_from' => $source->id]);
    $this->actingAs($this->worker)->get(route('it.tickets.show', $source))
        ->assertRedirect($destination)->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($this->worker)->get(route('it.tickets.show', ['ticket' => $source->id,
        'tab' => 'history', 'return_to' => 'https://example.invalid']))
        ->assertRedirect(route('it.tickets.show', ['ticket' => $survivor->id, 'merged_from' => $source->id, 'tab' => 'history']));
    $this->actingAs($this->worker)->get($destination)
        ->assertInertia(fn (Assert $page) => $page
            ->where('ticket.id', $survivor->id)
            ->where('ticket.merge_origin.id', $source->id)
            ->where('ticket.merge_origin.href', "/it/tickets/{$source->id}/original"));
    $this->actingAs($this->worker)->get(route('it.tickets.original', $source))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ticket.id', $source->id)
            ->where('ticket.merged_into.id', $survivor->id)
            ->where('can.comment', false));

    expect($source->refresh()->merged_into_ticket_id)->toBe($middle->id)
        ->and($source->lock_version)->toBe($sourceVersion);
});

test('drawer reads retain their original record identity and resolve only the authorized destination', function () {
    $target = mergeTicket(['status' => 'open']);
    $source = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $target->id]);
    $this->actingAs($this->worker)->getJson(route('it.tickets.show', $source))
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('ticket.id', $source->id)
        ->assertJsonPath('ticket.merged_into.id', $target->id)
        ->assertJsonPath('can.comment', false);
});

test('original records keep authorized private history while disabling editing controls', function () {
    $target = mergeTicket(['status' => 'open']);
    $source = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $target->id]);
    $source->comments()->create(['author_user_id' => $this->agent->id,
        'body' => 'Original internal investigation evidence', 'is_internal' => true]);

    $this->actingAs($this->agent)->getJson(route('it.tickets.original', $source))
        ->assertOk()->assertJsonPath('ticket.id', $source->id)
        ->assertJsonPath('comments.0.body', 'Original internal investigation evidence')
        ->assertJsonPath('can.view', true)->assertJsonPath('can.manage', false)
        ->assertJsonPath('can.linkDevices', false)->assertJsonPath('can.manageWatchers', false)
        ->assertJsonPath('can.merge', false)->assertJsonPath('can.comment', false)
        ->assertJsonPath('can.reopen', false)
        ->assertJsonPath('can.decideApproval', false);
    $this->actingAs($this->worker)->getJson(route('it.tickets.original', $source))
        ->assertOk()->assertJsonCount(0, 'comments')->assertJsonPath('can.view', false)
        ->assertJsonPath('can.reopen', false);
    expect($this->agent->can('reopen', $source))->toBeFalse();
});

test('a merge grants no access through an inaccessible original record', function () {
    $target = mergeTicket(['status' => 'open']);
    $source = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $target->id,
        'requester_user_id' => $this->otherWorker->id, 'requested_for_user_id' => $this->otherWorker->id]);
    $this->actingAs($this->worker)->get(route('it.tickets.show', $source))->assertNotFound();
    $this->actingAs($this->worker)->getJson(route('it.tickets.original', $source))->assertNotFound();
    $this->actingAs($this->worker)->getJson(route('it.tickets.show', ['ticket' => $target->id, 'merged_from' => $source->id]))
        ->assertOk()->assertJsonPath('ticket.merge_origin', null);
});

test('an inaccessible destination is concealed in both workspace and merge activity', function () {
    $target = mergeTicket(['status' => 'open', 'title' => 'Private destination title',
        'requester_user_id' => $this->otherWorker->id, 'requested_for_user_id' => $this->otherWorker->id]);
    $source = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $target->id]);
    $event = ItTicketEvent::record($source, 'merged', $this->agent->id, [
        'direction' => 'into', 'target_id' => $target->id, 'target_reference' => $target->reference,
        'reason' => 'Private destination title discussed here',
    ]);

    $this->actingAs($this->worker)->getJson(route('it.tickets.show', $source))
        ->assertOk()->assertJsonPath('ticket.merged_into', null)
        ->assertJsonPath('ticket.is_merged', true)
        ->assertJsonPath('events.0.payload', ['direction' => 'into'])
        ->assertDontSee($target->reference)->assertDontSee('Private destination title');
    $this->actingAs($this->worker)->get(route('it.tickets.show', $source))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('ticket.id', $source->id));
    expect(app(ItTicketActivityPresenter::class)->presentEvent($event, $this->worker)['payload'])
        ->toBe(['direction' => 'into']);
});

test('an inaccessible intermediate merge cannot be used to reach an otherwise visible survivor', function () {
    $target = mergeTicket(['status' => 'open']);
    $middle = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $target->id,
        'requester_user_id' => $this->otherWorker->id, 'requested_for_user_id' => $this->otherWorker->id]);
    $source = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $middle->id]);
    expect(app(ItTicketMergeService::class)->destinationForViewer($source, $this->worker))->toBeNull();
    $this->actingAs($this->worker)->getJson(route('it.tickets.show', $source))
        ->assertOk()->assertJsonPath('ticket.merged_into', null);
});

test('stale model snapshots and withdrawn account approval cannot authorize canonical navigation', function () {
    $target = mergeTicket(['status' => 'open']);
    $source = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $target->id]);
    $service = app(ItTicketMergeService::class);
    expect($service->destinationForViewer($source, $this->worker)?->id)->toBe($target->id);
    $target->fresh()->forceFill(['requester_user_id' => $this->otherWorker->id,
        'requested_for_user_id' => $this->otherWorker->id])->save();
    expect($service->destinationForViewer($source, $this->worker))->toBeNull();
    $this->worker->fresh()->forceFill(['approved_at' => null])->save();
    expect($service->destinationForViewer($source, $this->worker))->toBeNull();
});

test('cycles and missing merge destinations remain finite and do not invent a destination', function () {
    $source = mergeTicket(['status' => 'closed']);
    $target = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $source->id]);
    $source->forceFill(['merged_into_ticket_id' => $target->id])->save();
    expect(app(ItTicketMergeService::class)->destinationForViewer($source, $this->worker))->toBeNull();
    $this->actingAs($this->worker)->getJson(route('it.tickets.show', $source))
        ->assertOk()->assertJsonPath('ticket.merged_into', null);
    $target->delete();
    expect(app(ItTicketMergeService::class)->destinationForViewer($source, $this->worker))->toBeNull();
});

test('forged or unrelated history origins do not produce a link on the current ticket', function () {
    $target = mergeTicket(['status' => 'open']);
    $unrelated = mergeTicket(['status' => 'open']);
    foreach ([$unrelated->id, 'https://example.invalid', '-1', ['unexpected']] as $origin) {
        $this->actingAs($this->worker)->getJson(route('it.tickets.show', ['ticket' => $target->id, 'merged_from' => $origin]))
            ->assertOk()->assertJsonPath('ticket.merge_origin', null);
    }
});

test('merge activity hides private reasons when the counterpart is only participant-visible', function () {
    $target = mergeTicket(['status' => 'open', 'is_sensitive' => true,
        'requester_user_id' => $this->agent->id, 'requested_for_user_id' => $this->agent->id]);
    $source = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $target->id]);
    $sensitivePermission = Permission::query()->where('key', 'it.viewSensitive')->firstOrFail();
    $this->agent->permissionOverrides()->syncWithoutDetaching([$sensitivePermission->id => ['allowed' => false]]);
    $event = ItTicketEvent::record($source, 'merged', $this->agent->id, [
        'direction' => 'into', 'target_id' => $target->id, 'target_reference' => $target->reference,
        'reason' => 'Internal-only merge rationale',
    ]);
    expect(app(ItTicketActivityPresenter::class)->presentEvent($event, $this->agent->fresh())['payload'])
        ->toBe(['direction' => 'into', 'target_reference' => $target->reference]);
});

test('merge command replays the exact committed identity and cancellation cannot undo a commit', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $input = mergeCommandInput($source, $target);
    $path = "/it/tickets/{$source->id}/merge";
    $recovery = "/it/tickets/{$source->id}/merge-commands/{$input['request_uuid']}";
    $identity = ['actor_user_id' => $this->agent->id, 'target_ticket_id' => $target->id];
    $response = $this->actingAs($this->agent)->postJson($path, $input)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('status', 'committed')
        ->assertJsonPath('data.request_uuid', $input['request_uuid'])->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.source_version', $input['source_version'] + 2)
        ->assertJsonPath('data.target_version', $input['target_version'] + 1);
    $versions = [$source->fresh()->lock_version, $target->fresh()->lock_version];
    $this->travel(11)->minutes(); // A committed receipt survives expiry of its original review.
    $this->postJson($path, $input)->assertOk()->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.url', $response->json('data.url'));
    $this->getJson($recovery.'?'.http_build_query($identity))->assertOk()->assertJsonPath('status', 'committed');
    $this->postJson($recovery.'/cancel', $identity)->assertOk()->assertJsonPath('status', 'committed');
    $this->postJson($path, [...$input, 'reason' => 'Different command intent'])->assertStatus(409);
    expect([$source->fresh()->lock_version, $target->fresh()->lock_version])->toBe($versions)
        ->and(ItTicketCommandReceipt::where('operation', 'ticket.merge')->count())->toBe(1)
        ->and($source->events()->where('type', 'merged')->count())->toBe(1)
        ->and($target->events()->where('type', 'merged')->count())->toBe(1);
});

test('an explicit cancellation prevents a delayed merge with that identity', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $input = mergeCommandInput($source, $target);
    $recovery = "/it/tickets/{$source->id}/merge-commands/{$input['request_uuid']}";
    $identity = ['actor_user_id' => $this->agent->id, 'target_ticket_id' => $target->id];
    $this->actingAs($this->agent)->getJson($recovery.'?'.http_build_query($identity))->assertNotFound()
        ->assertJsonPath('code', 'merge_receipt_unconfirmed')->assertJsonPath('viewer_user_id', $this->agent->id)
        ->assertJsonPath('source_id', $source->id)->assertJsonPath('target_id', $target->id)
        ->assertJsonPath('request_uuid', $input['request_uuid']);
    $this->postJson($recovery.'/cancel', $identity)->assertOk()->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('data.replayed', false);
    $this->postJson("/it/tickets/{$source->id}/merge", $input)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->getJson($recovery.'?'.http_build_query($identity))->assertOk()->assertJsonPath('status', 'cancelled');
    expect($source->fresh()->merged_into_ticket_id)->toBeNull()
        ->and($source->fresh()->lock_version)->toBe($input['source_version'])
        ->and($target->fresh()->lock_version)->toBe($input['target_version'])
        ->and(AuditLog::where('action', 'it.ticket.merge.command.cancelled')->count())->toBe(1);
});

test('merge review proof rejects altered expired or another-pair reviews without any receipt', function (string $fault) {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $input = mergeCommandInput($source, $target);
    if ($fault === 'altered') {
        $input['review_token'] = 'not-a-valid-review';
    } elseif ($fault === 'expired') {
        $this->travel(10)->minutes();
    } else {
        $other = mergeTicket(['status' => 'open']);
        $input['review_token'] = mergeCommandInput($source, $other)['review_token'];
    }
    $this->actingAs($this->agent)->postJson("/it/tickets/{$source->id}/merge", $input)
        ->assertUnprocessable()->assertJsonValidationErrors('review_token');
    expect($source->fresh()->merged_into_ticket_id)->toBeNull()
        ->and(ItTicketCommandReceipt::where('operation', 'ticket.merge')->count())->toBe(0);
})->with(['altered', 'expired', 'other pair']);

test('merge rechecks inventory even when a historical adapter did not advance the parent version', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $input = mergeCommandInput($source, $target);
    $source->comments()->create(['author_user_id' => $this->worker->id, 'body' => 'New evidence after review', 'is_internal' => false]);
    expect($source->fresh()->lock_version)->toBe($input['source_version']);
    $this->actingAs($this->agent)->postJson("/it/tickets/{$source->id}/merge", $input)
        ->assertUnprocessable()->assertJsonValidationErrors('review_token');
    expect($source->comments()->count())->toBe(1)->and($target->comments()->count())->toBe(0);
});

test('merge commit rejects either stale parent version before moving records', function (string $parent) {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $input = mergeCommandInput($source, $target);
    ($parent === 'source' ? $source : $target)->update(['title' => 'Competing edit']);
    $this->actingAs($this->agent)->postJson("/it/tickets/{$source->id}/merge", $input)
        ->assertStatus(409)->assertJsonPath('code', 'stale_ticket');
    expect($source->fresh()->merged_into_ticket_id)->toBeNull()
        ->and(ItTicketCommandReceipt::where('operation', 'ticket.merge')->count())->toBe(0);
})->with(['source', 'target']);

test('merge protects staff access predicates as well as the requester audience', function (string $field) {
    if ($field === 'is_sensitive') {
        $permission = Permission::where('key', 'it.viewSensitive')->firstOrFail();
        $this->agent->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open', $field => $field === 'is_sensitive' ? true : $this->agent->id]);
    $input = mergeCommandInput($source, $target);
    expect($this->agent->can('merge', [$source, $target]))->toBeFalse();
    $this->actingAs($this->agent)->postJson("/it/tickets/{$source->id}/merge", $input)
        ->assertUnprocessable()->assertJsonPath('code', 'merge_blocked');
    expect($source->fresh()->merged_into_ticket_id)->toBeNull();
})->with(['assigned_to_user_id', 'owner_user_id', 'is_sensitive']);

test('merge blocks unfinished optional work and pending approvals rather than stranding them on a read-only original', function (string $kind) {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    if ($kind === 'task') {
        $source->tasks()->create(['title' => 'Optional follow-up', 'status' => 'pending', 'is_required' => false]);
    } else {
        $source->approvals()->create(['requested_by' => $this->agent->id, 'status' => 'pending', 'expires_at' => now()->addDay()]);
    }
    $this->actingAs($this->agent)->postJson("/it/tickets/{$source->id}/merge", mergeCommandInput($source, $target))
        ->assertUnprocessable()->assertJsonPath('code', 'merge_blocked');
    expect($source->fresh()->merged_into_ticket_id)->toBeNull()
        ->and(ItTicketCommandReceipt::where('operation', 'ticket.merge')->count())->toBe(0);
})->with(['task', 'approval']);

test('merge preserves original task completion and approval evidence while moving files without replacing identities', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $approval = $source->approvals()->create(['requested_by' => $this->worker->id, 'approver_id' => $this->agent->id,
        'status' => 'approved', 'decided_at' => now(), 'request_reason' => 'Synthetic retained approval']);
    $task = ItWorkTask::factory()->create(['ticket_id' => $source->id, 'status' => 'pending', 'is_required' => true,
        'approval_id' => $approval->id, 'evidence_required' => true]);
    $this->actingAs($this->agent)->postJson("/it/tickets/{$source->id}/tasks/{$task->id}/complete", [
        'actor_user_id' => $this->agent->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => $source->fresh()->lock_version,
        'completion_note' => 'Completed synthetic verification', 'evidence' => ['Synthetic evidence reference'],
    ])->assertOk();
    $completion = $task->fresh()->currentCompletion;
    $before = [$task->fresh()->getRawOriginal(), $completion->getRawOriginal(), $approval->fresh()->getRawOriginal()];
    $comment = $source->comments()->create(['author_user_id' => $this->agent->id, 'body' => 'Retained internal evidence', 'is_internal' => true]);
    $files = collect([$source, $comment])->map(fn ($parent) => ItAttachment::create([
        'attachable_type' => $parent->getMorphClass(), 'attachable_id' => $parent->id, 'path' => 'synthetic-merge-only.txt',
        'original_name' => 'synthetic.txt', 'mime' => 'text/plain', 'size' => 10, 'uploaded_by' => $this->agent->id,
    ]));
    $beforeFiles = $files->map(fn ($file) => $file->only(['id', 'path', 'uploaded_by', 'original_name']))->all();
    $this->postJson("/it/tickets/{$source->id}/merge", mergeCommandInput($source, $target))->assertOk();
    expect([$task->fresh()->getRawOriginal(), $completion->fresh()->getRawOriginal(), $approval->fresh()->getRawOriginal()])->toBe($before)
        ->and($files->map(fn ($file) => $file->fresh()->only(['id', 'path', 'uploaded_by', 'original_name']))->all())->toBe($beforeFiles)
        ->and($files[0]->fresh()->attachable_id)->toBe($target->id)
        ->and($files[1]->fresh()->attachable_id)->toBe($comment->id)
        ->and($comment->fresh()->ticket_id)->toBe($target->id)->and($comment->fresh()->is_internal)->toBeTrue()
        ->and($source->fresh()->workflow_state)->toBe('closed');
});

test('merge will not silently transfer a watcher whose account approval has been withdrawn', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $source->watchers()->attach($this->worker->id);
    $input = mergeCommandInput($source, $target);
    $this->worker->update(['approved_at' => null]);
    $this->actingAs($this->agent)->postJson("/it/tickets/{$source->id}/merge", $input)
        ->assertUnprocessable()->assertJsonPath('code', 'merge_blocked');
    expect($source->watchers()->count())->toBe(1)->and($target->watchers()->count())->toBe(0)
        ->and($source->fresh()->merged_into_ticket_id)->toBeNull();
});

test('merge public history skips observers for responsibility and leaves unknown legacy provenance unknown', function (bool $legacy) {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open', 'next_response_party' => 'requester']);
    $source->comments()->create(['author_user_id' => $this->worker->id, 'body' => 'Latest substantive message',
        'is_internal' => false, 'speaker_side' => $legacy ? null : 'requester', 'created_at' => now()->subMinute()]);
    $observer = $target->comments()->create(['author_user_id' => $this->agent->id, 'body' => 'Observer update',
        'is_internal' => false, 'speaker_side' => 'observer', 'created_at' => now()]);
    $this->actingAs($this->agent)->postJson("/it/tickets/{$source->id}/merge", mergeCommandInput($source, $target))->assertOk();
    expect($target->fresh()->last_public_comment_id)->toBe($observer->id)
        ->and($target->fresh()->last_public_speaker_side)->toBe('observer')
        ->and($target->fresh()->next_response_party)->toBe($legacy ? null : 'it');
})->with([false, true]);

test('merge receipt recovery rechecks current access to both parents and the original browser actor', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $input = mergeCommandInput($source, $target);
    $this->actingAs($this->agent)->postJson("/it/tickets/{$source->id}/merge", $input)->assertOk();
    $url = "/it/tickets/{$source->id}/merge-commands/{$input['request_uuid']}";
    $identity = ['actor_user_id' => $this->agent->id, 'target_ticket_id' => $target->id];
    $this->getJson($url.'?'.http_build_query([...$identity, 'actor_user_id' => $this->worker->id]))->assertForbidden();
    $target->update(['site_id' => Site::factory()->create()->id]);
    $this->getJson($url.'?'.http_build_query($identity))->assertNotFound();
    $this->postJson($url.'/cancel', $identity)->assertNotFound();
});

test('a merge audit failure rolls back settlement movements versions and receipt before the same command can retry', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $comment = $source->comments()->create(['author_user_id' => $this->worker->id, 'body' => 'Retain on failed merge', 'is_internal' => false]);
    $input = mergeCommandInput($source, $target);
    $event = 'eloquent.creating: '.AuditLog::class;
    Event::listen($event, function (AuditLog $log) {
        if ($log->action === 'it.ticket.merged') {
            throw new RuntimeException('Synthetic merge audit failure');
        }
    });
    try {
        expect(fn () => app(ItTicketMergeService::class)->execute($source, $target, $this->agent, $input))
            ->toThrow(RuntimeException::class, 'Synthetic merge audit failure');
    } finally {
        Event::forget($event);
    }
    expect($source->fresh()->status)->toBe('open')->and($source->fresh()->merged_into_ticket_id)->toBeNull()
        ->and($source->fresh()->lock_version)->toBe($input['source_version'])
        ->and($target->fresh()->lock_version)->toBe($input['target_version'])
        ->and($comment->fresh()->ticket_id)->toBe($source->id)
        ->and(ItTicketCommandReceipt::where('operation', 'ticket.merge')->count())->toBe(0)
        ->and($source->events()->count())->toBe(0)->and($target->events()->count())->toBe(0);
    $this->actingAs($this->agent)->postJson("/it/tickets/{$source->id}/merge", $input)->assertOk();
});

function mergeCandidateInput(ItTicket $source, ItTicket $target): array
{
    return ['actor_user_id' => test()->agent->id, 'purpose' => 'merge_work', 'ticket_id' => $source->id,
        'memory_uuid' => (string) Str::uuid(), 'candidate_uuid' => (string) Str::uuid(), 'base_ticket_version' => $source->lock_version,
        'fields' => ['reason' => 'Private retained proposal', 'target_ticket_id' => $target->id, 'target_version' => $target->lock_version],
        'step_index' => 1, 'bound_scopes' => [['target_ticket_id' => $target->id]]];
}

test('merge RAM recovery checks both parents without persisting text or granting submission', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $input = mergeCandidateInput($source, $target);
    $before = [$source->fresh()->getRawOriginal(), $target->fresh()->getRawOriginal(), AuditLog::count(), ItTicketCommandReceipt::count()];
    $this->actingAs($this->agent)->postJson("/it/tickets/{$source->id}/merge-candidates/validate", $input)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('candidate.authorized', true)
        ->assertJsonPath('candidate.capabilities.submit', false)->assertJsonPath('candidate.context_key', 'ticket:'.$source->id)
        ->assertJsonPath('candidate.memory_uuid', $input['memory_uuid'])->assertJsonPath('candidate.candidate_uuid', $input['candidate_uuid'])
        ->assertDontSee('Private retained proposal');
    expect([$source->fresh()->getRawOriginal(), $target->fresh()->getRawOriginal(), AuditLog::count(), ItTicketCommandReceipt::count()])
        ->toBe($before);
    expect($source->fresh()->lock_version)->toBe($source->lock_version)->and($target->fresh()->lock_version)->toBe($target->lock_version);
});

test('merge RAM recovery conceals earlier target bindings even after the current selection changed', function () {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $old = mergeTicket(['site_id' => Site::factory()->create()->id]);
    $input = mergeCandidateInput($source, $target);
    $input['bound_scopes'][] = ['target_ticket_id' => $old->id];
    $this->actingAs($this->agent)->postJson("/it/tickets/{$source->id}/merge-candidates/validate", $input)
        ->assertNotFound()->assertDontSee('Private retained proposal');
    expect($source->fresh()->merged_into_ticket_id)->toBeNull();
});

test('merge RAM recovery rejects another actor route context or unrelated draft fields', function (string $fault) {
    $source = mergeTicket(['status' => 'open']);
    $target = mergeTicket(['status' => 'open']);
    $input = mergeCandidateInput($source, $target);
    if ($fault === 'actor') {
        $input['actor_user_id'] = $this->worker->id;
    }
    if ($fault === 'context') {
        $input['ticket_id'] = $target->id;
    }
    if ($fault === 'fields') {
        $input['fields']['body'] = 'Unrelated operation';
    }
    $this->actingAs($this->agent)->postJson("/it/tickets/{$source->id}/merge-candidates/validate", $input)
        ->assertStatus($fault === 'fields' ? 422 : 403);
})->with(['actor', 'context', 'fields']);

test('survivor discovery lists canonical originals without an origin query and retains nested history', function () {
    $target = mergeTicket();
    $middle = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $target->id]);
    $source = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $middle->id]);
    $this->actingAs($this->agent)->getJson(route('it.tickets.show', $target))
        ->assertOk()->assertJsonCount(1, 'ticket.merged_originals.data')
        ->assertJsonPath('ticket.merged_originals.data.0.id', $middle->id)
        ->assertJsonPath('ticket.merged_originals.data.0.tasks_href', "/it/tickets/{$middle->id}/original?tab=tasks")
        ->assertJsonPath('ticket.merged_originals.data.0.approvals_href', "/it/tickets/{$middle->id}/original?tab=approvals")
        ->assertJsonPath('ticket.merged_originals.data.0.links_href', "/it/tickets/{$middle->id}/original?tab=links");
    $this->actingAs($this->agent)->getJson(route('it.tickets.original', $middle))
        ->assertOk()->assertJsonPath('ticket.merged_originals.data.0.id', $source->id)
        ->assertJsonPath('can.manage', false);
    expect($source->fresh()->merged_into_ticket_id)->toBe($middle->id);
});

test('original discovery withholds private controls from participants and hides inaccessible predecessors', function () {
    $target = mergeTicket();
    $visible = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $target->id]);
    $hidden = mergeTicket(['status' => 'closed', 'title' => 'Hidden predecessor title', 'merged_into_ticket_id' => $target->id,
        'requester_user_id' => $this->otherWorker->id, 'requested_for_user_id' => $this->otherWorker->id]);
    mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $hidden->id]);
    $this->actingAs($this->worker)->getJson(route('it.tickets.show', $target))
        ->assertOk()->assertJsonCount(1, 'ticket.merged_originals.data')
        ->assertJsonPath('ticket.merged_originals.data.0.id', $visible->id)
        ->assertJsonPath('ticket.merged_originals.data.0.tasks_href', null)
        ->assertJsonPath('ticket.merged_originals.data.0.approvals_href', null)
        ->assertJsonPath('ticket.merged_originals.data.0.links_href', null)
        ->assertDontSee('Hidden predecessor title');
    $this->actingAs($this->worker)->getJson(route('it.tickets.original', $hidden))->assertNotFound();
});

test('original discovery paginates only visible records and keeps original routes for nested pages', function () {
    $final = mergeTicket();
    $target = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $final->id]);
    $ids = collect(range(1, 12))->map(fn () => mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $target->id])->id)->reverse()->values();
    $first = $this->actingAs($this->worker)->getJson(route('it.tickets.original', $target))
        ->assertOk()->assertJsonCount(10, 'ticket.merged_originals.data')
        ->assertJsonPath('ticket.merged_originals.previous_page_url', null);
    expect(collect($first->json('ticket.merged_originals.data'))->pluck('id')->all())->toBe($ids->take(10)->all());
    $next = $first->json('ticket.merged_originals.next_page_url');
    expect($next)->toBe("/it/tickets/{$target->id}/original?originals_page=2");
    $last = $this->actingAs($this->worker)->getJson($next)->assertOk()->assertJsonCount(2, 'ticket.merged_originals.data')
        ->assertJsonPath('ticket.merged_originals.next_page_url', null);
    expect(collect($last->json('ticket.merged_originals.data'))->pluck('id')->all())->toBe($ids->skip(10)->values()->all());
});

test('original discovery refreshes actor approval and record scope instead of trusting stale instances', function () {
    $target = mergeTicket();
    $source = mergeTicket(['status' => 'closed', 'merged_into_ticket_id' => $target->id]);
    $service = app(ItTicketMergeService::class);
    expect($service->originalRecords($target, $this->worker)['data'])->toHaveCount(1);
    $source->fresh()->forceFill(['requester_user_id' => $this->otherWorker->id, 'requested_for_user_id' => $this->otherWorker->id])->save();
    expect($service->originalRecords($target, $this->worker)['data'])->toBe([]);
    $this->worker->fresh()->forceFill(['approved_at' => null])->save();
    expect(fn () => $service->originalRecords($target, $this->worker))->toThrow(ModelNotFoundException::class);
});
