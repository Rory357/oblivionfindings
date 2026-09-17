<?php

use App\Domain\It\Services\ItCatalogAttachmentService;
use App\Domain\It\Services\ItCatalogManagementService;
use App\Domain\It\Services\ItCatalogSubmissionService;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Bus::fake([DispatchItTicketNotifications::class]);
    Storage::fake(ItAttachment::DISK);
    $this->site = Site::factory()->create();
    foreach (['agent' => 'hr', 'worker' => 'support_worker', 'stranger' => 'support_worker'] as $name => $role) {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::where('name', $role)->firstOrFail()->id]);
        ensureCanonicalHrStaffProfile($user, $this->site);
        $this->{$name} = $user;
    }
    $this->fields = ['fields' => [
        ['key' => 'evidence', 'label' => 'Supporting files', 'type' => 'attachment', 'required' => true, 'visibility' => 'requester', 'max' => 3],
        ['key' => 'private_evidence', 'label' => 'IT evidence', 'type' => 'attachment', 'required' => false, 'visibility' => 'internal', 'max' => 3],
    ]];
});

test('catalogue attachment authoring and publication prevent impossible required file totals', function () {
    $fields = $this->fields;
    foreach ($fields['fields'] as &$field) {
        $field['required'] = true;
        $field['min'] = 3;
    }
    unset($field);
    $payload = ['actor_user_id' => $this->agent->id, 'name' => 'Synthetic supporting evidence',
        'description' => 'Harmless attachment authoring fixture.', 'outcome_type' => 'service_request',
        'category' => 'other', 'default_priority' => 'normal', 'requires_approval' => false,
        'internal_only' => false, 'search_terms' => [], 'sort_order' => 0, 'form_schema' => $fields];
    $this->actingAs($this->agent)->postJson('/it/setup/catalogue-items', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('form_schema.fields');
    expect(ItCatalogItem::query()->count())->toBe(0);

    $payload['form_schema']['fields'][1]['required'] = false;
    $this->post('/it/setup/catalogue-items', $payload)->assertRedirect()->assertSessionDoesntHaveErrors();
    $item = ItCatalogItem::query()->sole();
    expect($item->form_schema['fields'][0]['type'])->toBe('attachment')
        ->and($item->form_schema['fields'][0]['min'])->toBe(3)
        ->and($item->form_schema['fields'][1]['visibility'])->toBe('internal');
    $published = app(ItCatalogManagementService::class)->publish($item, $this->agent, $item->lock_version);
    expect($published->publishedContract()->form_schema)->toEqual($payload['form_schema']);

    // An older draft created outside this HTTP validation still cannot publish
    // an impossible request; publication does not merely trust saved JSON.
    $legacyDraft = ItCatalogItem::factory()->create(['is_published' => false, 'form_schema' => $fields]);
    expect(fn () => app(ItCatalogManagementService::class)->publish($legacyDraft, $this->agent, $legacyDraft->lock_version))
        ->toThrow(ValidationException::class);
    expect($legacyDraft->fresh()->is_published)->toBeFalse();
});

test('catalogue files use canonical private storage with original field audience and current result access', function (string $outcome) {
    $item = ItCatalogItem::factory()->create(['outcome_type' => $outcome, 'provisioning_type' => $outcome === 'provisioning' ? 'equipment' : null,
        'form_schema' => $this->fields]);
    $input = ['actor_user_id' => $this->agent->id, 'schema_version' => 1, 'site_id' => $this->site->id,
        'requested_for_user_id' => $this->worker->id, 'idempotency_key' => (string) Str::uuid(),
        'values' => ['evidence' => [UploadedFile::fake()->createWithContent('public.txt', 'Public fixture bytes')],
            'private_evidence' => [UploadedFile::fake()->createWithContent('private.txt', 'Private fixture bytes')]]];
    $url = "/it/catalog/{$item->id}/submissions";
    $this->actingAs($this->agent)->post($url, $input, ['Accept' => 'application/json'])->assertCreated();
    $submission = ItCatalogSubmission::query()->sole();
    expect($submission->submitted_values)->toBe(['evidence' => ['public.txt'], 'private_evidence' => ['private.txt']]);
    $public = $submission->attachments()->where('catalogue_field_key', 'evidence')->sole();
    $private = $submission->attachments()->where('catalogue_field_key', 'private_evidence')->sole();
    expect($public->attachable_type)->toBe('it_catalog_submission')
        ->and($public->uploaded_by)->toBe($this->agent->id)
        ->and(AuditLog::where('action', 'it.catalogue.attachments.recorded')->count())->toBe(1);
    Storage::disk(ItAttachment::DISK)->assertExists([$public->path, $private->path]);
    $this->get("/it/attachments/{$public->id}")->assertOk();
    $this->get("/it/attachments/{$private->id}")->assertOk();
    $this->actingAs($this->worker)->get("/it/attachments/{$public->id}")->assertOk();
    $this->get("/it/attachments/{$private->id}")->assertNotFound();
    $this->actingAs($this->stranger)->get("/it/attachments/{$public->id}")->assertNotFound();
    $result = $submission->result;
    $files = app(ItCatalogAttachmentService::class);
    expect(array_column($files->forResult($this->agent, $result), 'name'))->toBe(['public.txt', 'private.txt'])
        ->and(array_column($files->forResult($this->worker, $result), 'name'))->toBe(['public.txt'])
        ->and($files->forResult($this->stranger, $result))->toBe([]);
    if ($outcome === 'service_request') {
        $this->actingAs($this->worker)->get("/it/tickets/{$result->id}")
            ->assertInertia(fn ($page) => $page->has('ticket.attachments', 1)->where('ticket.attachments.0.name', 'public.txt'));
    } else {
        $this->actingAs($this->agent)->get('/it/provisioning')
            ->assertInertia(fn ($page) => $page->has('records.data', 1)
                ->where('records.data.0.id', $result->id));
        $this->actingAs($this->agent)->get("/it/provisioning/tasks/{$result->id}")
            ->assertInertia(fn ($page) => $page->where('task.attachments', [
                ['id' => $public->id, 'name' => 'public.txt', 'size' => $public->size,
                    'url' => '/it/attachments/'.$public->id, 'catalogue_field_label' => 'Supporting files', 'is_internal' => false],
                ['id' => $private->id, 'name' => 'private.txt', 'size' => $private->size,
                    'url' => '/it/attachments/'.$private->id, 'catalogue_field_label' => 'IT evidence', 'is_internal' => true],
            ]));
        $this->actingAs($this->worker)->get("/it/provisioning/{$result->id}")
            ->assertInertia(fn ($page) => $page->has('request.attachments', 1)->where('request.attachments.0.name', 'public.txt'));
    }

    // Withdrawal does not rewrite the retained evidence audience.
    $item->update(['is_published' => false]);
    $this->actingAs($this->agent)->post($url, $input, ['Accept' => 'application/json'])->assertOk();
    expect(ItCatalogSubmission::query()->count())->toBe(1)->and(ItAttachment::query()->count())->toBe(2);
    $this->actingAs($this->worker)->get("/it/attachments/{$public->id}")->assertOk();
    $this->get("/it/attachments/{$private->id}")->assertNotFound();
    $public->update(['catalogue_field_key' => 'nonexistent']);
    $this->actingAs($this->agent)->get("/it/attachments/{$public->id}")->assertNotFound();
    if ($outcome === 'provisioning') {
        ensureCanonicalHrStaffProfile($this->agent, Site::factory()->create());
        $this->actingAs($this->agent->fresh())->get('/it/provisioning')
            ->assertInertia(fn ($page) => $page->has('records.data', 0));
        expect($files->forResult($this->agent->fresh(), $result))->toBe([]);
        $this->get("/it/attachments/{$private->id}")->assertNotFound();
    }
    $this->agent->update(['approved_at' => null]);
    expect($files->canDownload($this->agent->fresh(), $private))->toBeFalse();
    $this->actingAs($this->agent->fresh())->get("/it/attachments/{$private->id}")->assertRedirect(route('login'));
    $this->assertGuest();
})->with(['service_request', 'provisioning']);

test('catalogue retry binds attachment bytes and filename without creating another result', function () {
    $item = ItCatalogItem::factory()->create(['form_schema' => $this->fields]);
    $input = ['actor_user_id' => $this->worker->id, 'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(), 'values' => ['evidence' => [UploadedFile::fake()->createWithContent('proof.txt', 'same bytes')]]];
    $url = "/it/catalog/{$item->id}/submissions";
    $this->actingAs($this->worker)->post($url, $input, ['Accept' => 'application/json'])->assertCreated();
    $input['values']['evidence'] = [UploadedFile::fake()->createWithContent('proof.txt', 'same bytes')];
    $this->post($url, $input, ['Accept' => 'application/json'])->assertOk();
    foreach ([['proof.txt', 'new bytes!'], ['renamed.txt', 'same bytes']] as [$name, $bytes]) {
        $input['values']['evidence'] = [UploadedFile::fake()->createWithContent($name, $bytes)];
        $this->post($url, $input, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }
    expect(ItTicket::query()->count())->toBe(1)->and(ItAttachment::query()->count())->toBe(1)
        ->and(ItEmailDelivery::query()->count())->toBe(1);
});

test('catalogue file validation rejects missing spoofed excessive and private requester inputs before writing', function () {
    $item = ItCatalogItem::factory()->create(['form_schema' => $this->fields]);
    $url = "/it/catalog/{$item->id}/submissions";
    $input = ['actor_user_id' => $this->worker->id, 'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(), 'values' => []];
    $this->actingAs($this->worker)->post($url, $input, ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('values.evidence');
    $input['values'] = ['evidence' => [UploadedFile::fake()->createWithContent('evil.svg', '<svg></svg>')]];
    $this->post($url, $input, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('values.evidence.0');
    $input['values'] = ['evidence' => ['invented-file-id']];
    $this->post($url, $input, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('values.evidence.0');
    $input['values'] = ['evidence' => array_map(fn ($i) => UploadedFile::fake()->createWithContent("{$i}.txt", 'proof'), range(1, 4))];
    $this->post($url, $input, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('values.evidence');
    $input['values'] = ['evidence' => [UploadedFile::fake()->create('large.txt', ItAttachment::MAX_SIZE_KB + 1, 'text/plain')]];
    $this->post($url, $input, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('values.evidence.0');
    $input['values'] = ['evidence' => [UploadedFile::fake()->createWithContent('proof.txt', 'proof')],
        'private_evidence' => [UploadedFile::fake()->createWithContent('private.txt', 'private')]];
    $this->post($url, $input, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('values.private_evidence');
    expect(ItCatalogSubmission::query()->count())->toBe(0)->and(ItAttachment::query()->count())->toBe(0)
        ->and(ItTicket::query()->count())->toBe(0);
});

test('catalogue enforces a combined file budget before reserving storage', function () {
    $item = ItCatalogItem::factory()->create(['form_schema' => $this->fields]);
    $files = fn () => array_map(fn ($index) => UploadedFile::fake()->createWithContent("{$index}.txt", 'proof'), range(1, 3));
    $before = ItAttachmentStorageIntent::query()->count();
    $this->actingAs($this->agent)->post("/it/catalog/{$item->id}/submissions", [
        'actor_user_id' => $this->agent->id, 'schema_version' => 1, 'site_id' => $this->site->id,
        'idempotency_key' => (string) Str::uuid(), 'values' => ['evidence' => $files(), 'private_evidence' => $files()],
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('values');
    expect(ItCatalogSubmission::query()->count())->toBe(0)->and(ItAttachmentStorageIntent::query()->count())->toBe($before);
});

test('catalogue file projection and download recheck sensitive work and current Site access', function () {
    $item = ItCatalogItem::factory()->create(['form_schema' => $this->fields]);
    $saved = app(ItCatalogSubmissionService::class)->submit($item, $this->agent, [
        'schema_version' => 1, 'site_id' => $this->site->id, 'idempotency_key' => (string) Str::uuid(),
        'values' => ['evidence' => [UploadedFile::fake()->createWithContent('public.txt', 'proof')],
            'private_evidence' => [UploadedFile::fake()->createWithContent('private.txt', 'private')]],
    ]);
    $ticket = $saved['result'];
    $private = $saved['submission']->attachments()->where('catalogue_field_key', 'private_evidence')->sole();
    expect($this->agent->canDo('it.viewSensitive'))->toBeFalse();
    $ticket->update(['is_sensitive' => true]);
    $this->actingAs($this->agent)->get("/it/attachments/{$private->id}")->assertNotFound();
    expect(array_column(app(ItCatalogAttachmentService::class)->forResult($this->agent, $ticket), 'name'))->toBe(['public.txt']);
    $ticket->update(['is_sensitive' => false, 'requester_user_id' => $this->worker->id, 'requested_for_user_id' => $this->worker->id]);
    ensureCanonicalHrStaffProfile($this->agent, Site::factory()->create());
    $this->actingAs($this->agent->fresh())->get("/it/attachments/{$private->id}")->assertNotFound();
    expect(app(ItCatalogAttachmentService::class)->forResult($this->agent->fresh(), $ticket))->toBe([]);
});

test('catalogue storage failure rolls back work and preserves an exact retry', function () {
    $item = ItCatalogItem::factory()->create(['form_schema' => $this->fields]);
    $input = ['schema_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        'values' => ['evidence' => [UploadedFile::fake()->createWithContent('proof.txt', 'proof')]]];
    $manager = Storage::getFacadeRoot();
    $disk = Storage::disk(ItAttachment::DISK);
    $broken = Mockery::mock($disk);
    $broken->shouldReceive('putFileAs')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with(ItAttachment::DISK)->andReturn($broken);
    try {
        expect(fn () => app(ItCatalogSubmissionService::class)->submit($item, $this->worker, $input))
            ->toThrow(DomainException::class, 'The attachment could not be stored.');
        expect(ItCatalogSubmission::query()->count())->toBe(0)->and(ItTicket::query()->count())->toBe(0)
            ->and(ItAttachment::query()->count())->toBe(0)->and(ItEmailDelivery::query()->count())->toBe(0);
    } finally {
        Storage::swap($manager);
    }
    expect(app(ItCatalogSubmissionService::class)->submit($item, $this->worker, $input)['created'])->toBeTrue();
    expect(ItCatalogSubmission::query()->count())->toBe(1)->and(ItAttachment::query()->count())->toBe(1);
});

test('catalogue evidence audit failure rolls back the result inside a surrounding transaction', function () {
    $item = ItCatalogItem::factory()->create(['form_schema' => $this->fields]);
    $input = ['schema_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        'values' => ['evidence' => [UploadedFile::fake()->createWithContent('proof.txt', 'proof')]]];
    $firstIntent = (int) ItAttachmentStorageIntent::query()->max('id');
    $reject = true;
    AuditLog::creating(function (AuditLog $audit) use (&$reject): void {
        if ($reject && $audit->action === 'it.catalogue.attachments.recorded') {
            throw new RuntimeException('Synthetic catalogue file audit failure');
        }
    });
    try {
        expect(fn () => app(ItCatalogSubmissionService::class)->submit($item, $this->worker, $input))
            ->toThrow(RuntimeException::class, 'Synthetic catalogue file audit failure');
        expect(ItTicket::query()->count())->toBe(0)->and(ItAttachment::query()->count())->toBe(0)
            ->and(ItCatalogSubmission::query()->count())->toBe(0)->and(ItEmailDelivery::query()->count())->toBe(0);
        // RefreshDatabase still owns an outer transaction. InnoDB retains
        // savepoint locks; real outer rollback/retry belongs to the standalone
        // ItCatalogAttachmentTransactionTest, which does not commit test locks.
        // A locking read sees the independent reservation committed after the
        // outer test transaction's repeatable-read snapshot was established.
        expect(ItAttachmentStorageIntent::query()->where('id', '>', $firstIntent)
            ->where('state', 'reserved')->lockForUpdate()->get())->toHaveCount(1);
    } finally {
        $reject = false;
    }
});
