<?php

use App\Domain\It\Enums\ItTicketDraftPurpose as Purpose;
use App\Domain\It\Exceptions\ItTicketDraftException;
use App\Domain\It\Services\ItCatalogSubmissionService;
use App\Domain\It\Services\ItTicketDraftAttachmentService;
use App\Domain\It\Services\ItTicketDraftService;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\ItAttachment;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItTicketDraft;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Bus::fake([DispatchItTicketNotifications::class]);
    Storage::fake(ItAttachment::DISK);
    config(['it.drafts.enabled' => true, 'it.drafts.retention_days' => 2, 'it.drafts.terminal_retention_days' => 3]);
    $this->site = Site::factory()->create();
    foreach (['worker' => 'support_worker', 'agent' => 'hr', 'other' => 'support_worker'] as $name => $role) {
        $actor = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $actor->roles()->syncWithoutDetaching(Role::where('name', $role)->pluck('id'));
        ensureCanonicalHrStaffProfile($actor, $this->site);
        $this->{$name} = $actor;
    }
    $this->item = ItCatalogItem::factory()->create(['site_scope' => [$this->site->id], 'form_schema' => ['fields' => [
        ['key' => 'details', 'label' => 'Details', 'type' => 'textarea', 'required' => true, 'visibility' => 'requester'],
        ['key' => 'evidence', 'label' => 'Supporting file', 'type' => 'attachment', 'required' => true, 'max' => 1, 'visibility' => 'requester'],
        ['key' => 'internal_file', 'label' => 'Internal file', 'type' => 'attachment', 'required' => false, 'max' => 1, 'visibility' => 'internal'],
    ]]]);
    $this->uuid = (string) Str::uuid();
    $this->drafts = app(ItTicketDraftService::class);
    $this->files = app(ItTicketDraftAttachmentService::class);
    $this->submission = app(ItCatalogSubmissionService::class);
    $this->fields = ['catalog_item_id' => $this->item->id, 'schema_version' => 1,
        'catalogue_values' => json_encode(['details' => 'Original private request details', 'evidence' => []]),
        'site_id' => $this->site->id];
});

function catalogueDraftStart($test, ?User $actor = null): array
{
    return $test->drafts->initialize($actor ?? $test->worker, Purpose::CatalogueRequest, null, $test->uuid,
        ['catalog_item_id' => $test->item->id, 'schema_version' => 1]);
}

test('catalogue draft metadata stays private and is bound to actor item and original schema', function () {
    $draft = catalogueDraftStart($this);
    expect($draft['context_key'])->toBe('catalogue:'.$this->item->id.':version:1:request:'.$this->uuid);
    $saved = $this->drafts->save($this->worker, $draft['draft_uuid'], 0, $this->fields, 1, null);
    expect(json_encode($saved))->not->toContain('Original private request details');
    $raw = DB::table('it_ticket_drafts')->where('draft_uuid', $draft['draft_uuid'])->first();
    expect($raw->encrypted_payload)->not->toContain('Original private request details')
        ->and($raw->bound_scope)->not->toContain('Original private request details');
    $this->actingAs($this->other)->postJson('/it/drafts/'.$draft['draft_uuid'].'/resume', ['actor_user_id' => $this->other->id])->assertNotFound();
    $this->actingAs($this->worker)->postJson('/it/drafts/'.$draft['draft_uuid'].'/resume', ['actor_user_id' => $this->worker->id])
        ->assertOk()->assertJsonPath('payload.fields.catalogue_values', $this->fields['catalogue_values']);
    $other = ItCatalogItem::factory()->create();
    $this->actingAs($this->worker)->patchJson('/it/drafts/'.$draft['draft_uuid'], ['actor_user_id' => $this->worker->id,
        'expected_revision' => $saved['revision'], 'step_index' => 0, 'fields' => [...$this->fields, 'catalog_item_id' => $other->id]])->assertNotFound();
});

test('catalogue commit transfers the original staged attachment once with immutable field binding', function () {
    $draft = catalogueDraftStart($this);
    $saved = $this->drafts->save($this->worker, $draft['draft_uuid'], 0, $this->fields, 1, null);
    $upload = $this->files->upload($this->worker, $draft['draft_uuid'], $saved['revision'], (string) Str::uuid(),
        UploadedFile::fake()->createWithContent('evidence.txt', 'Synthetic proof only'), 'evidence');
    $file = ItAttachment::findOrFail($upload['attachment']['id']);
    $path = $file->path;
    $input = ['idempotency_key' => $this->uuid, 'schema_version' => 1, 'site_id' => $this->site->id,
        'values' => ['details' => 'Original private request details', 'evidence' => []],
        'draft_uuid' => $draft['draft_uuid'], 'draft_actor_user_id' => $this->worker->id,
        'draft_revision' => $upload['draft']['revision'], 'staged_attachment_ids' => [$file->id]];
    $first = $this->submission->submit($this->item, $this->worker, $input);
    $again = $this->submission->submit($this->item, $this->worker, $input);
    expect($again['created'])->toBeFalse()->and($again['submission']->id)->toBe($first['submission']->id)
        ->and(ItCatalogSubmission::count())->toBe(1)->and(ItAttachment::count())->toBe(1)
        ->and($file->fresh()->attachable_type)->toBe($first['submission']->getMorphClass())
        ->and($file->fresh()->attachable_id)->toBe($first['submission']->id)
        ->and($file->fresh()->catalogue_field_key)->toBe('evidence')
        ->and($file->fresh()->path)->toBe($path)
        ->and($file->fresh()->draft_generation_uuid)->toBeNull()
        ->and(ItTicketDraft::where('draft_uuid', $draft['draft_uuid'])->sole()->state)->toBe('consumed');
    Storage::disk(ItAttachment::DISK)->assertExists($path);
});

test('staged upload identity cannot move between catalogue fields and requester cannot stage an internal file', function () {
    $draft = catalogueDraftStart($this, $this->agent);
    $uuid = (string) Str::uuid();
    $file = UploadedFile::fake()->createWithContent('proof.txt', 'Synthetic proof');
    $uploaded = $this->files->upload($this->agent, $draft['draft_uuid'], 0, $uuid, $file, 'evidence');
    try {
        $this->files->upload($this->agent, $draft['draft_uuid'], $uploaded['draft']['revision'], $uuid, $file, 'internal_file');
        $this->fail('The original upload field must be immutable.');
    } catch (ItTicketDraftException $error) {
        expect($error->getMessage())->toContain('different file content');
    }
    $other = catalogueDraftStart($this);
    $this->actingAs($this->worker)->post('/it/drafts/'.$other['draft_uuid'].'/attachments', [
        'actor_user_id' => $this->worker->id, 'expected_revision' => 0, 'upload_uuid' => (string) Str::uuid(),
        'catalogue_field_key' => 'internal_file', 'attachment' => $file,
    ])->assertNotFound();
});

test('a cancelled catalogue identity cannot consume its saved draft or create a late request', function () {
    $draft = catalogueDraftStart($this);
    $saved = $this->drafts->save($this->worker, $draft['draft_uuid'], 0, $this->fields, 0, null);
    $cancelled = $this->submission->cancel($this->item->id, $this->worker, $this->uuid, $this->worker->id);
    expect($cancelled['cancelled'])->toBeTrue();
    $late = $this->submission->submit($this->item, $this->worker, ['schema_version' => 1,
        'idempotency_key' => $this->uuid, 'values' => ['details' => 'Original private request details'],
        'site_id' => $this->site->id, 'draft_uuid' => $draft['draft_uuid'], 'draft_revision' => $saved['revision'],
        'draft_actor_user_id' => $this->worker->id, 'staged_attachment_ids' => []]);
    expect($late['cancelled'])->toBeTrue()->and(ItCatalogSubmission::count())->toBe(0)
        ->and($this->drafts->inspect($this->worker, $draft['draft_uuid'])['capabilities']['submit'])->toBeFalse();
});
