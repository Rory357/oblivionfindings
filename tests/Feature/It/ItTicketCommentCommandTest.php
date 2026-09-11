<?php

use App\Domain\It\Enums\ItTicketDraftPurpose;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItTicketDraftService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Domain\It\Services\ItTicketMergeService;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketComment;
use App\Models\ItTicketDraft;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketRepliedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->agent = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    foreach ([$this->worker, $this->agent] as $actor) {
        $actor->roles()->syncWithoutDetaching(Role::query()->where('name', $actor->role)->pluck('id'));
        ensureCanonicalHrStaffProfile($actor, $this->site);
    }
    $this->ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id, 'requester_user_id' => $this->worker->id,
        'assigned_to_user_id' => $this->agent->id, 'status' => 'open', 'first_responded_at' => null,
    ]);
    $this->input = [
        'request_uuid' => (string) Str::uuid(), 'actor_user_id' => $this->agent->id,
        'expected_version' => (int) $this->ticket->refresh()->lock_version,
        'body' => 'Private synthetic reply must not enter command receipts.', 'is_internal' => false,
    ];
    Notification::fake();
    Bus::fake([DispatchItTicketNotifications::class]);
    Storage::fake(ItAttachment::DISK);
});

test('a reply command commits once and recovery returns its exact identity without private content', function () {
    $path = "/it/tickets/{$this->ticket->id}/comments";
    $first = $this->actingAs($this->agent)->postJson($path, $this->input)->assertCreated()
        ->assertJsonPath('status', 'committed')->assertJsonPath('data.viewer_user_id', $this->agent->id)
        ->assertJsonPath('data.is_internal', false)->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.delivery.attempt_statuses.queued', 1)->assertDontSee($this->input['body']);
    $commentId = $first->json('data.comment_id');
    $version = $first->json('data.lock_version');
    $events = $this->ticket->events()->count();
    $this->postJson($path, $this->input)->assertOk()->assertJsonPath('data.comment_id', $commentId)
        ->assertJsonPath('data.lock_version', $version)->assertJsonPath('data.replayed', true);
    $this->getJson("/it/tickets/{$this->ticket->id}/comment-commands/{$this->input['request_uuid']}?actor_user_id={$this->agent->id}")
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.comment_id', $commentId)->assertDontSee($this->input['body']);
    expect($this->ticket->comments()->count())->toBe(1)
        ->and($this->ticket->events()->count())->toBe($events)
        ->and(ItTicketCommandReceipt::query()->count())->toBe(1)
        ->and(ItEmailDelivery::query()->count())->toBe(1)
        ->and(ItTicketCommandReceipt::query()->sole()->toJson())->not->toContain($this->input['body']);
    Notification::assertNothingSent();
});

test('a reply UUID cannot change its body audience or expected version', function ($changes) {
    $this->actingAs($this->agent)->postJson("/it/tickets/{$this->ticket->id}/comments", $this->input)->assertCreated();
    $this->postJson("/it/tickets/{$this->ticket->id}/comments", [...$this->input, ...$changes])
        ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    expect($this->ticket->comments()->count())->toBe(1)->and(ItEmailDelivery::query()->count())->toBe(1);
})->with([
    'body' => [['body' => 'A different reply']],
    'audience' => [['is_internal' => true]],
    'version' => [['expected_version' => 999]],
]);

test('a deferred dispatcher failure still acknowledges the saved reply with its durable queued delivery', function () {
    Bus::shouldReceive('dispatchAfterResponse')->andThrow(new RuntimeException('Synthetic deferred scheduler failure'));
    $path = "/it/tickets/{$this->ticket->id}/comments";
    $first = $this->actingAs($this->agent)->postJson($path, $this->input)->assertCreated()
        ->assertJsonPath('status', 'committed')->assertJsonPath('data.delivery.attempt_statuses.queued', 1);
    $this->postJson($path, $this->input)->assertOk()->assertJsonPath('data.comment_id', $first->json('data.comment_id'));
    expect($this->ticket->comments()->count())->toBe(1)->and(ItEmailDelivery::query()->count())->toBe(1);
});

test('last public responsibility follows requester replies while internal notes preserve first response and queue evidence', function () {
    $service = app(ItTicketInteractionService::class);
    $service->addCommentCommand($this->ticket, $this->agent, $this->input);
    $firstResponse = $this->ticket->fresh()->first_responded_at;
    expect($firstResponse)->not->toBeNull()->and($this->ticket->fresh()->next_response_party)->toBe('requester');
    $requester = [...$this->input, 'request_uuid' => (string) Str::uuid(), 'actor_user_id' => $this->worker->id,
        'expected_version' => $this->ticket->fresh()->lock_version, 'body' => 'I still need help with this.'];
    $reply = $service->addCommentCommand($this->ticket, $this->worker, $requester);
    expect($this->ticket->fresh()->next_response_party)->toBe('it')
        ->and((int) $this->ticket->fresh()->last_public_comment_id)->toBe((int) $reply->comment->id);
    $service->addCommentCommand($this->ticket, $this->agent, [...$this->input, 'request_uuid' => (string) Str::uuid(),
        'expected_version' => $this->ticket->fresh()->lock_version, 'body' => 'Private internal investigation.', 'is_internal' => true]);
    expect($this->ticket->fresh()->next_response_party)->toBe('it')
        ->and((int) $this->ticket->fresh()->last_public_comment_id)->toBe((int) $reply->comment->id)
        ->and($this->ticket->fresh()->first_responded_at->equalTo($firstResponse))->toBeTrue()
        ->and(ItEmailDelivery::query()->count())->toBe(2);
});

test('a visible observer reply keeps recorded IT responsibility and first response and uses truthful sender copy', function () {
    $service = app(ItTicketInteractionService::class);
    $service->addCommentCommand($this->ticket, $this->agent, $this->input);
    $firstResponse = $this->ticket->fresh()->first_responded_at;
    $role = Role::query()->create(['name' => 'it_reply_observer', 'label' => 'IT reply observer']);
    $role->permissions()->sync(Permission::query()->where('key', 'it.view')->pluck('id'));
    $observer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $observer->roles()->sync([$role->id]);
    ensureCanonicalHrStaffProfile($observer, $this->site);
    $reply = $service->addCommentCommand($this->ticket, $observer, [...$this->input,
        'request_uuid' => (string) Str::uuid(), 'actor_user_id' => $observer->id,
        'expected_version' => $this->ticket->fresh()->lock_version, 'body' => 'Additional visible context from a colleague.']);
    expect($reply->comment->speaker_side)->toBe('observer')
        ->and($this->ticket->fresh()->next_response_party)->toBe('requester')
        ->and($this->ticket->fresh()->first_responded_at->equalTo($firstResponse))->toBeTrue();
    $mail = (new TicketRepliedNotification($reply->ticket, 'requester', $reply->comment->id))->toMail($this->worker);
    expect(implode(' ', $mail->introLines))->toContain('There is a new reply on your ticket:')->not->toContain('IT has replied');
});

test('a dual-role requester note never resumes waiting and their own public message is not an IT first response', function () {
    $this->ticket->forceFill(['requester_user_id' => $this->agent->id, 'status' => 'waiting', 'waiting_party' => 'requester'])->save();
    $service = app(ItTicketInteractionService::class);
    $service->addCommentCommand($this->ticket, $this->agent, [...$this->input,
        'expected_version' => $this->ticket->fresh()->lock_version, 'is_internal' => true]);
    expect($this->ticket->fresh()->status)->toBe('waiting')->and($this->ticket->fresh()->first_responded_at)->toBeNull();
    $service->addCommentCommand($this->ticket, $this->agent, [...$this->input, 'request_uuid' => (string) Str::uuid(),
        'expected_version' => $this->ticket->fresh()->lock_version]);
    expect($this->ticket->fresh()->next_response_party)->toBe('it')->and($this->ticket->fresh()->first_responded_at)->toBeNull();
});

test('stale versions and original actor mismatches cannot create a reply or receipt', function () {
    $this->ticket->forceFill(['subcategory' => 'Newer classification'])->save();
    $this->actingAs($this->agent)->postJson("/it/tickets/{$this->ticket->id}/comments", $this->input)
        ->assertConflict()->assertJsonPath('code', 'stale_ticket');
    $this->actingAs($this->worker)->postJson("/it/tickets/{$this->ticket->id}/comments", $this->input)->assertForbidden();
    expect($this->ticket->comments()->count())->toBe(0)->and(ItTicketCommandReceipt::query()->count())->toBe(0);
});

test('an authorized requested-for public reply resumes requester waiting through the same guarded transition', function () {
    $this->ticket->forceFill(['requester_user_id' => $this->agent->id, 'requested_for_user_id' => $this->worker->id,
        'status' => 'waiting', 'workflow_state' => 'waiting', 'waiting_party' => 'requester'])->save();
    $this->actingAs($this->worker)->postJson("/it/tickets/{$this->ticket->id}/comments", [...$this->input,
        'actor_user_id' => $this->worker->id, 'expected_version' => $this->ticket->fresh()->lock_version])
        ->assertCreated()->assertJsonPath('data.is_internal', false);
    expect($this->ticket->fresh()->status)->toBe('in_progress')
        ->and($this->ticket->fresh()->next_response_party)->toBe('it')
        ->and($this->ticket->fresh()->first_responded_at)->toBeNull();
});

test('the canonical writer reloads an actor whose prior instance retained approved access', function () {
    expect($this->agent->canDo('it.manage'))->toBeTrue();
    DB::table('users')->where('id', $this->agent->id)->update(['approved_at' => null]);
    expect(fn () => app(ItTicketInteractionService::class)->addCommentCommand($this->ticket, $this->agent, $this->input))
        ->toThrow(AuthorizationException::class);
    expect($this->ticket->comments()->count())->toBe(0)->and(ItTicketCommandReceipt::query()->count())->toBe(0);
});

test('a delivery-intent failure rolls back the comment receipt response evidence and audit together', function () {
    $this->mock(ItEmailDeliveryService::class, fn ($mock) => $mock->shouldReceive('prepare')->once()->andThrow(new RuntimeException('Synthetic outbox failure')));
    expect(fn () => app(ItTicketInteractionService::class)->addCommentCommand($this->ticket, $this->agent, $this->input))
        ->toThrow(RuntimeException::class, 'Synthetic outbox failure');
    expect($this->ticket->comments()->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()->count())->toBe(0)
        ->and($this->ticket->fresh()->first_responded_at)->toBeNull()
        ->and($this->ticket->fresh()->next_response_party)->toBeNull()
        ->and(AuditLog::query()->where('action', 'it.ticket.comment.added')->count())->toBe(0);
});

test('settled work rejects a new comment but still permits authorized recovery of its earlier commit', function () {
    $first = $this->actingAs($this->agent)->postJson("/it/tickets/{$this->ticket->id}/comments", $this->input)->assertCreated();
    $this->ticket->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
    $this->postJson("/it/tickets/{$this->ticket->id}/comments", [...$this->input,
        'request_uuid' => (string) Str::uuid(), 'expected_version' => $this->ticket->fresh()->lock_version])
        ->assertUnprocessable()->assertJsonPath('code', 'comment_rejected');
    $this->postJson("/it/tickets/{$this->ticket->id}/comments", $this->input)->assertOk()
        ->assertJsonPath('data.comment_id', $first->json('data.comment_id'));
    expect($this->ticket->comments()->count())->toBe(1)->and(ItTicketCommandReceipt::query()->count())->toBe(1);
});

test('copied or access-revoked internal receipt identities do not disclose a committed comment', function () {
    $this->actingAs($this->agent)->postJson("/it/tickets/{$this->ticket->id}/comments", [...$this->input, 'is_internal' => true])->assertCreated();
    $path = "/it/tickets/{$this->ticket->id}/comment-commands/{$this->input['request_uuid']}";
    $this->actingAs($this->worker)->getJson($path.'?actor_user_id='.$this->worker->id)->assertNotFound()->assertJsonMissingPath('data');
    $other = Site::factory()->create();
    ensureCanonicalHrStaffProfile($this->agent, $other);
    $this->ticket->forceFill(['assigned_to_user_id' => null, 'owner_user_id' => null, 'team_id' => null, 'queue_id' => null])->save();
    $this->actingAs($this->agent->fresh())->getJson($path.'?actor_user_id='.$this->agent->id)
        ->assertNotFound()->assertJsonMissingPath('data')->assertDontSee($this->input['body']);
});

test('a technician participating outside their approved Site may reply publicly but cannot post an internal note', function () {
    $outside = Site::factory()->create();
    $this->ticket->forceFill(['site_id' => $outside->id, 'requested_for_user_id' => $this->agent->id,
        'assigned_to_user_id' => null, 'owner_user_id' => null, 'team_id' => null, 'queue_id' => null])->save();
    $input = [...$this->input, 'expected_version' => $this->ticket->fresh()->lock_version];
    $this->actingAs($this->agent)->postJson("/it/tickets/{$this->ticket->id}/comments", [...$input, 'is_internal' => true])
        ->assertForbidden()->assertJsonMissingPath('data');
    expect($this->ticket->comments()->count())->toBe(0)->and(ItTicketCommandReceipt::query()->count())->toBe(0);
    $this->postJson("/it/tickets/{$this->ticket->id}/comments", $input)->assertCreated()->assertJsonPath('data.is_internal', false);
    expect($this->ticket->fresh()->next_response_party)->toBe('it')->and($this->ticket->fresh()->first_responded_at)->toBeNull();
});

test('public reply draft consumption commits once with the exact saved content', function () {
    config(['it.drafts.enabled' => true, 'it.drafts.retention_days' => 2, 'it.drafts.terminal_retention_days' => 3]);
    $drafts = app(ItTicketDraftService::class);
    $draft = $drafts->initialize($this->agent, ItTicketDraftPurpose::PublicReply, $this->ticket->id, null);
    $drafts->save($this->agent, $draft['draft_uuid'], 0, ['body' => $this->input['body']], 0, $this->input['expected_version']);
    $input = [...$this->input, 'draft_uuid' => $draft['draft_uuid'], 'draft_revision' => 1, 'draft_actor_user_id' => $this->agent->id];
    $this->actingAs($this->agent)->postJson("/it/tickets/{$this->ticket->id}/comments", [...$input, 'body' => 'Unsaved replacement'])
        ->assertConflict()->assertJsonPath('code', 'draft_content_changed');
    expect(ItTicketDraft::query()->sole()->state)->toBe('active')->and($this->ticket->comments()->count())->toBe(0);
    $this->postJson("/it/tickets/{$this->ticket->id}/comments", $input)->assertCreated()
        ->assertJsonPath('data.draft.submitted_revision', 1)->assertJsonPath('data.draft.revision', 2)->assertJsonPath('data.draft.state', 'consumed');
    $this->postJson("/it/tickets/{$this->ticket->id}/comments", $input)->assertOk()->assertJsonPath('data.replayed', true);
    expect($this->ticket->comments()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.draft.consumed')->count())->toBe(1);
});

test('an internal note transfers the same staged file once and keeps copied downloads private', function () {
    config(['it.drafts.enabled' => true, 'it.drafts.retention_days' => 2, 'it.drafts.terminal_retention_days' => 3]);
    $drafts = app(ItTicketDraftService::class);
    $draft = $drafts->initialize($this->agent, ItTicketDraftPurpose::InternalNote, $this->ticket->id, null);
    $drafts->save($this->agent, $draft['draft_uuid'], 0, ['body' => $this->input['body']], 0, $this->input['expected_version']);
    $this->actingAs($this->agent)->postJson('/it/drafts/'.$draft['draft_uuid'].'/attachments', [
        'actor_user_id' => $this->agent->id, 'expected_revision' => 1, 'upload_uuid' => (string) Str::uuid(),
        'attachment' => UploadedFile::fake()->createWithContent('staged-private.txt', 'Exact staged evidence'),
    ])->assertOk()->assertJsonPath('draft.revision', 3);
    $staged = ItAttachment::query()->sole();
    $input = [...$this->input, 'is_internal' => true, 'draft_uuid' => $draft['draft_uuid'], 'draft_revision' => 3, 'draft_actor_user_id' => $this->agent->id];
    $path = "/it/tickets/{$this->ticket->id}/comments";
    $first = $this->postJson($path, [...$input, 'attachments' => [UploadedFile::fake()->createWithContent('direct-private.txt', 'Exact direct evidence')]])
        ->assertCreated()->assertJsonPath('data.draft.revision', 4)->assertJsonPath('data.delivery.requested', false);
    expect($staged->fresh()->attachable_type)->toBe((new ItTicketComment)->getMorphClass())
        ->and((int) $staged->fresh()->attachable_id)->toBe($first->json('data.comment_id'))
        ->and($staged->fresh()->draft_generation_uuid)->toBeNull();
    $this->postJson($path, [...$input, 'attachments' => [UploadedFile::fake()->createWithContent('direct-private.txt', 'Exact direct evidence')]])
        ->assertOk()->assertJsonPath('data.comment_id', $first->json('data.comment_id'));
    $this->postJson($path, [...$input, 'attachments' => [UploadedFile::fake()->createWithContent('direct-private.txt', 'Changed direct evidence')]])
        ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
    expect(ItAttachment::query()->count())->toBe(2)->and(ItEmailDelivery::query()->count())->toBe(0);
    $this->get('/it/attachments/'.$staged->id)->assertOk();
    $this->actingAs($this->worker)->get('/it/attachments/'.$staged->id)->assertNotFound();
});

test('a failed reply commit preserves staged evidence and defers direct cleanup inside an outer transaction', function () {
    $firstIntentId = (int) ItAttachmentStorageIntent::query()->max('id');
    config(['it.drafts.enabled' => true, 'it.drafts.retention_days' => 2, 'it.drafts.terminal_retention_days' => 3]);
    $drafts = app(ItTicketDraftService::class);
    $draft = $drafts->initialize($this->agent, ItTicketDraftPurpose::PublicReply, $this->ticket->id, null);
    $drafts->save($this->agent, $draft['draft_uuid'], 0, ['body' => $this->input['body']], 0, $this->input['expected_version']);
    $this->actingAs($this->agent)->postJson('/it/drafts/'.$draft['draft_uuid'].'/attachments', [
        'actor_user_id' => $this->agent->id, 'expected_revision' => 1, 'upload_uuid' => (string) Str::uuid(),
        'attachment' => UploadedFile::fake()->createWithContent('keep-staged.txt', 'Keep this saved evidence'),
    ])->assertOk();
    $staged = ItAttachment::query()->sole();
    $input = [...$this->input, 'draft_uuid' => $draft['draft_uuid'], 'draft_revision' => 3, 'draft_actor_user_id' => $this->agent->id];
    $this->mock(ItEmailDeliveryService::class, fn ($mock) => $mock->shouldReceive('prepare')->once()->andThrow(new RuntimeException('Synthetic outbox failure')));
    expect(fn () => app(ItTicketInteractionService::class)->addCommentCommand($this->ticket, $this->agent, $input, [
        UploadedFile::fake()->createWithContent('rolled-back-direct.txt', 'Remove this uncommitted file'),
    ]))->toThrow(RuntimeException::class, 'Synthetic outbox failure');
    expect($staged->fresh()->attachable_type)->toBe((new ItTicketDraft)->getMorphClass())
        ->and($staged->fresh()->draft_storage_state)->toBe('ready')
        ->and(ItTicketDraft::query()->sole()->state)->toBe('active')
        ->and(ItTicketDraft::query()->sole()->revision)->toBe(3)
        ->and($this->ticket->comments()->count())->toBe(0)->and(ItTicketCommandReceipt::query()->count())->toBe(0);
    // The intent committed on another connection after this test took its
    // snapshot. Use a current read while retaining the same outer transaction.
    $intent = ItAttachmentStorageIntent::query()->where('id', '>', $firstIntentId)->lockForUpdate()->sole();
    expect($intent->state)->toBe('reserved')
        ->and(Storage::disk(ItAttachment::DISK)->allFiles('it_attachments'))->toHaveCount(2)
        ->and(Storage::disk(ItAttachment::DISK)->exists($staged->path))->toBeTrue()
        ->and(Storage::disk(ItAttachment::DISK)->exists($intent->path))->toBeTrue();
});

test('staged and direct reply files share the same five-file boundary without consuming a rejected draft', function () {
    config(['it.drafts.enabled' => true, 'it.drafts.retention_days' => 2, 'it.drafts.terminal_retention_days' => 3]);
    $drafts = app(ItTicketDraftService::class);
    $draft = $drafts->initialize($this->agent, ItTicketDraftPurpose::PublicReply, $this->ticket->id, null);
    $drafts->save($this->agent, $draft['draft_uuid'], 0, ['body' => $this->input['body']], 0, $this->input['expected_version']);
    $this->actingAs($this->agent)->postJson('/it/drafts/'.$draft['draft_uuid'].'/attachments', [
        'actor_user_id' => $this->agent->id, 'expected_revision' => 1, 'upload_uuid' => (string) Str::uuid(),
        'attachment' => UploadedFile::fake()->createWithContent('staged.txt', 'Keep staged evidence'),
    ])->assertOk();
    $this->postJson("/it/tickets/{$this->ticket->id}/comments", [...$this->input,
        'draft_uuid' => $draft['draft_uuid'], 'draft_revision' => 3, 'draft_actor_user_id' => $this->agent->id,
        'attachments' => array_map(fn ($index) => UploadedFile::fake()->createWithContent("file-{$index}.txt", "File {$index}"), range(1, 5)),
    ])->assertUnprocessable()->assertJsonPath('code', 'comment_rejected');
    expect(ItTicketDraft::query()->sole()->state)->toBe('active')->and(ItTicketDraft::query()->sole()->revision)->toBe(3)
        ->and(ItAttachment::query()->count())->toBe(1)->and($this->ticket->comments()->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()->count())->toBe(0);
});

test('Awaiting IT uses recorded responsibility across open states and never guesses historical speaker roles', function () {
    $historical = $this->ticket->id;
    foreach (ItTicket::OPEN_STATUSES as $status) {
        ItTicket::factory()->create(['status' => $status, 'first_responded_at' => now(),
            'waiting_party' => $status === 'waiting' ? 'vendor' : null])->forceFill(['next_response_party' => 'it'])->save();
    }
    $ids = ItTicket::query()->where('next_response_party', 'it')->orderBy('id')->pluck('id')->all();
    ItTicket::factory()->create(['status' => 'resolved'])->forceFill(['next_response_party' => 'it'])->save();
    ItTicket::factory()->create()->forceFill(['next_response_party' => 'requester'])->save();
    ItTicket::factory()->create(['merged_into_ticket_id' => $historical])->forceFill(['next_response_party' => 'it'])->save();
    expect(ItTicket::query()->awaitingIt()->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->not->toContain($historical);
});

test('explicit cancellation is permanent idempotent and prevents a delayed reply without discarding its saved draft', function () {
    config(['it.drafts.enabled' => true, 'it.drafts.retention_days' => 2, 'it.drafts.terminal_retention_days' => 3]);
    $draft = app(ItTicketDraftService::class)->initialize($this->agent, ItTicketDraftPurpose::PublicReply, $this->ticket->id, null);
    app(ItTicketDraftService::class)->save($this->agent, $draft['draft_uuid'], 0, ['body' => $this->input['body']], 0, $this->input['expected_version']);
    $path = "/it/tickets/{$this->ticket->id}/comment-commands/{$this->input['request_uuid']}";
    $body = ['actor_user_id' => $this->agent->id, 'is_internal' => false];
    $first = $this->actingAs($this->agent)->postJson($path.'/cancel', $body)->assertOk()
        ->assertJsonPath('status', 'cancelled')->assertJsonPath('data.id', $this->ticket->id)
        ->assertJsonPath('data.viewer_user_id', $this->agent->id)->assertJsonPath('data.is_internal', false)
        ->assertJsonPath('data.replayed', false)->assertDontSee($this->input['body']);
    $this->postJson($path.'/cancel', $body)->assertOk()->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('data.cancelled_at', $first->json('data.cancelled_at'))->assertJsonPath('data.replayed', true);
    $this->getJson($path.'?actor_user_id='.$this->agent->id)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson("/it/tickets/{$this->ticket->id}/comments", [...$this->input,
        'draft_uuid' => $draft['draft_uuid'], 'draft_revision' => 1, 'draft_actor_user_id' => $this->agent->id,
    ])->assertOk()->assertJsonPath('status', 'cancelled');
    expect($this->ticket->comments()->count())->toBe(0)->and(ItEmailDelivery::query()->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()->count())->toBe(1)->and($this->ticket->fresh()->lock_version)->toBe($this->input['expected_version'])
        ->and(ItTicketDraft::query()->sole()->state)->toBe('active')->and(ItTicketDraft::query()->sole()->revision)->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.ticket.comment_command.cancelled')->count())->toBe(1);
});

test('cancellation returns an earlier commit rather than deleting or denying its saved reply', function () {
    $first = $this->actingAs($this->agent)->postJson("/it/tickets/{$this->ticket->id}/comments", $this->input)->assertCreated();
    $this->postJson("/it/tickets/{$this->ticket->id}/comment-commands/{$this->input['request_uuid']}/cancel", [
        'actor_user_id' => $this->agent->id, 'is_internal' => false,
    ])->assertOk()->assertJsonPath('status', 'committed')->assertJsonPath('data.comment_id', $first->json('data.comment_id'))
        ->assertJsonPath('data.lock_version', $first->json('data.lock_version'))->assertJsonPath('data.replayed', true);
    expect($this->ticket->comments()->count())->toBe(1)->and(ItTicketCommandReceipt::query()->sole()->committed_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'it.ticket.comment_command.cancelled')->count())->toBe(0);
});

test('a cancellation-only command prevents rollback from removing its permanent replay protection', function () {
    app(ItTicketInteractionService::class)->cancelCommentCommand($this->ticket, $this->agent, $this->input['request_uuid'], false);
    expect(ItTicketComment::query()->count())->toBe(0)
        ->and($this->ticket->fresh()->next_response_party)->toBeNull()
        ->and(ItTicketCommandReceipt::query()->sole()->it_ticket_comment_id)->toBeNull();
    $migration = require database_path('migrations/2026_09_09_000008_add_it_ticket_conversation_commands.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Retain recorded conversation responsibility and command receipts.');
    expect(Schema::hasColumn('it_ticket_command_receipts', 'result_metadata'))->toBeTrue()
        ->and(ItTicketCommandReceipt::query()->sole()->result_metadata['state'])->toBe('cancelled');
});

test('cancellation keeps original actor and internal audience boundaries and cannot be changed to another audience', function () {
    $path = "/it/tickets/{$this->ticket->id}/comment-commands/{$this->input['request_uuid']}/cancel";
    $this->actingAs($this->worker)->postJson($path, ['actor_user_id' => $this->agent->id, 'is_internal' => false])->assertForbidden();
    $this->postJson($path, ['actor_user_id' => $this->worker->id, 'is_internal' => true])->assertForbidden();
    expect(ItTicketCommandReceipt::query()->count())->toBe(0);
    $this->actingAs($this->agent)->postJson($path, ['actor_user_id' => $this->agent->id, 'is_internal' => true])->assertOk();
    $this->postJson($path, ['actor_user_id' => $this->agent->id, 'is_internal' => false])->assertConflict();
    $this->postJson("/it/tickets/{$this->ticket->id}/comments", $this->input)->assertConflict();
    expect($this->ticket->comments()->count())->toBe(0)->and(ItTicketCommandReceipt::query()->count())->toBe(1);
});

test('a merged comment remains recoverable by its immutable command only while both conversation audiences remain allowed', function () {
    $first = $this->actingAs($this->agent)->postJson("/it/tickets/{$this->ticket->id}/comments", $this->input)->assertCreated();
    $target = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->worker->id,
        'requested_for_user_id' => $this->ticket->requested_for_user_id, 'status' => 'open', 'assigned_to_user_id' => $this->agent->id]);
    app(ItTicketMergeService::class)->merge($this->ticket, $target, $this->agent, 'Same synthetic conversation');
    $path = "/it/tickets/{$this->ticket->id}/comment-commands/{$this->input['request_uuid']}";
    $this->getJson($path.'?actor_user_id='.$this->agent->id)->assertOk()
        ->assertJsonPath('data.id', $this->ticket->id)->assertJsonPath('data.canonical_ticket_id', $target->id)
        ->assertJsonPath('data.comment_id', $first->json('data.comment_id'))->assertJsonPath('data.lock_version', $first->json('data.lock_version'));
    $this->postJson($path.'/cancel', ['actor_user_id' => $this->agent->id, 'is_internal' => false])->assertOk()
        ->assertJsonPath('status', 'committed')->assertJsonPath('data.canonical_ticket_id', $target->id);
    expect((int) ItTicketCommandReceipt::query()->sole()->it_ticket_id)->toBe((int) $this->ticket->id);
    $target->forceFill(['site_id' => Site::factory()->create()->id, 'assigned_to_user_id' => null,
        'owner_user_id' => null, 'team_id' => null, 'queue_id' => null])->save();
    $this->getJson($path.'?actor_user_id='.$this->agent->id)->assertNotFound()->assertJsonMissingPath('data');
});
