<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Enums\ItTicketDraftPurpose as Purpose;
use App\Domain\It\Exceptions\ItTicketDraftException;
use App\Domain\It\Services\ItTicketDraftService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketDraft;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    config(['it.drafts.enabled' => true, 'it.drafts.retention_days' => 2, 'it.drafts.terminal_retention_days' => 3]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->agent = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    foreach ([$this->worker, $this->agent] as $actor) {
        $actor->roles()->syncWithoutDetaching(Role::query()->where('name', $actor->role)->pluck('id'));
        ensureCanonicalHrStaffProfile($actor, $this->site);
    }
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->worker->id]);
    $this->requestUuid = (string) Str::uuid();
    $this->service = app(ItTicketDraftService::class);
    Notification::fake();
});

function initializeItDraft($test, User $actor, Purpose $purpose = Purpose::RequesterIntake): array
{
    return $test->actingAs($actor)->postJson('/it/drafts/context', [
        'actor_user_id' => $actor->id, 'purpose' => $purpose->value,
        ...($purpose->requiresTicket() ? ['ticket_id' => $test->ticket->id] : ['request_uuid' => $test->requestUuid]),
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('draft');
}

test('draft storage stays disabled until both retention decisions are configured', function ($enabled, $content, $terminal) {
    config(['it.drafts.enabled' => $enabled, 'it.drafts.retention_days' => $content, 'it.drafts.terminal_retention_days' => $terminal]);
    $this->actingAs($this->worker)->post('/it/drafts/context', [
        'actor_user_id' => $this->worker->id, 'purpose' => 'requester_intake', 'request_uuid' => $this->requestUuid,
    ])->assertStatus(503)->assertJsonPath('code', 'drafts_disabled')->assertHeader('Cache-Control', 'no-store, private');
    expect(ItTicketDraft::query()->count())->toBe(0);
})->with([[false, 2, 3], [true, null, 3], [true, 2, null], [true, '1.5', 3]]);

test('draft metadata never hydrates private fields and explicit resume decrypts only the owner content', function () {
    $draft = initializeItDraft($this, $this->worker);
    $url = '/it/drafts/'.$draft['draft_uuid'];
    $private = 'PRIVATE DRAFT TEXT that must not enter ordinary metadata';
    $this->patchJson($url, ['actor_user_id' => $this->worker->id, 'expected_revision' => 0,
        'fields' => ['title' => $private, 'site_id' => $this->site->id], 'step_index' => 1])
        ->assertOk()->assertJsonPath('draft.revision', 1)->assertJsonPath('draft.has_content', true)
        ->assertJsonMissingPath('payload')->assertDontSee($private)->assertHeader('Cache-Control', 'no-store, private');
    $row = ItTicketDraft::query()->sole();
    expect(DB::table('it_ticket_drafts')->value('encrypted_payload'))->not->toContain($private)
        ->and($row->encrypted_payload['fields']['title'])->toBe($private)
        ->and(json_encode($row))->not->toContain($private)->not->toContain($row->payload_hash)
        ->and(AuditLog::query()->where('action', 'like', 'it.draft.%')->get()->toJson())->not->toContain($private);
    $this->getJson($url.'?actor_user_id='.$this->worker->id)->assertOk()->assertDontSee($private);
    $same = initializeItDraft($this, $this->worker);
    expect($same['draft_uuid'])->toBe($draft['draft_uuid'])->and($same['revision'])->toBe(1);
    $this->postJson($url.'/resume', ['actor_user_id' => $this->worker->id])->assertOk()
        ->assertJsonPath('payload.fields.title', $private)->assertJsonPath('payload.step_index', 1)
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('unknown foreign and inaccessible draft identities disclose the same safe response', function () {
    $draft = initializeItDraft($this, $this->worker);
    $foreign = $this->actingAs($this->agent)->postJson('/it/drafts/'.$draft['draft_uuid'].'/resume', ['actor_user_id' => $this->agent->id])
        ->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    $missing = $this->postJson('/it/drafts/'.Str::uuid().'/resume', ['actor_user_id' => $this->agent->id])->assertNotFound();
    expect($foreign->json())->toBe($missing->json());
    $this->patchJson('/it/drafts/'.$draft['draft_uuid'], ['actor_user_id' => $this->worker->id,
        'expected_revision' => 0, 'fields' => ['title' => 'Old actor buffer'], 'step_index' => 0])
        ->assertForbidden()->assertJsonPath('code', 'access_unavailable');
    expect(ItTicketDraft::query()->sole()->encrypted_payload)->toBeNull();
});

test('public and internal drafts stay separate and participant status never grants internal recovery', function () {
    $public = initializeItDraft($this, $this->agent, Purpose::PublicReply);
    $internal = initializeItDraft($this, $this->agent, Purpose::InternalNote);
    foreach ([[$public, 'Public buffer'], [$internal, 'Private internal buffer']] as [$draft, $body]) {
        $this->service->save($this->agent, $draft['draft_uuid'], 0, ['body' => $body], 0, null);
    }
    expect($public['draft_uuid'])->not->toBe($internal['draft_uuid']);
    $this->agent->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'it.viewSensitive')->sole()->id => ['allowed' => false],
    ]);
    $this->ticket->forceFill(['is_sensitive' => true, 'requester_user_id' => $this->agent->id])->save();
    $this->postJson('/it/drafts/'.$public['draft_uuid'].'/resume', ['actor_user_id' => $this->agent->id])
        ->assertOk()->assertJsonPath('payload.fields.body', 'Public buffer');
    $this->postJson('/it/drafts/'.$internal['draft_uuid'].'/resume', ['actor_user_id' => $this->agent->id])
        ->assertNotFound()->assertDontSee('Private internal buffer');
    $this->postJson('/it/drafts/context', ['actor_user_id' => $this->agent->id,
        'purpose' => 'internal_note', 'ticket_id' => $this->ticket->id])->assertNotFound();
});

test('old Site bindings are checked before cleared fields or an accessible replacement can be saved', function () {
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $draft = initializeItDraft($this, $this->worker);
    $this->service->save($this->worker, $draft['draft_uuid'], 0, ['title' => 'Bound private text', 'site_id' => $this->site->id], 0, null);
    ensureCanonicalHrStaffProfile($this->worker, $other);
    foreach ([null, $other->id] as $site) {
        $this->patchJson('/it/drafts/'.$draft['draft_uuid'], ['actor_user_id' => $this->worker->id,
            'expected_revision' => 1, 'fields' => ['site_id' => $site], 'step_index' => 0])->assertNotFound();
    }
    $this->postJson('/it/drafts/'.$draft['draft_uuid'].'/resume', ['actor_user_id' => $this->worker->id])->assertNotFound();
    expect(ItTicketDraft::query()->sole()->revision)->toBe(1);
});

test('current role and account approval are rechecked despite a retained service actor', function () {
    $draft = initializeItDraft($this, $this->worker, Purpose::PublicReply);
    $this->worker->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'it.request')->sole()->id => ['allowed' => false],
        Permission::query()->where('key', 'it.view')->sole()->id => ['allowed' => false],
    ]);
    expect(fn () => $this->service->resume($this->worker, $draft['draft_uuid']))->toThrow(ItTicketDraftException::class);
    $this->worker->forceFill(['approved_at' => null])->save();
    $this->actingAs($this->worker->fresh())->postJson('/it/drafts/'.$draft['draft_uuid'].'/resume', ['actor_user_id' => $this->worker->id])
        ->assertForbidden()->assertJsonPath('code', 'access_unavailable')->assertHeader('Cache-Control', 'no-store, private');
});

test('identical lost acknowledgement retries return the saved revision but changed stale text conflicts', function () {
    $draft = initializeItDraft($this, $this->worker);
    $input = ['actor_user_id' => $this->worker->id, 'expected_revision' => 0, 'fields' => ['title' => 'Original private text'], 'step_index' => 0];
    $url = '/it/drafts/'.$draft['draft_uuid'];
    $first = $this->patchJson($url, $input)->assertOk()->json('draft');
    $this->patchJson($url, $input)->assertOk()->assertJsonPath('draft.revision', $first['revision']);
    $this->patchJson($url, [...$input, 'fields' => ['title' => 'Later unsaved text']])->assertConflict()
        ->assertJsonPath('code', 'draft_conflict')->assertJsonPath('current.revision', 1)
        ->assertDontSee('Original private text')->assertDontSee('Later unsaved text')->assertHeader('Cache-Control', 'no-store, private');
    expect(AuditLog::query()->where('action', 'it.draft.saved')->count())->toBe(1)
        ->and(ItTicketDraft::query()->sole()->encrypted_payload['fields']['title'])->toBe('Original private text');
});

test('discard is revision aware idempotent and a fresh generation cannot be changed by delayed old requests', function () {
    $draft = initializeItDraft($this, $this->worker, Purpose::PublicReply);
    $url = '/it/drafts/'.$draft['draft_uuid'];
    $this->service->save($this->worker, $draft['draft_uuid'], 0, ['body' => 'Unsent text'], 0, null);
    $this->deleteJson($url, ['actor_user_id' => $this->worker->id, 'expected_revision' => 0])->assertConflict();
    foreach ([1, 1] as $revision) {
        $this->deleteJson($url, ['actor_user_id' => $this->worker->id, 'expected_revision' => $revision])
            ->assertOk()->assertJsonPath('draft.state', 'discarded')->assertJsonPath('draft.revision', 2);
    }
    expect(ItTicketDraft::query()->sole()->encrypted_payload)->toBeNull();
    $this->patchJson($url, ['actor_user_id' => $this->worker->id, 'expected_revision' => 1, 'fields' => ['body' => 'Delayed save'], 'step_index' => 0])
        ->assertConflict()->assertJsonPath('code', 'draft_terminal');
    $new = $this->postJson($url.'/start-new', ['actor_user_id' => $this->worker->id, 'expected_revision' => 2])
        ->assertOk()->assertJsonPath('draft.revision', 3)->json('draft');
    expect($new['draft_uuid'])->not->toBe($draft['draft_uuid']);
    $this->postJson($url.'/start-new', ['actor_user_id' => $this->worker->id, 'expected_revision' => 2])->assertNotFound();
    $this->deleteJson($url, ['actor_user_id' => $this->worker->id, 'expected_revision' => 2])->assertNotFound();
    $this->postJson('/it/drafts/'.$new['draft_uuid'].'/start-new', ['actor_user_id' => $this->worker->id, 'expected_revision' => 3])->assertConflict();
    expect(ItTicketDraft::query()->sole()->revision)->toBe(3)->and(ItTicketDraft::query()->count())->toBe(1);
});

test('settled workflow and changed ticket version block submission without destroying authorized text', function () {
    $draft = initializeItDraft($this, $this->agent, Purpose::PublicResolution);
    $this->service->save($this->agent, $draft['draft_uuid'], 0, ['note' => 'Resolution explanation'], 0, 1);
    $this->ticket->forceFill(['lock_version' => 2])->save();
    $meta = $this->service->inspect($this->agent, $draft['draft_uuid']);
    expect($meta['blocker']['code'])->toBe('ticket_changed')->and($meta['capabilities']['submit'])->toBeFalse();
    $this->ticket->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
    $response = $this->postJson('/it/drafts/'.$draft['draft_uuid'].'/resume', ['actor_user_id' => $this->agent->id])
        ->assertOk()->assertJsonPath('payload.fields.note', 'Resolution explanation')->assertJsonPath('draft.blocker.code', 'ticket_settled');
    expect($response->json('draft.capabilities'))->toMatchArray(['read' => true, 'save' => true, 'submit' => false]);
    $this->service->save($this->agent, $draft['draft_uuid'], 1, ['note' => 'Retained for review'], 0, 1);
});

test('committed intake receipt suspends draft changes and points only to canonical recovery', function () {
    $draft = initializeItDraft($this, $this->worker);
    $this->service->save($this->worker, $draft['draft_uuid'], 0, ['title' => 'Uncertain submission'], 0, null);
    ItTicketCommandReceipt::query()->create(['actor_user_id' => $this->worker->id, 'channel' => 'browser', 'operation' => 'ticket.create',
        'request_uuid' => $this->requestUuid, 'request_hash' => str_repeat('a', 64), 'it_ticket_id' => $this->ticket->id, 'committed_at' => now()]);
    $this->getJson('/it/drafts/'.$draft['draft_uuid'].'?actor_user_id='.$this->worker->id)->assertOk()
        ->assertJsonPath('draft.blocker.code', 'request_committed')->assertJsonPath('draft.capabilities.save', false)
        ->assertJsonPath('draft.capabilities.submit', false)->assertJsonPath('draft.blocker.recovery_url', '/it/ticket-commands/'.$this->requestUuid);
    $this->patchJson('/it/drafts/'.$draft['draft_uuid'], ['actor_user_id' => $this->worker->id, 'expected_revision' => 1,
        'fields' => ['title' => 'Changed retry'], 'step_index' => 0])->assertConflict()->assertJsonPath('code', 'draft_submission_exists');
});

test('consumption shares the canonical transaction and rollback restores the draft', function () {
    $draft = initializeItDraft($this, $this->worker, Purpose::PublicReply);
    $this->service->save($this->worker, $draft['draft_uuid'], 0, ['body' => 'Transactional text'], 0, null);
    try {
        DB::transaction(function () use ($draft) {
            $this->service->consume($this->worker, $draft['draft_uuid'], 1, Purpose::PublicReply, $this->ticket->id, null);
            throw new RuntimeException('Later canonical write failed');
        });
    } catch (RuntimeException) {
        // The enclosing business write, draft scrub and draft audit all roll back.
    }
    expect(ItTicketDraft::query()->sole()->state)->toBe('active')
        ->and(ItTicketDraft::query()->sole()->encrypted_payload['fields']['body'])->toBe('Transactional text')
        ->and(AuditLog::query()->where('action', 'it.draft.consumed')->count())->toBe(0);
    DB::transaction(fn () => $this->service->consume($this->worker, $draft['draft_uuid'], 1, Purpose::PublicReply, $this->ticket->id, null));
    expect(ItTicketDraft::query()->sole()->state)->toBe('consumed')->and(ItTicketDraft::query()->sole()->encrypted_payload)->toBeNull();
    expect(fn () => DB::transaction(fn () => $this->service->consume($this->worker, $draft['draft_uuid'], 1, Purpose::PublicReply, $this->ticket->id, null)))
        ->toThrow(ItTicketDraftException::class);
    expect(AuditLog::query()->where('action', 'it.draft.consumed')->count())->toBe(1);
});

test('expired private contents cannot resume or save and expiration never resets the generation silently', function () {
    $draft = initializeItDraft($this, $this->worker);
    $this->service->save($this->worker, $draft['draft_uuid'], 0, ['title' => 'Expired private text'], 0, null);
    $this->travel(3)->days();
    $same = initializeItDraft($this, $this->worker);
    expect($same['draft_uuid'])->toBe($draft['draft_uuid'])->and($same['state'])->toBe('expired')->and($same['has_content'])->toBeFalse();
    $this->postJson('/it/drafts/'.$draft['draft_uuid'].'/resume', ['actor_user_id' => $this->worker->id])
        ->assertConflict()->assertJsonPath('code', 'draft_terminal')->assertDontSee('Expired private text');
    $this->patchJson('/it/drafts/'.$draft['draft_uuid'], ['actor_user_id' => $this->worker->id, 'expected_revision' => 1,
        'fields' => ['title' => 'Resurrected'], 'step_index' => 0])->assertConflict();
});

test('invalid partial payloads never flash into session and unexpected failures return no private debug data', function () {
    $draft = initializeItDraft($this, $this->worker);
    $this->patch('/it/drafts/'.$draft['draft_uuid'], ['actor_user_id' => $this->worker->id, 'expected_revision' => 0,
        'fields' => ['title' => 'PRIVATE INVALID BODY', 'password' => 'PRIVATE SECRET'], 'step_index' => 0])
        ->assertUnprocessable()->assertJsonPath('code', 'draft_validation')->assertSessionMissing('_old_input')
        ->assertDontSee('PRIVATE INVALID BODY')->assertDontSee('PRIVATE SECRET')->assertHeader('Cache-Control', 'no-store, private');
    Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $entry): void {
        if ($entry->action === 'it.draft.resumed') {
            throw new RuntimeException('PRIVATE SQL BINDING');
        }
    });
    Log::spy();
    config(['app.debug' => true]);
    $this->postJson('/it/drafts/'.$draft['draft_uuid'].'/resume', ['actor_user_id' => $this->worker->id])
        ->assertStatus(500)->assertJsonPath('code', 'draft_request_failed')->assertDontSee('PRIVATE SQL BINDING')
        ->assertJsonMissingPath('exception')->assertHeader('Cache-Control', 'no-store, private');
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => ! str_contains(json_encode([$message, $context]), 'PRIVATE SQL BINDING'));
});

test('unauthenticated and missing-route draft responses remain uncached JSON', function () {
    $this->post('/it/drafts/context', ['fields' => ['title' => 'Do not flash']])
        ->assertUnauthorized()->assertJsonPath('code', 'session_expired')->assertSessionMissing('_old_input')
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/it/drafts/not-a-uuid')->assertNotFound()->assertJsonPath('code', 'draft_unavailable')
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('canonical ticket creation consumes exactly its submitted draft and receipt replay does not repeat consumption', function () {
    $draft = initializeItDraft($this, $this->worker);
    $this->service->save($this->worker, $draft['draft_uuid'], 0, ['title' => 'Committed draft title', 'site_id' => $this->site->id], 0, null);
    $input = ['request_uuid' => $this->requestUuid, 'title' => 'Committed draft title', 'category' => 'hardware', 'priority' => 'normal',
        'site_id' => $this->site->id, 'draft_uuid' => $draft['draft_uuid'], 'draft_revision' => 1, 'draft_actor_user_id' => $this->worker->id];
    $first = $this->postJson('/it/tickets', $input)->assertCreated()->assertJsonPath('status', 'committed');
    expect(ItTicketDraft::query()->sole()->state)->toBe('consumed')->and(ItTicketDraft::query()->sole()->encrypted_payload)->toBeNull();
    $this->postJson('/it/tickets', $input)->assertOk()->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.replayed', true);
    expect(AuditLog::query()->where('action', 'it.draft.consumed')->count())->toBe(1)->and(ItTicketCommandReceipt::query()->count())->toBe(1);
    $this->postJson('/it/tickets', [...$input, 'draft_revision' => 2])->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
});

test('stale draft or rejected canonical intake leaves no new receipt ticket or scrubbed content', function () {
    $draft = initializeItDraft($this, $this->worker);
    $this->service->save($this->worker, $draft['draft_uuid'], 0, ['title' => 'Keep rejected input'], 0, null);
    $input = ['request_uuid' => $this->requestUuid, 'title' => 'Keep rejected input', 'category' => 'hardware', 'priority' => 'normal',
        'site_id' => $this->site->id, 'draft_uuid' => $draft['draft_uuid'], 'draft_revision' => 0, 'draft_actor_user_id' => $this->worker->id];
    $this->postJson('/it/tickets', $input)->assertConflict()->assertJsonPath('code', 'draft_conflict');
    $hidden = Site::factory()->create();
    $this->postJson('/it/tickets', [...$input, 'draft_revision' => 1, 'site_id' => $hidden->id])->assertUnprocessable()->assertJsonValidationErrors('site_id');
    expect(ItTicketCommandReceipt::query()->count())->toBe(0)->and(ItTicket::query()->count())->toBe(1)
        ->and(ItTicketDraft::query()->sole()->revision)->toBe(1)->and(ItTicketDraft::query()->sole()->encrypted_payload['fields']['title'])->toBe('Keep rejected input')
        ->and(AuditLog::query()->where('action', 'it.draft.consumed')->count())->toBe(0);
});

test('canonical edit rejects stale draft revisions then consumes only the confirmed version in its transaction', function () {
    $draft = initializeItDraft($this, $this->agent, Purpose::TicketEdit);
    $this->service->save($this->agent, $draft['draft_uuid'], 0, ['subcategory' => 'Reviewed edit'], 0, 1);
    $input = ['expected_version' => 1, 'subcategory' => 'Reviewed edit', 'draft_uuid' => $draft['draft_uuid'],
        'draft_revision' => 0, 'draft_actor_user_id' => $this->agent->id];
    $this->patchJson('/it/tickets/'.$this->ticket->id, $input)->assertConflict()->assertJsonPath('code', 'draft_conflict');
    expect($this->ticket->fresh()->subcategory)->toBeNull()->and(ItTicketDraft::query()->sole()->state)->toBe('active');
    $this->patchJson('/it/tickets/'.$this->ticket->id, [...$input, 'draft_revision' => 1])->assertOk()
        ->assertJsonPath('status', 'committed')->assertJsonPath('data.id', $this->ticket->id)
        ->assertJsonPath('data.draft', ['draft_uuid' => $draft['draft_uuid'], 'submitted_revision' => 1, 'revision' => 2, 'state' => 'consumed']);
    expect($this->ticket->fresh()->subcategory)->toBe('Reviewed edit')->and(ItTicketDraft::query()->sole()->state)->toBe('consumed');
    $this->patchJson('/it/tickets/'.$this->ticket->id, [...$input, 'expected_version' => $this->ticket->fresh()->lock_version,
        'draft_revision' => 2, 'subcategory' => 'Improper reuse'])->assertConflict()->assertJsonPath('code', 'draft_terminal');
    expect($this->ticket->fresh()->subcategory)->toBe('Reviewed edit');
});

test('failed canonical resolution rolls back draft consumption and a valid retry resolves with its public note', function () {
    $draft = initializeItDraft($this, $this->agent, Purpose::PublicResolution);
    $evidence = ['resolution_code' => 'restored', 'resolution_verification' => 'Confirmed the repaired service.'];
    $this->service->save($this->agent, $draft['draft_uuid'], 0, [...$evidence, 'note' => 'Verified repair details'], 0, 1);
    $input = [...$evidence, 'expected_version' => 1, 'note' => 'Verified repair details', 'notify_requester' => false,
        'draft_uuid' => $draft['draft_uuid'], 'draft_revision' => 1, 'draft_actor_user_id' => $this->agent->id];
    Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $entry): void {
        if ($entry->action === 'it.ticket.resolved') {
            throw new RuntimeException('Synthetic audit failure');
        }
    });
    $this->postJson('/it/tickets/'.$this->ticket->id.'/resolve', $input)->assertStatus(500);
    expect($this->ticket->fresh()->status)->toBe('open')->and($this->ticket->comments()->count())->toBe(0)
        ->and(ItTicketDraft::query()->sole()->state)->toBe('active')->and(ItTicketDraft::query()->sole()->encrypted_payload['fields']['note'])->toBe('Verified repair details');
    Event::forget('eloquent.creating: '.AuditLog::class);
    $this->postJson('/it/tickets/'.$this->ticket->id.'/resolve', $input)->assertOk()->assertJsonPath('status', 'committed')
        ->assertJsonPath('data.draft', ['draft_uuid' => $draft['draft_uuid'], 'submitted_revision' => 1, 'revision' => 2, 'state' => 'consumed']);
    expect($this->ticket->fresh()->status)->toBe('resolved')->and($this->ticket->comments()->sole()->body)->toBe("Verified repair details\n\nHow it was checked: Confirmed the repaired service.")
        ->and(ItTicketDraft::query()->sole()->state)->toBe('consumed')->and(ItTicketDraft::query()->sole()->encrypted_payload)->toBeNull();
    $this->postJson('/it/tickets/'.$this->ticket->id.'/resolve', ['resolution_code' => 'restored', 'resolution_verification' => 'Synthetic verification confirmed the expected result.', 'note' => 'Already resolved', 'expected_version' => $this->ticket->fresh()->lock_version])
        ->assertUnprocessable()->assertJsonPath('code', 'ticket_resolution_blocked')->assertJsonMissingPath('status');
});

test('hub and ticket drawer advertise only effectively configured draft recovery without creating a slot', function ($enabled, $terminal, $expected) {
    config(['it.drafts.enabled' => $enabled, 'it.drafts.terminal_retention_days' => $terminal]);
    $this->actingAs($this->worker)->get('/it')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('draftRecovery.enabled', $expected));
    $this->getJson('/it/tickets/'.$this->ticket->id)->assertOk()->assertJsonPath('draftRecovery.enabled', $expected);
    expect(ItTicketDraft::query()->count())->toBe(0);
})->with([[false, 3, false], [true, null, false], [true, 3, true]]);

test('candidate authorization binds the exact local request without returning or persisting private fields', function () {
    $draft = initializeItDraft($this, $this->worker);
    $this->service->save($this->worker, $draft['draft_uuid'], 0, ['title' => 'Previously saved title', 'site_id' => $this->site->id], 0, null);
    $stored = ItTicketDraft::query()->sole()->getRawOriginal();
    $audits = AuditLog::query()->count();
    $candidateUuid = (string) Str::uuid();
    $this->postJson('/it/drafts/'.$draft['draft_uuid'].'/validate-candidate', [
        'actor_user_id' => $this->worker->id, 'candidate_uuid' => $candidateUuid, 'expected_revision' => 1,
        'fields' => ['title' => 'Latest private unsaved local text', 'site_id' => $this->site->id], 'step_index' => 2,
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('candidate', ['candidate_uuid' => $candidateUuid, 'actor_user_id' => $this->worker->id,
            'draft_uuid' => $draft['draft_uuid'], 'purpose' => 'requester_intake', 'context_key' => $draft['context_key'],
            'revision' => 1, 'base_ticket_version' => null, 'authorized' => true])
        ->assertJsonMissingPath('payload')->assertDontSee('Latest private unsaved local text')->assertDontSee('Previously saved title');
    expect(ItTicketDraft::query()->sole()->getRawOriginal())->toBe($stored)->and(AuditLog::query()->count())->toBe($audits);
});

test('candidate validation rechecks newer unsaved Site bindings even when saved metadata remains available', function () {
    $second = Site::factory()->create(['is_active' => true, 'archived' => false]);
    // Use the existing canonical profile rather than granting application-wide access.
    $profile = HrEmployeeProfile::query()->where('user_id', $this->worker->id)->sole();
    $profile->update(['secondary_site_ids' => [$second->id]]);
    $draft = initializeItDraft($this, $this->worker);
    $this->service->save($this->worker, $draft['draft_uuid'], 0, ['title' => 'Saved at the retained Site', 'site_id' => $this->site->id], 0, null);
    $input = ['actor_user_id' => $this->worker->id, 'candidate_uuid' => (string) Str::uuid(), 'expected_revision' => 1,
        'fields' => ['title' => 'Private local work for the other Site', 'site_id' => $second->id], 'step_index' => 0];
    $url = '/it/drafts/'.$draft['draft_uuid'];
    $this->postJson($url.'/validate-candidate', $input)->assertOk();
    $profile->update(['secondary_site_ids' => []]);
    $this->getJson($url.'?actor_user_id='.$this->worker->id)->assertOk();
    $this->postJson($url.'/validate-candidate', $input)->assertNotFound()->assertJsonMissingPath('candidate')
        ->assertDontSee($input['fields']['title']);
    $this->postJson($url.'/validate-candidate', [
        ...$input, 'fields' => ['title' => 'The other Site was cleared', 'site_id' => $this->site->id],
        'bound_scopes' => [['site_id' => $this->site->id], ['site_id' => $second->id]],
    ])->assertNotFound()->assertJsonMissingPath('candidate')->assertDontSee('The other Site was cleared');
    expect(ItTicketDraft::query()->sole()->revision)->toBe(1)
        ->and(ItTicketDraft::query()->sole()->bound_scope['site_id'])->toBe($this->site->id);
});

test('candidate conflicts and terminal generations never authorize stale local recovery', function () {
    $draft = initializeItDraft($this, $this->worker);
    $input = ['actor_user_id' => $this->worker->id, 'candidate_uuid' => (string) Str::uuid(), 'expected_revision' => 0,
        'fields' => ['title' => 'Unsaved local snapshot'], 'step_index' => 0];
    $this->service->save($this->worker, $draft['draft_uuid'], 0, ['title' => 'Newer saved generation state'], 0, null);
    $url = '/it/drafts/'.$draft['draft_uuid'].'/validate-candidate';
    $this->postJson($url, $input)->assertConflict()->assertJsonPath('code', 'draft_conflict')->assertJsonMissingPath('candidate');
    $this->service->discard($this->worker, $draft['draft_uuid'], 1);
    $this->postJson($url, [...$input, 'expected_revision' => 2])->assertConflict()->assertJsonPath('code', 'draft_terminal');
    $this->service->startNew($this->worker, $draft['draft_uuid'], 2);
    $this->postJson($url, [...$input, 'expected_revision' => 3])->assertNotFound()->assertJsonMissingPath('candidate');
});

test('candidate authorization keeps the displayed base version and rejects impossible versions', function () {
    $draft = initializeItDraft($this, $this->agent, Purpose::PublicResolution);
    $this->ticket->forceFill(['lock_version' => 2])->save();
    $input = ['actor_user_id' => $this->agent->id, 'candidate_uuid' => (string) Str::uuid(), 'expected_revision' => 0,
        'fields' => ['note' => 'Original local repair explanation'], 'step_index' => 0, 'base_ticket_version' => 1];
    $url = '/it/drafts/'.$draft['draft_uuid'].'/validate-candidate';
    $this->postJson($url, $input)->assertOk()->assertJsonPath('candidate.base_ticket_version', 1)
        ->assertJsonPath('draft.current_ticket_version', 2)->assertJsonPath('draft.capabilities.submit', false);
    $this->postJson($url, [...$input, 'base_ticket_version' => 3])->assertUnprocessable()->assertJsonValidationErrors('base_ticket_version');
    expect(ItTicketDraft::query()->sole()->base_ticket_version)->toBe(1)->and(ItTicketDraft::query()->sole()->encrypted_payload)->toBeNull();
});

test('candidate validation cannot bypass actor purpose or current internal audience authorization', function () {
    $draft = initializeItDraft($this, $this->agent, Purpose::InternalNote);
    $input = ['actor_user_id' => $this->agent->id, 'candidate_uuid' => (string) Str::uuid(), 'expected_revision' => 0,
        'fields' => ['body' => 'Private local internal note'], 'step_index' => 0];
    $url = '/it/drafts/'.$draft['draft_uuid'].'/validate-candidate';
    $this->postJson($url, [...$input, 'fields' => ['note' => 'Wrong purpose field']])->assertUnprocessable()->assertJsonMissingPath('candidate');
    $this->actingAs($this->worker)->postJson($url, $input)->assertForbidden()->assertJsonPath('code', 'access_unavailable');
    $this->actingAs($this->agent);
    $this->agent->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'it.viewSensitive')->sole()->id => ['allowed' => false],
    ]);
    $this->ticket->forceFill(['is_sensitive' => true, 'requester_user_id' => $this->agent->id])->save();
    $this->postJson($url, $input)->assertNotFound()->assertJsonMissingPath('candidate')->assertDontSee('Private local internal note');
});
