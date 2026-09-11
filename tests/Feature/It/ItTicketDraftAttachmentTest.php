<?php

use App\Domain\It\Enums\ItTicketDraftPurpose as Purpose;
use App\Domain\It\Services\ItAttachmentStorageIntentService;
use App\Domain\It\Services\ItTicketDraftPruner;
use App\Domain\It\Services\ItTicketDraftService;
use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketDraft;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    $this->drafts = app(ItTicketDraftService::class);
    $this->actingAs($this->worker);
    $this->draft = $this->drafts->initialize($this->worker, Purpose::RequesterIntake, null, $this->requestUuid);
    $this->url = '/it/drafts/'.$this->draft['draft_uuid'];
    Notification::fake();
    Storage::fake(ItAttachment::DISK);
});

function itDraftUpload($test, int $revision = 0, ?string $uploadUuid = null, string $body = 'Private fixture file bytes')
{
    return $test->postJson($test->url.'/attachments', [
        'actor_user_id' => $test->worker->id, 'expected_revision' => $revision, 'upload_uuid' => $uploadUuid ?? (string) Str::uuid(),
        'attachment' => UploadedFile::fake()->createWithContent('private-fixture.txt', $body),
    ]);
}

function itDraftCreateInput($test, int $revision): array
{
    return ['request_uuid' => $test->requestUuid, 'title' => 'Draft file request', 'category' => 'hardware', 'priority' => 'normal',
        'site_id' => $test->site->id, 'draft_uuid' => $test->draft['draft_uuid'], 'draft_revision' => $revision, 'draft_actor_user_id' => $test->worker->id];
}

test('staged files use canonical private records and only explicit authorized resume reveals their metadata', function () {
    $upload = itDraftUpload($this)->assertOk()->assertJsonPath('attachment.state', 'ready')->assertJsonPath('draft.revision', 2)
        ->assertJsonPath('draft.files.ready', 1)->assertHeader('Cache-Control', 'no-store, private');
    $file = ItAttachment::query()->sole();
    expect($file->attachable_type)->toBe((new ItTicketDraft)->getMorphClass())
        ->and($file->draft_generation_uuid)->toBe($this->draft['draft_uuid'])
        ->and(json_encode($file))->not->toContain($file->draft_content_hash);
    Storage::disk(ItAttachment::DISK)->assertExists($file->path);
    $this->getJson($this->url.'?actor_user_id='.$this->worker->id)->assertOk()->assertDontSee('private-fixture.txt');
    $this->postJson($this->url.'/resume', ['actor_user_id' => $this->worker->id])->assertOk()
        ->assertJsonPath('attachments.0.id', $file->id)->assertJsonPath('attachments.0.name', 'private-fixture.txt');
    $this->get($upload->json('attachment.download_url'))->assertOk();
    $this->actingAs($this->agent)->get('/it/attachments/'.$file->id)->assertNotFound();
});

test('upload retry is idempotent by immutable identity and changed or stale uploads cannot add another file', function () {
    $uuid = (string) Str::uuid();
    $first = itDraftUpload($this, 0, $uuid)->assertOk();
    itDraftUpload($this, 0, $uuid)->assertOk()->assertJsonPath('draft.revision', 2)->assertJsonPath('attachment.id', $first->json('attachment.id'));
    itDraftUpload($this, 0, $uuid, 'Changed file bytes')->assertConflict()->assertJsonPath('code', 'draft_upload_conflict');
    itDraftUpload($this, 0)->assertConflict()->assertJsonPath('code', 'draft_conflict');
    expect(ItAttachment::query()->count())->toBe(1)->and(Storage::disk(ItAttachment::DISK)->allFiles('it_attachments'))->toHaveCount(1);
});

test('removed files retain a tombstone so lost remove acknowledgement and delayed upload never resurrect bytes', function () {
    $uuid = (string) Str::uuid();
    itDraftUpload($this, 0, $uuid)->assertOk();
    $file = ItAttachment::query()->sole();
    foreach ([2, 2] as $revision) {
        $this->deleteJson($this->url.'/attachments/'.$file->id, ['actor_user_id' => $this->worker->id, 'expected_revision' => $revision])
            ->assertOk()->assertJsonPath('draft.revision', 3)->assertJsonPath('draft.files.ready', 0);
    }
    expect($file->fresh()->draft_storage_state)->toBe('removed');
    Storage::disk(ItAttachment::DISK)->assertMissing($file->path);
    itDraftUpload($this, 0, $uuid)->assertConflict()->assertJsonPath('code', 'draft_attachment_removed');
    $this->get('/it/attachments/'.$file->id)->assertNotFound();
    $this->postJson($this->url.'/resume', ['actor_user_id' => $this->worker->id])->assertOk()->assertJsonCount(0, 'attachments');
});

test('failed physical upload stays reserved blocks submit and can be retried without a second record', function () {
    $manager = Storage::getFacadeRoot();
    $broken = Mockery::mock(FilesystemAdapter::class);
    $broken->shouldReceive('putFileAs')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with(ItAttachment::DISK)->andReturn($broken);
    $uuid = (string) Str::uuid();
    itDraftUpload($this, 0, $uuid)->assertStatus(503)->assertJsonPath('code', 'draft_upload_unconfirmed');
    Storage::swap($manager);
    $file = ItAttachment::query()->sole();
    expect($file->draft_storage_state)->toBe('reserved')->and(ItTicketDraft::query()->sole()->revision)->toBe(1);
    $this->getJson($this->url.'?actor_user_id='.$this->worker->id)->assertOk()
        ->assertJsonPath('draft.files.pending', 1)->assertJsonPath('draft.capabilities.submit', false);
    $this->postJson('/it/tickets', itDraftCreateInput($this, 1))->assertConflict()->assertJsonPath('code', 'draft_submission_blocked');
    expect(ItTicketCommandReceipt::query()->count())->toBe(0)->and(ItTicket::query()->count())->toBe(1);
    itDraftUpload($this, 0, $uuid)->assertOk()->assertJsonPath('attachment.id', $file->id)->assertJsonPath('draft.files.ready', 1);
    expect(ItAttachment::query()->count())->toBe(1);
});

test('a failed ready commit preserves its durable file intent and retry confirms the same private path', function () {
    Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $entry): void {
        if ($entry->action === 'it.draft.file_ready') {
            throw new RuntimeException('Synthetic ready-audit failure');
        }
    });
    $uuid = (string) Str::uuid();
    itDraftUpload($this, 0, $uuid)->assertStatus(503);
    $file = ItAttachment::query()->sole();
    expect($file->draft_storage_state)->toBe('reserved');
    Storage::disk(ItAttachment::DISK)->assertExists($file->path);
    Event::forget('eloquent.creating: '.AuditLog::class);
    itDraftUpload($this, 0, $uuid)->assertOk()->assertJsonPath('attachment.id', $file->id);
    expect(Storage::disk(ItAttachment::DISK)->allFiles('it_attachments'))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'it.draft.file_ready')->count())->toBe(1);
});

test('canonical intake transfers the same attachment atomically and a rejected intake leaves it recoverable', function () {
    itDraftUpload($this)->assertOk();
    $file = ItAttachment::query()->sole();
    $otherSite = Site::factory()->create();
    $this->postJson('/it/tickets', [...itDraftCreateInput($this, 2), 'site_id' => $otherSite->id])->assertUnprocessable();
    expect($file->fresh()->attachable_type)->toBe((new ItTicketDraft)->getMorphClass())
        ->and(ItTicketDraft::query()->sole()->state)->toBe('active');
    $created = $this->postJson('/it/tickets', itDraftCreateInput($this, 2))->assertCreated();
    expect($file->fresh()->attachable_type)->toBe((new ItTicket)->getMorphClass())
        ->and((int) $file->fresh()->attachable_id)->toBe($created->json('data.id'))
        ->and($file->fresh()->draft_generation_uuid)->toBeNull()->and(ItTicketDraft::query()->sole()->state)->toBe('consumed');
    $this->postJson('/it/tickets', itDraftCreateInput($this, 2))->assertOk()->assertJsonPath('data.replayed', true);
    expect(ItAttachment::query()->count())->toBe(1)->and(Storage::disk(ItAttachment::DISK)->allFiles('it_attachments'))->toHaveCount(1);
    $this->get('/it/attachments/'.$file->id)->assertOk();
});

test('discard and generation rotation clean only their own staged files and never a canonical ticket attachment', function () {
    itDraftUpload($this)->assertOk();
    $staged = ItAttachment::query()->sole();
    Storage::disk(ItAttachment::DISK)->put('it_attachments/canonical-fixture.txt', 'Keep canonical evidence');
    $canonical = $this->ticket->attachments()->create(['path' => 'it_attachments/canonical-fixture.txt', 'original_name' => 'existing.txt',
        'mime' => 'text/plain', 'size' => 23, 'uploaded_by' => $this->worker->id]);
    $this->deleteJson($this->url, ['actor_user_id' => $this->worker->id, 'expected_revision' => 2])->assertOk()
        ->assertJsonPath('draft.cleanup.failed', 0)->assertJsonPath('draft.files.ready', 0)->assertJsonPath('draft.files.cleanup_pending', 0);
    Storage::disk(ItAttachment::DISK)->assertMissing($staged->path);
    Storage::disk(ItAttachment::DISK)->assertExists($canonical->path);
    $new = $this->postJson($this->url.'/start-new', ['actor_user_id' => $this->worker->id, 'expected_revision' => 3])->assertOk()->json('draft');
    itDraftUpload($this, 2)->assertNotFound();
    $this->get('/it/attachments/'.$staged->id)->assertNotFound();
    $this->postJson('/it/drafts/'.$new['draft_uuid'].'/resume', ['actor_user_id' => $this->worker->id])->assertOk()->assertJsonCount(0, 'attachments');
    expect(ItAttachment::query()->sole()->id)->toBe($canonical->id);
});

test('failed deletion remains tracked concealed and retried without claiming physical cleanup succeeded', function () {
    itDraftUpload($this)->assertOk();
    $file = ItAttachment::query()->sole();
    $manager = Storage::getFacadeRoot();
    $broken = Mockery::mock(FilesystemAdapter::class);
    $broken->shouldReceive('exists')->andReturn(true);
    $broken->shouldReceive('delete')->andReturn(false);
    Storage::shouldReceive('disk')->with(ItAttachment::DISK)->andReturn($broken);
    $this->deleteJson($this->url.'/attachments/'.$file->id, ['actor_user_id' => $this->worker->id, 'expected_revision' => 2])
        ->assertOk()->assertJsonPath('cleanup.failed', 1)->assertJsonPath('draft.files.cleanup_pending', 1);
    Storage::swap($manager);
    expect($file->fresh()->draft_storage_state)->toBe('cleanup_pending')->and($file->fresh()->draft_cleanup_attempts)->toBe(1);
    $this->get('/it/attachments/'.$file->id)->assertNotFound();
    $result = app(ItTicketDraftPruner::class)->run();
    expect($result['files_deleted'])->toBe(1)->and($result['cleanup_failed'])->toBe(0)->and($file->fresh()->draft_storage_state)->toBe('removed');
    Storage::disk(ItAttachment::DISK)->assertMissing($file->path);
});

test('expiry denies exact-boundary reads scrubs text before file cleanup and later removes the terminal slot', function () {
    itDraftUpload($this)->assertOk();
    $this->drafts->save($this->worker, $this->draft['draft_uuid'], 2, ['description' => 'Private expiry text'], 0, null);
    $file = ItAttachment::query()->sole();
    $this->travelTo(ItTicketDraft::query()->sole()->expires_at);
    $this->get('/it/attachments/'.$file->id)->assertNotFound();
    $this->postJson($this->url.'/resume', ['actor_user_id' => $this->worker->id])->assertConflict();
    $result = app(ItTicketDraftPruner::class)->run();
    expect($result['expired'])->toBe(1)->and($result['files_deleted'])->toBe(1)
        ->and(ItTicketDraft::query()->sole()->encrypted_payload)->toBeNull()->and(ItTicketDraft::query()->sole()->state)->toBe('expired')
        ->and(AuditLog::query()->where('action', 'it.draft.expired')->sole()->toJson())->not->toContain('Private expiry text');
    $this->travel(4)->days();
    expect(app(ItTicketDraftPruner::class)->run()['removed_slots'])->toBe(1)->and(ItTicketDraft::query()->count())->toBe(0);
    Storage::disk(ItAttachment::DISK)->assertMissing($file->path);
});

test('file limits and purpose restrictions fail before reserving any private storage', function () {
    foreach ([UploadedFile::fake()->createWithContent('unsafe.html', '<html>Unsafe</html>'), UploadedFile::fake()->create('oversize.pdf', ItAttachment::MAX_SIZE_KB + 1, 'application/pdf')] as $file) {
        $this->postJson($this->url.'/attachments', ['actor_user_id' => $this->worker->id, 'expected_revision' => 0,
            'upload_uuid' => (string) Str::uuid(), 'attachment' => $file])->assertUnprocessable();
    }
    expect(ItAttachment::query()->count())->toBe(0);
    $this->actingAs($this->agent);
    $resolution = $this->drafts->initialize($this->agent, Purpose::PublicResolution, $this->ticket->id, null);
    $this->postJson('/it/drafts/'.$resolution['draft_uuid'].'/attachments', ['actor_user_id' => $this->agent->id, 'expected_revision' => 0,
        'upload_uuid' => (string) Str::uuid(), 'attachment' => UploadedFile::fake()->createWithContent('details.txt', 'No file field in this form')])
        ->assertUnprocessable()->assertJsonPath('code', 'draft_files_unsupported');
});

test('filename and detected content rejection preserves draft text and earlier files and allows a corrected upload', function () {
    itDraftUpload($this)->assertOk();
    $this->drafts->save($this->worker, $this->draft['draft_uuid'], 2, ['description' => 'Keep this draft text'], 0, null);
    $original = ItAttachment::query()->sole();
    $auditCount = AuditLog::query()->count();

    foreach ([
        ['details.exe', 'Plain text with an unapproved filename'],
        ['details', 'Plain text without an extension'],
        ['details.txt', '<!DOCTYPE html><html><body><script>alert("fixture")</script></body></html>'],
    ] as [$name, $bytes]) {
        $backing = UploadedFile::fake()->createWithContent('fixture.bin', $bytes);
        // Real content detection; fake()->create alone can report the filename's MIME.
        $file = new UploadedFile($backing->getPathname(), $name, 'text/plain', null, true);
        $this->postJson($this->url.'/attachments', ['actor_user_id' => $this->worker->id, 'expected_revision' => 3,
            'upload_uuid' => (string) Str::uuid(), 'attachment' => $file])
            ->assertUnprocessable()->assertJsonValidationErrors('attachment');
        expect(ItTicketDraft::query()->sole()->revision)->toBe(3)
            ->and(ItAttachment::query()->count())->toBe(1)
            ->and(AuditLog::query()->count())->toBe($auditCount);
        Storage::disk(ItAttachment::DISK)->assertExists($original->path);
    }
    $this->postJson($this->url.'/resume', ['actor_user_id' => $this->worker->id])->assertOk()
        ->assertJsonPath('payload.fields.description', 'Keep this draft text')->assertJsonCount(1, 'attachments');
    $backing = UploadedFile::fake()->createWithContent('fixture.bin', 'Approved uppercase text file');
    $corrected = new UploadedFile($backing->getPathname(), 'details.TXT', 'application/x-msdownload', null, true);
    $this->postJson($this->url.'/attachments', ['actor_user_id' => $this->worker->id, 'expected_revision' => 3,
        'upload_uuid' => (string) Str::uuid(), 'attachment' => $corrected])->assertOk()
        ->assertJsonPath('attachment.mime', 'text/plain')->assertJsonPath('draft.revision', 5);
    expect(ItAttachment::query()->whereKeyNot($original->id)->sole()->mime)->toBe('text/plain')
        ->and(Storage::disk(ItAttachment::DISK)->allFiles('it_attachments'))->toHaveCount(2);
});

test('direct intake comment and storage reservation reject an unapproved filename before any write', function () {
    // Independent durable reservations from earlier tests survive their outer rollback.
    // Prove this rejection changes no intent, without assuming an empty global table.
    $intentsBefore = ItAttachmentStorageIntent::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    $backing = UploadedFile::fake()->createWithContent('fixture.bin', 'Plain text executable-name fixture');
    $file = new UploadedFile($backing->getPathname(), 'details.exe', 'text/plain', null, true);
    $this->postJson('/it/tickets', ['request_uuid' => $this->requestUuid, 'actor_user_id' => $this->worker->id,
        'title' => 'Rejected attachment', 'category' => 'hardware', 'priority' => 'normal', 'site_id' => $this->site->id,
        'attachments' => [$file]])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
    $this->postJson('/it/tickets/'.$this->ticket->id.'/comments', ['body' => 'Keep this unsent reply', 'attachments' => [$file]])
        ->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
    expect(fn () => app(ItAttachmentStorageIntentService::class)->reserveDirect($this->ticket, [$file], $this->worker))
        ->toThrow(ValidationException::class);
    expect(ItAttachment::query()->count())->toBe(0)
        ->and(ItAttachmentStorageIntent::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($intentsBefore)
        ->and(ItTicketCommandReceipt::query()->count())->toBe(0)->and($this->ticket->comments()->count())->toBe(0)
        ->and(ItTicket::query()->count())->toBe(1)->and(Storage::disk(ItAttachment::DISK)->allFiles('it_attachments'))->toBe([]);
});

test('internal file downloads recheck current sensitive access and disabled cleanup does not mutate records', function () {
    $this->actingAs($this->agent);
    $internal = $this->drafts->initialize($this->agent, Purpose::InternalNote, $this->ticket->id, null);
    $this->postJson('/it/drafts/'.$internal['draft_uuid'].'/attachments', ['actor_user_id' => $this->agent->id, 'expected_revision' => 0,
        'upload_uuid' => (string) Str::uuid(), 'attachment' => UploadedFile::fake()->createWithContent('internal.txt', 'Private internal file')])->assertOk();
    $file = ItAttachment::query()->sole();
    $this->ticket->forceFill(['is_sensitive' => true, 'requester_user_id' => $this->agent->id])->save();
    $this->agent->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', 'it.viewSensitive')->sole()->id => ['allowed' => false]]);
    $this->get('/it/attachments/'.$file->id)->assertNotFound();
    config(['it.drafts.enabled' => false]);
    $result = app(ItTicketDraftPruner::class)->run();
    expect($result['enabled'])->toBeFalse()->and(ItAttachment::query()->count())->toBe(1);
    Storage::disk(ItAttachment::DISK)->assertExists($file->path);
});

test('staged and direct files share the canonical five-file submission limit', function () {
    $intentsBefore = ItAttachmentStorageIntent::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    foreach (range(0, 3) as $number) {
        itDraftUpload($this, $number * 2)->assertOk();
    }
    $input = [...itDraftCreateInput($this, 8), 'attachments' => [
        UploadedFile::fake()->createWithContent('extra-a.txt', 'Extra A'),
        UploadedFile::fake()->createWithContent('extra-b.txt', 'Extra B'),
    ]];
    $this->postJson('/it/tickets', $input)->assertUnprocessable()->assertJsonValidationErrors('attachments');
    expect(ItTicketCommandReceipt::query()->count())->toBe(0)
        ->and(ItAttachmentStorageIntent::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($intentsBefore)
        ->and(ItTicketDraft::query()->sole()->state)->toBe('active')->and(ItAttachment::query()->count())->toBe(4)
        ->and(Storage::disk(ItAttachment::DISK)->allFiles('it_attachments'))->toHaveCount(4);
    itDraftUpload($this, 8)->assertOk();
    itDraftUpload($this, 10)->assertUnprocessable()->assertJsonValidationErrors('attachment');
    expect(ItAttachment::query()->count())->toBe(5);
});

test('expiry still scrubs private text when physical deletion fails and a later run cleans the retained intent', function () {
    itDraftUpload($this)->assertOk();
    $this->drafts->save($this->worker, $this->draft['draft_uuid'], 2, ['description' => 'Scrub even when storage fails'], 0, null);
    $file = ItAttachment::query()->sole();
    $this->travel(3)->days();
    $manager = Storage::getFacadeRoot();
    $broken = Mockery::mock(FilesystemAdapter::class);
    $broken->shouldReceive('exists')->andReturn(true);
    $broken->shouldReceive('delete')->andReturn(false);
    Storage::shouldReceive('disk')->with(ItAttachment::DISK)->andReturn($broken);
    $failed = app(ItTicketDraftPruner::class)->run();
    Storage::swap($manager);
    expect($failed['expired'])->toBe(1)->and($failed['cleanup_failed'])->toBe(1)
        ->and(ItTicketDraft::query()->sole()->encrypted_payload)->toBeNull()
        ->and($file->fresh()->draft_storage_state)->toBe('cleanup_pending');
    $retry = app(ItTicketDraftPruner::class)->run();
    expect($retry['cleanup_failed'])->toBe(0)->and($retry['files_deleted'])->toBe(1)->and(ItAttachment::query()->count())->toBe(0);
    Storage::disk(ItAttachment::DISK)->assertMissing($file->path);
});
