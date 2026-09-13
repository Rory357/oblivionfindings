<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItCatalogFieldOptionService;
use App\Domain\It\Services\ItCatalogSubmissionService;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItProvisioningAccessService;
use App\Domain\It\Services\ItProvisioningRequestLifecycleService;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AuditLog;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItCatalogVersion;
use App\Models\ItEmailDelivery;
use App\Models\ItProvisioningRequest;
use App\Models\ItService;
use App\Models\ItSetupCommandReceipt;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketCreatedNotification;
use Database\Seeders\ItServiceCatalogSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function catalogUser(string $role): User
{
    $user = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

function catalogSchema(): array
{
    return [
        'fields' => [
            [
                'key' => 'details',
                'label' => 'What do you need?',
                'type' => 'textarea',
                'required' => true,
                'max' => 2000,
            ],
            [
                'key' => 'system_name',
                'label' => 'System',
                'type' => 'select',
                'required' => true,
                'options' => ['Microsoft 365', 'VPN'],
            ],
            [
                'key' => 'fulfilment_note',
                'label' => 'Fulfilment note',
                'type' => 'text',
                'visibility' => 'internal',
            ],
        ],
    ];
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->worker = catalogUser('support_worker');
    $this->agent = catalogUser('hr');
    $this->workerProfile = HrEmployeeProfile::factory()->create([
        'user_id' => $this->worker->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'created_by' => $this->agent->id,
        'updated_by' => $this->agent->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->agent->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'created_by' => $this->agent->id,
        'updated_by' => $this->agent->id,
    ]);
});

test('catalogue intake atomically preserves publication audit and durable delivery with one recovery drain', function () {
    Bus::fake([DispatchItTicketNotifications::class]);
    $service = ItService::factory()->create(['is_active' => true]);
    $item = ItCatalogItem::factory()->create(['outcome_type' => 'service_request', 'category' => 'hardware',
        'requires_approval' => true, 'it_service_id' => $service->id, 'form_schema' => ['fields' => []]]);
    $input = ['actor_user_id' => $this->worker->id, 'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(), 'values' => []];
    $url = "/it/catalog/{$item->id}/submissions";
    $this->actingAs($this->worker)->postJson($url, $input)->assertCreated();
    $ticket = ItTicket::query()->sole();
    $submission = ItCatalogSubmission::query()->sole();
    $delivery = ItEmailDelivery::query()->sole();
    expect($ticket->work_type)->toBe('service_request')->and($ticket->requires_approval)->toBeTrue()
        ->and((int) $ticket->it_service_id)->toBe((int) $service->id)
        ->and($ticket->next_response_party)->toBe('it')
        ->and($ticket->events()->where('type', 'created')->count())->toBe(1)
        ->and((int) $ticket->events()->where('type', 'created')->sole()->payload['catalog_version_id'])->toBe((int) $submission->catalog_version_id)
        ->and(AuditLog::where('action', 'it.ticket.created')->count())->toBe(1)
        ->and($delivery->dispatch_requested_at)->not->toBeNull()
        ->and($delivery->dispatch_finished_at)->toBeNull()
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    $this->postJson($url, $input)->assertOk()->assertJsonPath('data.id', $ticket->id);
    expect(ItEmailDelivery::query()->count())->toBe(1);
    $outbox = app(ItEmailDeliveryService::class);
    expect($outbox->dispatchPending())->toBe(1)->and($outbox->dispatchPending())->toBe(0)
        ->and($delivery->fresh()->attempt_count)->toBe(1)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});

test('catalogue submission storage failure rolls back canonical ticket audit and notification intent', function () {
    Bus::fake([DispatchItTicketNotifications::class]);
    $item = ItCatalogItem::factory()->create(['form_schema' => ['fields' => []]]);
    $input = ['schema_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'values' => []];
    $dispatcher = ItCatalogSubmission::getEventDispatcher();
    ItCatalogSubmission::setEventDispatcher(clone $dispatcher);
    $reject = true;
    ItCatalogSubmission::creating(function () use (&$reject) {
        if ($reject) {
            throw new RuntimeException('Synthetic final submission write failure');
        }
    });
    try {
        expect(fn () => app(ItCatalogSubmissionService::class)->submit($item, $this->worker, $input))
            ->toThrow(RuntimeException::class, 'Synthetic final submission write failure');
        expect(ItTicket::query()->count())->toBe(0)->and(ItCatalogSubmission::query()->count())->toBe(0)
            ->and(ItEmailDelivery::query()->count())->toBe(0)
            ->and(AuditLog::where('action', 'it.ticket.created')->count())->toBe(0);
        // Keep all model boot hooks registered during the first attempt for
        // the retry. Only the deliberate failure is disabled.
        $reject = false;
        $saved = app(ItCatalogSubmissionService::class)->submit($item, $this->worker, $input);
        expect($saved['created'])->toBeTrue()->and(ItTicket::query()->count())->toBe(1)
            ->and(ItEmailDelivery::query()->count())->toBe(1);
    } finally {
        ItCatalogSubmission::setEventDispatcher($dispatcher);
    }
});

test('catalogue technician answers remain usable internal notes and never enter beneficiary projections or notifications', function () {
    Bus::fake([DispatchItTicketNotifications::class]);
    $item = ItCatalogItem::factory()->create(['form_schema' => catalogSchema()]);
    $private = 'SYNTHETIC-TECHNICIAN-ONLY-CATALOGUE-ANSWER';
    $input = ['actor_user_id' => $this->agent->id, 'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(), 'requested_for_user_id' => $this->worker->id,
        'values' => ['details' => 'Public requested access', 'system_name' => 'VPN', 'fulfilment_note' => $private]];
    $url = "/it/catalog/{$item->id}/submissions";
    $this->actingAs($this->agent)->postJson($url, $input)->assertCreated()->assertDontSee($private, false);
    $ticket = ItTicket::query()->sole();
    $note = $ticket->comments()->sole();
    expect($ticket->description)->toContain('Public requested access')->not->toContain($private)
        ->and($note->body)->toContain($private)->and($note->is_internal)->toBeTrue()
        ->and($ticket->next_response_party)->toBe('it')
        ->and($ticket->first_responded_at)->toBeNull()
        ->and(ItCatalogSubmission::query()->sole()->submitted_values['fulfilment_note'])->toBe($private)
        ->and(AuditLog::where('action', 'it.ticket.comment.added')->count())->toBe(1);
    $this->postJson($url, $input)->assertOk()->assertJsonPath('data.id', $ticket->id);
    expect($ticket->comments()->count())->toBe(1)->and(ItEmailDelivery::query()->count())->toBe(1);
    $this->get("/it/tickets/{$ticket->id}")->assertOk()->assertSee($private, false);
    $this->actingAs($this->worker)->get("/it/tickets/{$ticket->id}")->assertOk()->assertDontSee($private, false);
    $this->get('/it')->assertOk()->assertDontSee($private, false);
    app(ItEmailDeliveryService::class)->dispatchPending();
    expect(json_encode($this->agent->notifications()->get()->toArray(), JSON_THROW_ON_ERROR))->not->toContain($private);
    foreach (Mail::mailer('array')->getSymfonyTransport()->messages() as $message) {
        expect($message->getOriginalMessage()->toString())->not->toContain($private);
    }
});

test('failure to persist a catalogue internal note rolls back the submission ticket and notification intent', function () {
    Bus::fake([DispatchItTicketNotifications::class]);
    $item = ItCatalogItem::factory()->create(['form_schema' => catalogSchema()]);
    $input = ['schema_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        'requested_for_user_id' => $this->worker->id,
        'values' => ['details' => 'Public requested access', 'system_name' => 'VPN', 'fulfilment_note' => 'Private instructions']];
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    $reject = true;
    AuditLog::creating(function (AuditLog $audit) use (&$reject) {
        if ($reject && $audit->action === 'it.ticket.comment.added') {
            throw new RuntimeException('Synthetic catalogue note audit failure');
        }
    });
    try {
        expect(fn () => app(ItCatalogSubmissionService::class)->submit($item, $this->agent, $input))
            ->toThrow(RuntimeException::class, 'Synthetic catalogue note audit failure');
        expect(ItTicket::query()->count())->toBe(0)->and(ItCatalogSubmission::query()->count())->toBe(0)
            ->and(ItEmailDelivery::query()->count())->toBe(0)
            ->and(ItTicketComment::query()->count())->toBe(0);
        $reject = false;
        expect(app(ItCatalogSubmissionService::class)->submit($item, $this->agent, $input)['created'])->toBeTrue()
            ->and(ItTicket::query()->count())->toBe(1)->and(ItTicketComment::query()->count())->toBe(1);
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
});

test('the provisioning beneficiary can track public answers without gaining IT notes or unrelated HR work', function () {
    $item = ItCatalogItem::factory()->create(['outcome_type' => 'provisioning', 'provisioning_type' => 'equipment', 'form_schema' => catalogSchema()]);
    $input = ['schema_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        'requested_for_user_id' => $this->worker->id,
        'values' => ['details' => 'A public equipment request', 'system_name' => 'VPN', 'fulfilment_note' => 'SYNTHETIC-PRIVATE-PROVISIONING-INSTRUCTION']];
    $result = app(ItCatalogSubmissionService::class)->submit($item, $this->agent, $input)['result'];
    expect($result->notes)->toContain('SYNTHETIC-PRIVATE-PROVISIONING-INSTRUCTION');
    $this->actingAs($this->worker)->get('/it/provisioning/'.$result->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('request.id', $result->id)
            ->where('request.answers.0.value', 'A public equipment request')->has('request.answers', 2)->missing('request.notes'))
        ->assertDontSee('SYNTHETIC-PRIVATE-PROVISIONING-INSTRUCTION', false);
    $this->get('/it?tab=my-tickets')->assertOk()->assertInertia(fn ($page) => $page->where('myProvisioning.total', 1));
    $unrelated = catalogUser('support_worker');
    $this->actingAs($unrelated)->get('/it/provisioning/'.$result->id)->assertNotFound();
    $privateItem = ItCatalogItem::factory()->create(['internal_only' => true, 'outcome_type' => 'provisioning',
        'provisioning_type' => 'equipment', 'form_schema' => ['fields' => []]]);
    $private = app(ItCatalogSubmissionService::class)->submit($privateItem, $this->agent, [
        'schema_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'requested_for_user_id' => $this->worker->id, 'values' => [],
    ])['result'];
    $this->actingAs($this->worker)->get('/it/provisioning/'.$private->id)->assertNotFound();
    $this->workerProfile->update(['is_active' => false]);
    $this->get('/it/provisioning/'.$result->id)->assertNotFound();
});

test('catalogue requested-for preserves the submitter and denies spoofed or out-of-scope beneficiaries', function (string $outcome) {
    Bus::fake([DispatchItTicketNotifications::class]);
    $item = ItCatalogItem::factory()->create(['outcome_type' => $outcome,
        'provisioning_type' => $outcome === 'provisioning' ? 'equipment' : null, 'form_schema' => ['fields' => []]]);
    $input = ['actor_user_id' => $this->worker->id, 'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(), 'values' => [], 'requested_for_user_id' => $this->agent->id];
    $url = "/it/catalog/{$item->id}/submissions";
    $this->actingAs($this->worker)->postJson($url, $input)->assertForbidden();
    $input['actor_user_id'] = $this->agent->id;
    $input['requested_for_user_id'] = $this->worker->id;
    $this->actingAs($this->agent)->postJson($url, $input)->assertCreated();
    $submission = ItCatalogSubmission::query()->sole();
    expect((int) $submission->requester_user_id)->toBe((int) $this->agent->id);
    if ($outcome === 'service_request') {
        expect((int) $submission->result->requester_user_id)->toBe((int) $this->agent->id)
            ->and((int) $submission->result->requested_for_user_id)->toBe((int) $this->worker->id);
    } else {
        expect((int) $submission->result->employee_profile_id)->toBe((int) $this->workerProfile->id)
            ->and((int) $submission->result->created_by)->toBe((int) $this->agent->id);
    }
    $this->postJson($url, [...$input, 'requested_for_user_id' => $this->agent->id])
        ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    $this->workerProfile->update(['primary_site_id' => Site::factory()->create()->id]);
    $response = $this->postJson($url, [...$input, 'idempotency_key' => (string) Str::uuid()]);
    expect($response->status())->toBeIn([403, 422]);
    expect(ItCatalogSubmission::query()->count())->toBe(1);
})->with(['service_request', 'provisioning']);

test('new catalogue HTTP commands require a recoverable UUID before any work', function () {
    $item = ItCatalogItem::factory()->create(['form_schema' => ['fields' => []]]);
    $this->actingAs($this->worker)->postJson("/it/catalog/{$item->id}/submissions", [
        'actor_user_id' => $this->worker->id, 'schema_version' => 1, 'values' => [], 'idempotency_key' => 'legacy-free-text',
    ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    $this->postJson("/it/catalog/{$item->id}/submissions", [
        'actor_user_id' => $this->worker->id, 'schema_version' => 1, 'values' => [],
        'idempotency_key' => (string) Str::uuid(), 'requested_for_user_id' => null,
    ])->assertUnprocessable()->assertJsonValidationErrors('requested_for_user_id');
    expect(ItCatalogSubmission::query()->count())->toBe(0)->and(ItTicket::query()->count())->toBe(0);
});

test('requested-for discovery binds actor publication and Site and denies inaccessible selected people', function () {
    $item = ItCatalogItem::factory()->create(['form_schema' => ['fields' => []]]);
    $url = "/it/catalog/{$item->id}/requested-for/options";
    $input = ['actor_user_id' => $this->agent->id, 'query_uuid' => (string) Str::uuid(),
        'schema_version' => 1, 'site_id' => $this->site->id, 'query' => '', 'selected_id' => $this->worker->id];
    $this->actingAs($this->worker)->postJson($url, [...$input, 'actor_user_id' => $this->worker->id])->assertForbidden();
    $this->actingAs($this->agent)->postJson($url, $input)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('purpose', 'requested-for')->assertJsonPath('site_id', $this->site->id)
        ->assertJsonPath('selected.name', $this->worker->name);
    $this->postJson($url, [...$input, 'actor_user_id' => $this->worker->id])->assertForbidden();
    $this->postJson($url, [...$input, 'schema_version' => 2])->assertConflict();
    $outside = Site::factory()->create();
    $this->workerProfile->update(['primary_site_id' => $outside->id]);
    $this->postJson($url, $input)->assertOk()->assertJsonPath('selected', null)
        ->assertJsonMissing(['name' => $this->worker->name]);
    $this->postJson($url, [...$input, 'site_id' => $outside->id])->assertNotFound();
    $this->actingAs($this->worker)->getJson('/it/catalog')->assertJsonPath('data.0.can_request_for_others', false);
    expect(ItCatalogSubmission::query()->count())->toBe(0);
});

test('catalogue submissions bind the original browser account before creating or replaying work', function (string $outcome) {
    Notification::fake();
    $item = ItCatalogItem::factory()->create([
        'outcome_type' => $outcome,
        'provisioning_type' => $outcome === 'provisioning' ? 'equipment' : null,
        'form_schema' => catalogSchema(),
    ]);
    $input = [
        'actor_user_id' => $this->worker->id,
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'values' => ['details' => 'Private original requester details', 'system_name' => 'VPN'],
    ];
    $url = "/it/catalog/{$item->id}/submissions";

    // Both accounts may request this item. The old buffer still belongs only
    // to the account that opened it, including when the new login manages IT.
    $this->actingAs($this->agent)->postJson($url, $input)->assertForbidden();
    $withoutActor = $input;
    unset($withoutActor['actor_user_id']);
    $this->actingAs($this->worker)->postJson($url, $withoutActor)
        ->assertUnprocessable()->assertJsonValidationErrors('actor_user_id');
    $this->postJson($url, [...$input, 'actor_user_id' => [$this->worker->id]])->assertForbidden();
    expect(ItCatalogSubmission::query()->count())->toBe(0)
        ->and(ItTicket::query()->count())->toBe(0)
        ->and(ItProvisioningRequest::query()->count())->toBe(0);
    Notification::assertNothingSent();

    $this->post($url, $input)->assertRedirect()->assertSessionDoesntHaveErrors();
    $submission = ItCatalogSubmission::query()->sole();
    expect($submission->requester_user_id)->toBe($this->worker->id);
    $this->actingAs($this->agent)->postJson($url, $input)->assertForbidden();
    $this->actingAs($this->worker)->post($url, $input)
        ->assertRedirect()->assertSessionHas('it_catalog_submission.created', false);
    expect(ItCatalogSubmission::query()->count())->toBe(1)
        ->and(ItTicket::query()->count())->toBe($outcome === 'service_request' ? 1 : 0)
        ->and(ItProvisioningRequest::query()->count())->toBe($outcome === 'provisioning' ? 1 : 0);
})->with(['service_request', 'provisioning']);

test('catalogue JSON commands recover the original published result without resubmitting private values', function (string $outcome) {
    Notification::fake();
    $item = ItCatalogItem::factory()->create([
        'outcome_type' => $outcome,
        'provisioning_type' => $outcome === 'provisioning' ? 'equipment' : null,
        'form_schema' => catalogSchema(),
    ]);
    $input = [
        'actor_user_id' => $this->worker->id,
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'values' => ['details' => 'Private detail is not returned in the command receipt', 'system_name' => 'VPN'],
    ];
    $url = "/it/catalog/{$item->id}/submissions";
    $created = $this->actingAs($this->worker)->postJson($url, $input)->assertCreated()
        ->assertJsonPath('status', 'committed')->assertJsonPath('data.viewer_user_id', $this->worker->id)
        ->assertJsonPath('data.catalog_item_id', $item->id)->assertJsonPath('data.schema_version', 1)
        ->assertJsonPath('data.request_uuid', $input['idempotency_key'])->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.result_type', $outcome === 'service_request' ? 'ticket' : 'provisioning')
        ->assertJsonMissingPath('data.values')->assertJsonMissingPath('data.submitted_values');
    expect($created->headers->get('Cache-Control'))->toContain('no-store');
    $resultId = $created->json('data.id');
    $expectedUrl = ($outcome === 'service_request' ? '/it/tickets/' : '/it/provisioning/').$resultId;
    $created->assertJsonPath('data.url', $expectedUrl);
    $item->update(['is_published' => false]);
    $identity = ['actor_user_id' => $this->worker->id, 'idempotency_key' => $input['idempotency_key']];
    Notification::fake();

    $recovered = $this->postJson($url.'/recover', $identity)->assertOk()
        ->assertJsonPath('status', 'committed')->assertJsonPath('data.id', $resultId)
        ->assertJsonPath('data.submission_id', $created->json('data.submission_id'))
        ->assertJsonPath('data.url', $expectedUrl)->assertJsonPath('data.replayed', true);
    expect($recovered->headers->get('Cache-Control'))->toContain('no-store');
    $this->postJson($url, $input)->assertOk()->assertJsonPath('data.replayed', true);
    $this->actingAs($this->agent)->postJson($url.'/recover', $identity)->assertForbidden();
    $different = ItCatalogItem::factory()->create();
    $this->actingAs($this->worker)->postJson("/it/catalog/{$different->id}/submissions/recover", $identity)->assertNotFound();
    expect(ItCatalogSubmission::query()->count())->toBe(1)
        ->and(ItTicket::query()->count())->toBe($outcome === 'service_request' ? 1 : 0)
        ->and(ItProvisioningRequest::query()->count())->toBe($outcome === 'provisioning' ? 1 : 0);
    Notification::assertNothingSent();
})->with(['service_request', 'provisioning']);

test('unknown catalogue recovery is scoped and never creates work or invites a withdrawn request', function () {
    Notification::fake();
    $item = ItCatalogItem::factory()->create();
    $url = "/it/catalog/{$item->id}/submissions/recover";
    $identity = ['actor_user_id' => $this->worker->id, 'idempotency_key' => (string) Str::uuid()];
    $this->actingAs($this->worker)->postJson($url, $identity)->assertOk()
        ->assertJsonPath('status', 'not_found')->assertJsonPath('data.catalog_item_id', $item->id)
        ->assertJsonPath('data.viewer_user_id', $this->worker->id)
        ->assertJsonPath('data.request_uuid', $identity['idempotency_key'])
        ->assertJsonPath('data.retry_same_command', true)->assertJsonMissingPath('data.id');
    $this->postJson($url, ['idempotency_key' => $identity['idempotency_key']])
        ->assertUnprocessable()->assertJsonValidationErrors('actor_user_id');
    $this->postJson($url, [...$identity, 'idempotency_key' => 'invalid'])
        ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    $item->update(['is_published' => false]);
    $this->postJson($url, $identity)->assertNotFound();
    expect(ItCatalogSubmission::query()->count())->toBe(0)
        ->and(ItTicket::query()->count())->toBe(0)
        ->and(ItProvisioningRequest::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('catalogue cancellation prevents late submission and never undoes committed work', function (string $outcome) {
    Notification::fake();
    $item = ItCatalogItem::factory()->create(['outcome_type' => $outcome,
        'provisioning_type' => $outcome === 'provisioning' ? 'equipment' : null, 'form_schema' => catalogSchema()]);
    $url = "/it/catalog/{$item->id}/submissions";
    $identity = ['actor_user_id' => $this->worker->id, 'idempotency_key' => (string) Str::uuid()];
    $input = [...$identity, 'schema_version' => 1, 'values' => ['details' => 'Original request', 'system_name' => 'VPN']];
    $this->actingAs($this->agent)->postJson($url.'/cancel', $identity)->assertForbidden();
    $this->actingAs($this->worker)->postJson($url.'/cancel', $identity)->assertOk()
        ->assertJsonPath('status', 'cancelled')->assertJsonPath('data.cancelled', true)
        ->assertJsonPath('data.viewer_user_id', $this->worker->id)->assertJsonPath('data.catalog_item_id', $item->id)
        ->assertJsonPath('data.request_uuid', $identity['idempotency_key'])->assertJsonMissingPath('data.id');
    $this->postJson($url.'/cancel', $identity)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson($url, $input)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson($url.'/recover', $identity)->assertOk()->assertJsonPath('status', 'cancelled');
    expect(ItCatalogSubmission::count())->toBe(0)->and(ItTicket::count())->toBe(0)
        ->and(ItProvisioningRequest::count())->toBe(0)
        ->and(ItTicketCommandReceipt::where('operation', ItTicketCommandReceipt::CATALOGUE_OPERATION)->count())->toBe(1)
        ->and(AuditLog::where('action', 'it.catalogue.submission.cancelled')->count())->toBe(1);
    Notification::assertNothingSent();

    $input['idempotency_key'] = (string) Str::uuid();
    $saved = $this->postJson($url, $input)->assertCreated()->json('data');
    $item->update(['is_published' => false]);
    $identity['idempotency_key'] = $input['idempotency_key'];
    $this->postJson($url.'/cancel', $identity)->assertOk()->assertJsonPath('status', 'committed')
        ->assertJsonPath('data.id', $saved['id'])->assertJsonPath('data.replayed', true);
    $this->postJson($url.'/cancel', [...$identity, 'idempotency_key' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('status', 'cancelled');
    expect(ItCatalogSubmission::count())->toBe(1)
        ->and(ItTicket::count())->toBe($outcome === 'service_request' ? 1 : 0)
        ->and(ItProvisioningRequest::count())->toBe($outcome === 'provisioning' ? 1 : 0);
    // Withdrawal permits cancellation only, never fresh intake or discovery.
    expect(fn () => $item->fresh()->publishedContract())
        ->toThrow(HttpException::class);
    $this->postJson($url, [...$input, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
    $privateItem = ItCatalogItem::factory()->create(['internal_only' => true]);
    $privateItem->update(['is_published' => false, 'internal_only' => false]);
    $this->postJson("/it/catalog/{$privateItem->id}/submissions/cancel", [
        ...$identity, 'idempotency_key' => (string) Str::uuid(),
    ])->assertNotFound();
})->with(['service_request', 'provisioning']);

test('catalogue cancellation audit failure rolls back its receipt and inaccessible drafts cannot be cancelled', function () {
    $item = ItCatalogItem::factory()->create();
    $key = (string) Str::uuid();
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    AuditLog::creating(fn () => throw new RuntimeException('Synthetic cancellation audit failure'));
    try {
        expect(fn () => app(ItCatalogSubmissionService::class)->cancel($item->id, $this->worker, $key, $this->worker->id))
            ->toThrow(RuntimeException::class);
        expect(ItTicketCommandReceipt::where('request_uuid', $key)->count())->toBe(0);
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
    $draft = ItCatalogItem::factory()->unpublished()->create();
    $this->actingAs($this->worker)->postJson("/it/catalog/{$draft->id}/submissions/cancel", [
        'actor_user_id' => $this->worker->id, 'idempotency_key' => $key,
    ])->assertNotFound();
    expect(ItTicketCommandReceipt::where('request_uuid', $key)->count())->toBe(0);
});

test('requesters track their canonical catalogue work without technician or HR details', function () {
    $item = ItCatalogItem::factory()->create([
        'outcome_type' => 'provisioning', 'provisioning_type' => 'equipment', 'form_schema' => catalogSchema(),
    ]);
    $this->actingAs($this->worker)->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id,
        'schema_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        'values' => ['details' => 'A headset please', 'system_name' => 'VPN'],
    ])->assertRedirect();
    $work = ItProvisioningRequest::query()->sole();
    $work->update(['notes' => 'PRIVATE work notes', 'failure_reason' => 'PRIVATE provider failure', 'fulfiller_context' => ['secret' => 'PRIVATE token']]);
    ItTicketEvent::record($work, 'failed', $this->agent->id, ['reason' => 'PRIVATE event payload']);
    $response = $this->get('/it/provisioning/'.$work->id)->assertOk()
        ->assertInertia(fn ($page) => $page->component('it/provisioning/show')
            ->where('request.id', $work->id)->where('request.catalogue_version', 1)
            ->where('request.answers.0.value', 'A headset please')->has('request.answers', 2)
            ->has('request.events', 2)->missing('request.notes')->missing('request.employee_profile_id')
            ->missing('request.fulfillment_context')->missing('request.events.1.payload'));
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->getContent())->not->toContain('PRIVATE');
    $this->get('/it?tab=my-tickets')->assertOk()->assertInertia(fn ($page) => $page
        ->where('myProvisioning.total', 1)->where('myProvisioning.data.0.id', $work->id));
    $item->update(['name' => 'Later revision', 'is_published' => false]);
    $this->get('/it/provisioning/'.$work->id)->assertOk()->assertInertia(fn ($page) => $page
        ->where('request.title', $work->item)->where('request.catalogue_version', 1));
    $stranger = catalogUser('support_worker');
    $this->actingAs($stranger)->get('/it/provisioning/'.$work->id)->assertNotFound();
    $this->actingAs($this->worker);
    $this->workerProfile->update(['is_active' => false]);
    $this->get('/it/provisioning/'.$work->id)->assertNotFound();
    $this->get('/it?tab=my-tickets')->assertOk()->assertInertia(fn ($page) => $page->where('myProvisioning.total', 0));
});

test('tracking requires catalogue provenance and current Site and approval access', function () {
    $work = ItProvisioningRequest::query()->create([
        'employee_profile_id' => $this->workerProfile->id, 'created_by' => $this->worker->id,
        'type' => 'account', 'item' => 'Private HR task', 'status' => 'pending', 'priority' => 'normal',
    ]);
    $this->actingAs($this->worker)->get('/it/provisioning/'.$work->id)->assertNotFound();
    $item = ItCatalogItem::factory()->create(['outcome_type' => 'provisioning', 'provisioning_type' => 'equipment', 'form_schema' => ['fields' => []]]);
    $result = app(ItCatalogSubmissionService::class)->submit($item, $this->worker, [
        'schema_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'values' => [],
    ])['result'];
    $this->site->update(['is_active' => false]);
    $this->get('/it/provisioning/'.$result->id)->assertNotFound();
    $this->site->update(['is_active' => true]);
    $this->worker->update(['approved_at' => null]);
    expect(app(ItProvisioningAccessService::class)->canTrack($this->worker->fresh(), $result))->toBeFalse();
});

test('tracking hides an internal request after its author loses IT management', function () {
    $item = ItCatalogItem::factory()->create(['internal_only' => true, 'outcome_type' => 'provisioning', 'provisioning_type' => 'account', 'form_schema' => ['fields' => []]]);
    $result = app(ItCatalogSubmissionService::class)->submit($item, $this->agent, [
        'schema_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'values' => [],
    ])['result'];
    $this->actingAs($this->agent)->get('/it/provisioning/'.$result->id)->assertOk();
    $this->agent->roles()->sync([Role::query()->where('name', 'support_worker')->value('id')]);
    $this->agent->update(['role' => 'support_worker']);
    $this->actingAs($this->agent->fresh())->get('/it/provisioning/'.$result->id)->assertNotFound();
    expect(app(ItProvisioningAccessService::class)->canTrack($this->agent->fresh(), $result))->toBeFalse();
});

test('requester tracking paginates and applies literal search and status to the complete owned set', function () {
    $attributes = ['outcome_type' => 'provisioning', 'provisioning_type' => 'equipment', 'form_schema' => ['fields' => []]];
    $item = ItCatalogItem::factory()->create([...$attributes, 'name' => 'Tracking request']);
    $percentItem = ItCatalogItem::factory()->create([...$attributes, 'name' => 'Tracking 100% request']);
    $firstId = null;
    foreach (range(1, 21) as $number) {
        $work = app(ItCatalogSubmissionService::class)->submit($number === 21 ? $percentItem : $item, $this->worker, [
            'schema_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'values' => [],
        ])['result'];
        $firstId ??= $work->id;
        $work->update(['item' => 'Private internal task '.$number,
            'status' => $number <= 5 ? 'done' : 'pending']);
    }
    $this->actingAs($this->worker)->get('/it?tab=my-tickets')->assertOk()->assertInertia(fn ($page) => $page
        ->where('myProvisioning.total', 21)->where('myProvisioning.matched', 21)
        ->has('myProvisioning.data', 20)->where('myProvisioning.last_page', 2));
    $this->get('/it?tab=my-tickets&my_provisioning_page=2')->assertOk()->assertInertia(fn ($page) => $page
        ->has('myProvisioning.data', 1)->where('myProvisioning.data.0.id', $firstId));
    $this->get('/it?tab=my-tickets&my_status=pending')->assertOk()->assertInertia(fn ($page) => $page
        ->where('myProvisioning.total', 21)->where('myProvisioning.matched', 16)->has('myProvisioning.data', 16));
    $this->get('/it?tab=my-tickets&my_q=%25')->assertOk()->assertInertia(fn ($page) => $page
        ->where('myProvisioning.matched', 1)->where('myProvisioning.data.0.title', 'Tracking 100% request'));
    $this->get('/it?tab=my-tickets&my_q=Private%20internal')->assertOk()->assertInertia(fn ($page) => $page
        ->where('myProvisioning.matched', 0)->has('myProvisioning.data', 0));
    $this->get('/it?tab=my-tickets&my_q=IT-P'.str_pad((string) $firstId, 6, '0', STR_PAD_LEFT))->assertOk()->assertInertia(fn ($page) => $page
        ->where('myProvisioning.matched', 1)->where('myProvisioning.data.0.id', $firstId));
});

test('published catalogue discovery is application-wide and strips internal fields for requesters', function () {
    ItCatalogItem::factory()->create([
        'name' => 'Request Microsoft 365 access',
        'slug' => 'request-microsoft-365-access',
        'sort_order' => 10,
        'form_schema' => catalogSchema(),
    ]);
    ItCatalogItem::factory()->unpublished()->create(['name' => 'Draft request']);
    ItCatalogItem::factory()->create([
        'name' => 'Request a desk phone',
        'sort_order' => 20,
    ]);

    $this->actingAs($this->worker)
        ->getJson('/it/catalog?q=microsoft')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Request Microsoft 365 access')
        ->assertJsonCount(2, 'data.0.form_schema.fields')
        ->assertJsonMissing(['key' => 'fulfilment_note']);

    $this->actingAs($this->agent)
        ->getJson('/it/catalog?q=microsoft')
        ->assertOk()
        ->assertJsonCount(3, 'data.0.form_schema.fields');

    $this->actingAs($this->worker)
        ->get('/it')
        ->assertInertia(fn ($page) => $page
            ->has('catalogItems', 2)
            ->where('catalogItems.0.name', 'Request Microsoft 365 access')
            ->has('kbPublished'));
});

test('catalogue submission enforces the published schema version required fields and internal boundary', function () {
    $schema = catalogSchema();
    $schema['fields'][] = [
        'key' => 'device_count',
        'label' => 'Number of devices',
        'type' => 'integer',
        'required' => false,
        'min' => 1,
        'max' => 5,
    ];
    $item = ItCatalogItem::factory()->create([
        'form_schema_version' => 3,
        'form_schema' => $schema,
    ]);

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id,
            'schema_version' => 2,
            'idempotency_key' => (string) Str::uuid(),
            'values' => ['details' => 'Please help', 'system_name' => 'VPN'],
        ])
        ->assertSessionHasErrors('schema_version');

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id,
            'schema_version' => 3,
            'idempotency_key' => (string) Str::uuid(),
            'values' => ['details' => 'Please help'],
        ])
        ->assertSessionHasErrors('values.system_name');

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id,
            'schema_version' => 3,
            'idempotency_key' => (string) Str::uuid(),
            'values' => [
                'details' => 'Please help',
                'system_name' => 'VPN',
                'fulfilment_note' => 'Grant global admin',
            ],
        ])
        ->assertSessionHasErrors('values.fulfilment_note');

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id,
            'schema_version' => 3,
            'idempotency_key' => (string) Str::uuid(),
            'values' => [
                'details' => 'Please help',
                'system_name' => 'VPN',
                'device_count' => 6,
            ],
        ])
        ->assertSessionHasErrors('values.device_count');

    expect(ItCatalogSubmission::query()->count())->toBe(0);
});

test('service request catalogue intake is idempotent and creates one canonical governed ticket', function () {
    Notification::fake();
    $item = ItCatalogItem::factory()->create([
        'name' => 'Request VPN access',
        'slug' => 'request-vpn-access',
        'outcome_type' => 'service_request',
        'category' => 'account',
        'default_priority' => 'high',
        'requires_approval' => true,
        'form_schema_version' => 4,
        'form_schema' => catalogSchema(),
    ]);
    $payload = [
        'schema_version' => 4,
        'idempotency_key' => (string) Str::uuid(),
        'values' => ['details' => 'Need access for the on-call shift', 'system_name' => 'VPN'],
    ];

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$payload])
        ->assertRedirect()
        ->assertSessionHas('it_catalog_submission');
    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$payload])
        ->assertRedirect();

    expect(ItCatalogSubmission::query()->count())->toBe(1)
        ->and(ItTicket::query()->count())->toBe(1);

    $submission = ItCatalogSubmission::query()->firstOrFail();
    $ticket = ItTicket::query()->firstOrFail();

    expect($submission->schema_version)->toBe(4)
        ->and($submission->schema_snapshot['fields'])->toHaveCount(3)
        ->and($submission->submitted_values['details'])->toBe('Need access for the on-call shift')
        ->and($submission->result->is($ticket))->toBeTrue()
        ->and($ticket->reference)->toMatch('/^IT-\d{6}$/')
        ->and($ticket->work_type)->toBe('service_request')
        ->and($ticket->workflow_state)->toBe('submitted')
        ->and($ticket->requires_approval)->toBeTrue()
        ->and($ticket->first_response_due_at)->not->toBeNull()
        ->and($ticket->resolution_due_at)->not->toBeNull()
        ->and($ticket->events()->where('type', 'created')->count())->toBe(1);
    expect(fn () => $submission->update(['submitted_values' => ['details' => 'tampered']]))
        ->toThrow(LogicException::class, 'Catalogue submissions are immutable.');

    Notification::assertSentToTimes($this->worker, TicketCreatedNotification::class, 1);

    $this->actingAs($this->worker)
        ->get('/it')
        ->assertInertia(fn ($page) => $page
            ->where('myTickets.0.reference', $ticket->reference));
});

test('security catalogue intake creates a security request in the shared ticket store', function () {
    $item = ItCatalogItem::factory()->securityRequest()->create([
        'category' => 'other',
        'form_schema' => catalogSchema(),
    ]);

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id,
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'values' => ['details' => 'Unexpected sign-in prompt', 'system_name' => 'Microsoft 365'],
        ])
        ->assertRedirect();

    expect(ItTicket::query()->where('work_type', 'security_request')->count())->toBe(1)
        ->and(ItCatalogSubmission::query()->firstOrFail()->result_type)->toBe('it_ticket');
});

test('provisioning catalogue intake creates the canonical provisioning record and timeline only', function () {
    $profile = $this->workerProfile;
    $item = ItCatalogItem::factory()->provisioning()->create([
        'name' => 'Request a replacement laptop',
        'provisioning_type' => 'equipment',
        'form_schema' => catalogSchema(),
    ]);

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id,
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'values' => ['details' => 'Current laptop battery is swollen', 'system_name' => 'Microsoft 365'],
        ])
        ->assertRedirect()
        ->assertSessionHas('it_catalog_submission');

    expect(ItTicket::query()->count())->toBe(0)
        ->and(ItProvisioningRequest::query()->count())->toBe(1);

    $provisioning = ItProvisioningRequest::query()->firstOrFail();
    $submission = ItCatalogSubmission::query()->firstOrFail();
    expect((int) $provisioning->employee_profile_id)->toBe($profile->id)
        ->and($provisioning->type)->toBe('equipment')
        ->and($provisioning->approval_required)->toBeFalse()
        ->and($provisioning->approval_status)->toBe('not_required')
        ->and($provisioning->events()->where('type', 'created')->count())->toBe(1)
        ->and($submission->result->is($provisioning))->toBeTrue();
});

test('catalogue provisioning preserves its approval gate through revision replay and fulfilment', function () {
    Notification::fake();
    $item = ItCatalogItem::factory()->provisioning()->create([
        'requires_approval' => true,
        'form_schema' => catalogSchema(),
    ]);
    $payload = [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'values' => ['details' => 'Request approved application access', 'system_name' => 'Microsoft 365'],
    ];

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$payload])
        ->assertRedirect()
        ->assertSessionHas('it_catalog_submission');

    $request = ItProvisioningRequest::query()->sole();
    expect($request->approval_required)->toBeTrue()
        ->and($request->approval_status)->toBe('pending')
        ->and($request->events()->where('type', 'created')->sole()->payload['approval_required'])->toBeTrue();

    $item->update(['requires_approval' => false]);
    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$payload])
        ->assertRedirect();
    expect(ItProvisioningRequest::query()->count())->toBe(1)
        ->and($request->fresh()->approval_required)->toBeTrue();

    $request->update(['assigned_to_user_id' => $this->agent->id]);
    $identity = fn (User $actor): array => ['actor_user_id' => $actor->id, 'request_uuid' => (string) Str::uuid(),
        'expected_version' => $request->fresh()->lock_version];
    $this->actingAs($this->agent)
        ->post("/it/provisioning/{$request->id}/fulfil", $identity($this->agent))
        ->assertSessionHas('error', 'This request needs approval before fulfilment.');
    expect($request->fresh()->status)->toBe('pending')
        ->and($request->events()->where('type', 'fulfilled')->count())->toBe(0);

    $this->actingAs($this->worker)
        ->post("/it/provisioning/{$request->id}/approve")
        ->assertForbidden();
    expect($request->fresh()->approval_status)->toBe('pending');

    $approver = catalogUser('hr');
    $cover = catalogUser('hr');
    ensureCanonicalHrStaffProfile($approver, $this->site);
    ensureCanonicalHrStaffProfile($cover, $this->site);
    app(ItProvisioningRequestLifecycleService::class)->requestApproval($request, $this->agent, [
        'primary_approver_user_id' => $approver->id, 'cover_approver_user_id' => $cover->id,
        'approval_expires_at' => now()->addDays(2)->toIso8601String(), 'reason' => 'Independent review of original catalogue approval.',
    ]);
    $this->actingAs($approver)
        ->post("/it/provisioning/{$request->id}/approve", [...$identity($approver), 'decision_note' => 'Approved for this role.'])
        ->assertSessionHas('success');
    $this->actingAs($this->agent)->post("/it/provisioning/{$request->id}/fulfil", [
        ...$identity($this->agent), 'external_ref' => 'SYN-CATALOGUE-ACCESS',
        'evidence_summary' => 'Synthetic catalogue access manually completed and verified.',
    ])
        ->assertSessionHas('success');

    expect($request->fresh()->status)->toBe('done')
        ->and($request->fresh()->approved_by_user_id)->toBe($approver->id)
        ->and($request->events()->where('type', 'approved')->count())->toBe(1)
        ->and($request->events()->where('type', 'fulfilled')->count())->toBe(1)
        ->and(ItCatalogSubmission::query()->count())->toBe(1)
        ->and(ItTicket::query()->count())->toBe(0);
});

test('a requester cannot submit an unpublished or internal-only catalogue item', function () {
    $draft = ItCatalogItem::factory()->unpublished()->create();
    $internal = ItCatalogItem::factory()->create(['internal_only' => true]);
    $payload = [
        'schema_version' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'values' => [],
    ];

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$draft->id}/submissions", ['actor_user_id' => $this->worker->id, ...$payload])
        ->assertNotFound();
    $this->actingAs($this->worker)
        ->post("/it/catalog/{$internal->id}/submissions", ['actor_user_id' => $this->worker->id, ...$payload])
        ->assertNotFound();
});

test('agents author version publish and reasonedly unpublish catalogue forms end to end', function () {
    $service = ItService::factory()->create(['name' => 'Workplace technology']);
    $payload = [
        'it_service_id' => $service->id,
        'name' => 'Request workplace equipment',
        'description' => 'Request approved equipment for your role.',
        'outcome_type' => 'provisioning',
        'category' => 'hardware',
        'provisioning_type' => 'equipment',
        'default_priority' => 'normal',
        'requires_approval' => true,
        'internal_only' => false,
        'search_terms' => ['laptop', 'equipment'],
        'sort_order' => 20,
        'form_schema' => [
            'fields' => [[
                'key' => 'employee_profile_id',
                'label' => 'Who needs the equipment?',
                'type' => 'employee',
                'required' => true,
                'visibility' => 'requester',
                'help' => 'Choose an employee you are allowed to request for.',
            ]],
        ],
    ];

    $this->actingAs($this->agent)
        ->post('/it/setup/catalogue-items', $payload)
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $item = ItCatalogItem::query()->sole();
    expect($item->is_published)->toBeFalse()
        ->and($item->form_schema_version)->toBe(1)
        ->and($item->slug)->toBe('request-workplace-equipment')
        ->and(AuditLog::query()->where('action', 'it.catalogue.item.created')->count())->toBe(1);

    $this->actingAs($this->agent)
        ->get('/it/setup?tab=catalogue')
        ->assertInertia(fn ($page) => $page
            ->has('catalogItems', 1)
            ->where('catalogItems.0.is_published', false));
    $this->actingAs($this->worker)
        ->get('/it')
        ->assertInertia(fn ($page) => $page->has('catalogItems', 0));

    $this->actingAs($this->agent)
        ->post("/it/setup/catalogue-items/{$item->id}/publish", ['expected_version' => $item->fresh()->lock_version])
        ->assertRedirect();
    $this->actingAs($this->worker)
        ->get('/it')
        ->assertInertia(fn ($page) => $page
            ->has('catalogItems', 1)
            ->where('catalogItems.0.name', 'Request workplace equipment'));

    $payload['form_schema']['fields'][] = [
        'key' => 'asset_id',
        'label' => 'Existing equipment',
        'type' => 'asset',
        'required' => false,
        'visibility' => 'requester',
    ];
    $this->actingAs($this->agent)
        ->patch("/it/setup/catalogue-items/{$item->id}", [...$payload, 'expected_version' => $item->fresh()->lock_version])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();
    expect($item->fresh()->form_schema_version)->toBe(2)
        ->and($item->fresh()->is_published)->toBeTrue();

    $this->actingAs($this->agent)
        ->post("/it/setup/catalogue-items/{$item->id}/unpublish")
        ->assertSessionHasErrors('reason');
    $this->actingAs($this->agent)
        ->post("/it/setup/catalogue-items/{$item->id}/unpublish", [
            'reason' => 'The equipment catalogue is being replaced.',
            'expected_version' => $item->fresh()->lock_version,
        ])
        ->assertRedirect();
    expect($item->fresh()->is_published)->toBeFalse()
        ->and(AuditLog::query()
            ->where('action', 'it.catalogue.item.unpublished')
            ->where('meta->reason', 'The equipment catalogue is being replaced.')
            ->count())->toBe(1);
});

test('draft edits preserve publication and requests recover the original contract after withdrawal', function () {
    Notification::fake();
    $item = ItCatalogItem::factory()->create([
        'name' => 'Published equipment request',
        'category' => 'hardware',
        'form_schema' => ['fields' => []],
    ]);
    $originalVersion = $item->published_version_id;
    $draft = $item->only(['it_service_id', 'name', 'description', 'outcome_type', 'category', 'provisioning_type', 'default_priority', 'requires_approval', 'internal_only', 'form_schema', 'search_terms', 'sort_order']);
    $draft = [...$draft, 'name' => 'Confidential new request', 'requires_approval' => true, 'internal_only' => true, 'expected_version' => 1];
    $this->actingAs($this->agent)->patch("/it/setup/catalogue-items/{$item->id}", $draft)
        ->assertSessionDoesntHaveErrors();
    expect($item->fresh()->form_schema_version)->toBe(2)
        ->and($item->fresh()->published_version_id)->toBe($originalVersion);

    $this->patch("/it/setup/catalogue-items/{$item->id}", [...$draft, 'name' => 'Stale overwrite'])
        ->assertSessionHasErrors('expected_version');
    $this->post("/it/setup/catalogue-items/{$item->id}/publish", ['expected_version' => 1])
        ->assertSessionHasErrors('expected_version');
    $this->actingAs($this->worker)->getJson('/it/catalog?q=Published')
        ->assertOk()->assertJsonPath('data.0.name', 'Published equipment request')
        ->assertJsonPath('data.0.form_schema_version', 1);
    $this->getJson('/it/catalog?q=Confidential')->assertJsonCount(0, 'data');
    $this->get('/it')->assertInertia(fn ($page) => $page->where('catalogItems.0.name', 'Published equipment request'));

    $input = ['schema_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'values' => []];
    $this->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$input])->assertSessionDoesntHaveErrors();
    $submission = ItCatalogSubmission::query()->sole();
    expect($submission->contract_snapshot['name'])->toBe('Published equipment request')
        ->and($submission->contract_snapshot['requires_approval'])->toBeFalse()
        ->and((int) $submission->catalog_version_id)->toBe((int) $originalVersion);

    $this->actingAs($this->agent)->post("/it/setup/catalogue-items/{$item->id}/publish", ['expected_version' => 2])
        ->assertSessionDoesntHaveErrors();
    expect($item->fresh()->publishedVersion->version)->toBe(2)
        ->and($item->fresh()->publishedVersion->contract['requires_approval'])->toBeTrue();
    $this->actingAs($this->worker)->getJson('/it/catalog')->assertJsonCount(0, 'data');
    $this->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$input])->assertSessionDoesntHaveErrors();
    $this->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$input, 'idempotency_key' => (string) Str::uuid(), 'schema_version' => 2])->assertNotFound();

    $this->actingAs($this->agent)->post("/it/setup/catalogue-items/{$item->id}/unpublish", [
        'expected_version' => 3, 'reason' => 'Withdrawn for review.',
    ])->assertSessionDoesntHaveErrors();
    $this->actingAs($this->worker)->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$input])
        ->assertSessionDoesntHaveErrors()->assertSessionHas('it_catalog_submission.created', false);
    $this->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$input, 'values' => ['changed' => 'input']])
        ->assertSessionHasErrors('idempotency_key');
    $this->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$input, 'idempotency_key' => (string) Str::uuid()])
        ->assertNotFound();
    expect(ItCatalogSubmission::query()->count())->toBe(1)
        ->and(ItTicket::query()->count())->toBe(1)
        ->and($submission->fresh()->contract_snapshot['name'])->toBe('Published equipment request');
    expect(fn () => $item->fresh()->publishedVersion->update(['contract' => []]))->toThrow(LogicException::class);
    Notification::assertSentToTimes($this->worker, TicketCreatedNotification::class, 1);
});

test('catalogue replay denies a result whose current permission boundary changed', function () {
    Notification::fake();
    $item = ItCatalogItem::factory()->create(['form_schema' => ['fields' => []]]);
    $input = ['schema_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'values' => []];
    $this->actingAs($this->worker)->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$input])->assertRedirect();
    $ticket = ItTicket::query()->sole();
    $other = catalogUser('support_worker');
    $ticket->forceFill(['requester_user_id' => $other->id, 'requested_for_user_id' => $other->id, 'is_sensitive' => true])->save();
    $this->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$input])->assertNotFound();
    expect(ItTicket::query()->count())->toBe(1);
});

test('failed publication returns a form error without changing the current contract', function () {
    $service = ItService::factory()->create(['is_active' => false]);
    $item = ItCatalogItem::factory()->unpublished()->create(['it_service_id' => $service->id]);
    $this->actingAs($this->agent)->post("/it/setup/catalogue-items/{$item->id}/publish", ['expected_version' => 1])
        ->assertSessionHasErrors('publication')->assertSessionMissing('success');
    expect($item->fresh()->is_published)->toBeFalse()
        ->and($item->fresh()->published_version_id)->toBeNull()
        ->and($item->fresh()->lock_version)->toBe(1)
        ->and(ItCatalogVersion::query()->count())->toBe(0);
});

test('catalogue seed reruns preserve authored drafts and their published versions', function () {
    $item = ItCatalogItem::factory()->create(['slug' => 'request-equipment', 'name' => 'Reviewed equipment policy']);
    $version = $item->published_version_id;
    $this->seed(ItServiceCatalogSeeder::class);
    $this->seed(ItServiceCatalogSeeder::class);
    expect($item->fresh()->name)->toBe('Reviewed equipment policy')
        ->and($item->fresh()->published_version_id)->toBe($version)
        ->and(ItCatalogItem::query()->count())->toBe(3)
        ->and(ItCatalogVersion::query()->count())->toBe(3);
});

test('a failed imported publication rolls back the new catalogue item', function () {
    $dispatcher = ItCatalogVersion::getEventDispatcher();
    ItCatalogVersion::setEventDispatcher(clone $dispatcher);
    ItCatalogVersion::creating(fn () => throw new RuntimeException('Synthetic publication storage failure'));
    try {
        expect(fn () => ItCatalogItem::factory()->create())->toThrow(RuntimeException::class, 'Synthetic publication storage failure');
        expect(ItCatalogItem::query()->count())->toBe(0)
            ->and(ItCatalogVersion::query()->count())->toBe(0);
    } finally {
        ItCatalogVersion::setEventDispatcher($dispatcher);
    }
});

test('catalogue entity fields expose only canonical choices and reject forged direct objects', function () {
    $assignedAsset = Asset::factory()->forSite($this->site)->create([
        'name' => 'Assigned laptop',
        'asset_tag' => 'LT-001',
        'status' => 'active',
    ]);
    AssetAssignment::query()->create([
        'asset_id' => $assignedAsset->id,
        'assignee_type' => 'staff',
        'assignee_id' => $this->worker->id,
        'purpose' => 'Work device',
        'assigned_at' => now(),
    ]);

    $other = catalogUser('support_worker');
    $otherProfile = HrEmployeeProfile::factory()->create([
        'user_id' => $other->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'created_by' => $this->agent->id,
        'updated_by' => $this->agent->id,
    ]);
    $otherAsset = Asset::factory()->forSite($this->site)->create([
        'name' => 'Someone else’s laptop',
        'status' => 'active',
    ]);
    AssetAssignment::query()->create([
        'asset_id' => $otherAsset->id,
        'assignee_type' => 'staff',
        'assignee_id' => $other->id,
        'purpose' => 'Work device',
        'assigned_at' => now(),
    ]);

    $item = ItCatalogItem::factory()->create([
        'name' => 'Report equipment issue',
        'form_schema' => [
            'fields' => [
                ['key' => 'employee', 'label' => 'Employee', 'type' => 'employee', 'required' => true],
                ['key' => 'user', 'label' => 'User', 'type' => 'user', 'required' => true],
                ['key' => 'asset', 'label' => 'Equipment', 'type' => 'asset', 'required' => true],
            ],
        ],
    ]);

    $access = app(ItProvisioningAccessService::class);
    expect($access->selectableProfiles($this->worker)->exists())->toBeFalse()
        ->and($access->canSelectProfile($this->worker, $this->workerProfile))->toBeFalse()
        ->and($access->canRequestForProfile($this->worker, $this->workerProfile))->toBeTrue()
        ->and($access->canRequestForProfile($this->worker, $otherProfile))->toBeFalse();

    $this->actingAs($this->worker)
        ->get('/it')
        ->assertInertia(fn ($page) => $page
            ->where('catalogFieldOptions.employee.0.id', $this->workerProfile->id)
            ->where('catalogFieldOptions.user.0.id', $this->worker->id)
            ->where('catalogFieldOptions.asset.0.id', $assignedAsset->id)
            ->where('catalogFieldOptions.employee', fn ($options) => ! collect($options)->pluck('id')->contains($otherProfile->id))
            ->where('catalogFieldOptions.user', fn ($options) => ! collect($options)->pluck('id')->contains($other->id))
            ->where('catalogFieldOptions.asset', fn ($options) => ! collect($options)->pluck('id')->contains($otherAsset->id)));

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id,
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'values' => [
                'employee' => $otherProfile->id,
                'user' => $other->id,
                'asset' => $otherAsset->id,
            ],
        ])
        ->assertSessionHasErrors([
            'values.employee',
            'values.user',
            'values.asset',
        ]);

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id,
            'schema_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'values' => [
                'employee' => $this->workerProfile->id,
                'user' => $this->worker->id,
                'asset' => $assignedAsset->id,
            ],
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $ticket = ItTicket::query()->sole();
    expect($ticket->description)->toContain($this->worker->name)
        ->and($ticket->description)->toContain('Assigned laptop')
        ->and($ticket->description)->toContain('LT-001')
        ->and($ticket->description)->not->toContain("Equipment: {$assignedAsset->id}");
});

test('catalogue entity submissions resolve current permitted records beyond the first 200 choices', function () {
    HrEmployeeProfile::factory()->count(200)->create([
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'created_by' => $this->agent->id,
        'updated_by' => $this->agent->id,
    ]);
    $person = User::factory()->create(['name' => 'Last permitted catalogue person']);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $person->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'created_by' => $this->agent->id,
        'updated_by' => $this->agent->id,
    ]);
    Asset::factory()->count(200)->forSite($this->site)->create([
        'name' => 'Earlier equipment',
        'created_by_user_id' => $this->agent->id,
        'updated_by_user_id' => $this->agent->id,
    ]);
    $asset = Asset::factory()->forSite($this->site)->create([
        'name' => 'Z last permitted equipment',
        'created_by_user_id' => $this->agent->id,
        'updated_by_user_id' => $this->agent->id,
    ]);
    $options = app(ItCatalogFieldOptionService::class);
    $firstPage = $options->forTypes($this->agent);
    foreach (['employee' => $profile->id, 'user' => $person->id, 'asset' => $asset->id] as $type => $id) {
        expect($firstPage[$type])->toHaveCount(200)
            ->and(collect($firstPage[$type])->pluck('id')->all())->not->toContain($id)
            ->and($options->find($this->agent, $type, $id)['id'])->toBe($id)
            ->and($options->find($this->worker, $type, $id))->toBeNull();
    }

    $item = ItCatalogItem::factory()->create([
        'form_schema' => ['fields' => [
            ['key' => 'employee', 'label' => 'Employee', 'type' => 'employee', 'required' => true],
            ['key' => 'user', 'label' => 'User', 'type' => 'user', 'required' => true],
            ['key' => 'asset', 'label' => 'Equipment', 'type' => 'asset', 'required' => true],
        ]],
    ]);
    $payload = [
        'schema_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        'values' => ['employee' => $profile->id, 'user' => $person->id, 'asset' => $asset->id],
    ];
    $this->actingAs($this->agent);
    foreach (['employee' => $profile->id, 'user' => $person->id, 'asset' => $asset->id] as $type => $id) {
        $search = [
            'actor_user_id' => $this->agent->id, 'schema_version' => 1,
            'query_uuid' => (string) Str::uuid(), 'query' => 'last permitted', 'selected_id' => $id,
        ];
        $response = $this->postJson("/it/catalog/{$item->id}/fields/{$type}/options", $search)
            ->assertOk()->assertJsonPath('viewer_user_id', $this->agent->id)
            ->assertJsonPath('query_uuid', $search['query_uuid'])->assertJsonPath('field_key', $type)
            ->assertJsonPath('catalog_item_id', $item->id)->assertJsonPath('schema_version', 1)
            ->assertJsonCount(1, 'options')->assertJsonPath('options.0.id', $id)
            ->assertJsonPath('selected.id', $id)->assertJsonPath('next_cursor', null);
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
    $seen = [];
    $after = null;
    do {
        $page = $this->postJson("/it/catalog/{$item->id}/fields/asset/options", [
            'actor_user_id' => $this->agent->id, 'schema_version' => 1,
            'query_uuid' => (string) Str::uuid(), 'after' => $after,
        ])->assertOk()->json();
        expect(count($page['options']))->toBeLessThanOrEqual(50);
        $seen = [...$seen, ...array_column($page['options'], 'id')];
        $after = $page['next_cursor'];
    } while ($after !== null && count($seen) <= 250);
    expect($seen)->toHaveCount(201)->and(array_unique($seen))->toHaveCount(201)
        ->and($seen)->toContain($asset->id)->and($after)->toBeNull();
    $this->actingAs($this->agent)->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->agent->id, ...$payload])
        ->assertRedirect()->assertSessionDoesntHaveErrors();
    expect(ItTicket::query()->sole()->description)->toContain($person->name, $asset->name)
        ->and(ItCatalogSubmission::query()->count())->toBe(1);

    // Previously discovered records are rechecked at submission, without retaining a grant.
    $profile->update(['is_active' => false]);
    $asset->update(['status' => 'retired']);
    $payload['idempotency_key'] = (string) Str::uuid();
    $this->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->agent->id, ...$payload])
        ->assertSessionHasErrors(['values.employee', 'values.user', 'values.asset']);
    expect(ItTicket::query()->count())->toBe(1)
        ->and(ItCatalogSubmission::query()->count())->toBe(1);
});

test('catalogue field search requires the current actor and visible published field version', function () {
    $item = ItCatalogItem::factory()->create(['form_schema' => ['fields' => [
        ['key' => 'person', 'label' => 'Person', 'type' => 'employee'],
        ['key' => 'private_person', 'label' => 'Private person', 'type' => 'employee', 'visibility' => 'internal'],
        ['key' => 'details', 'label' => 'Details', 'type' => 'text'],
    ]]]);
    $base = "/it/catalog/{$item->id}/fields";
    $input = ['actor_user_id' => $this->worker->id, 'query_uuid' => (string) Str::uuid(), 'schema_version' => 1];
    $this->actingAs($this->worker)->postJson("{$base}/person/options", $input)
        ->assertOk()->assertJsonCount(1, 'options')->assertJsonPath('options.0.id', $this->workerProfile->id);
    foreach (['private_person', 'details', 'missing', 'asset'] as $field) {
        $this->postJson("{$base}/{$field}/options", $input)->assertNotFound();
    }
    $this->postJson("{$base}/person/options", [...$input, 'actor_user_id' => $this->agent->id])->assertForbidden();
    $this->postJson("{$base}/person/options", [...$input, 'schema_version' => 2])->assertConflict();
    $this->postJson("{$base}/person/options", [...$input, 'query' => str_repeat('x', 101)])->assertUnprocessable();
    $this->postJson("{$base}/person/options", [...$input, 'query' => '%'])->assertOk()->assertJsonCount(0, 'options');
    $this->postJson("{$base}/person/options", [...$input, 'selected_id' => $this->agent->hrEmployeeProfile->id])
        ->assertOk()->assertJsonPath('selected', null);
    $this->workerProfile->update(['is_active' => false]);
    $this->postJson("{$base}/person/options", [...$input, 'selected_id' => $this->workerProfile->id])
        ->assertNotFound();
    $this->workerProfile->update(['is_active' => true]);
    $this->postJson("{$base}/person/options", $input)->assertOk()->assertJsonCount(1, 'options');
    $item->update(['is_published' => false]);
    $this->postJson("{$base}/person/options", $input)->assertNotFound();
});

function catalogueCreateCommand(User $actor, ?string $uuid = null): array
{
    return [
        'actor_user_id' => $actor->id, 'request_uuid' => $uuid ?? (string) Str::uuid(),
        'name' => 'Recoverable equipment request', 'description' => 'A reviewed request draft.',
        'outcome_type' => 'provisioning', 'category' => 'hardware', 'provisioning_type' => 'equipment',
        'default_priority' => 'normal', 'requires_approval' => true, 'internal_only' => false,
        'search_terms' => [], 'sort_order' => 0,
        'form_schema' => ['fields' => [[
            'key' => 'equipment', 'label' => 'Equipment', 'type' => 'select',
            'required' => true, 'visibility' => 'requester', 'options' => ['Monitor', 'Headset'],
        ]]],
    ];
}

test('catalogue Site discovery and direct submission use the published audience and current approved access', function () {
    $otherSite = Site::factory()->create();
    $item = ItCatalogItem::factory()->create(['site_scope' => [$otherSite->id], 'form_schema' => catalogSchema()]);
    $input = ['schema_version' => 1, 'idempotency_key' => (string) Str::uuid(),
        'site_id' => $otherSite->id, 'values' => ['details' => 'Site scoped work', 'system_name' => 'VPN']];
    $this->actingAs($this->worker)->getJson('/it/catalog')->assertOk()->assertJsonCount(0, 'data');
    $this->get('/it?tab=catalog')->assertInertia(fn ($page) => $page->has('catalogItems', 0));
    $this->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$input])->assertNotFound();
    expect(ItCatalogSubmission::query()->count())->toBe(0);

    $this->workerProfile->update(['secondary_site_ids' => [$otherSite->id]]);
    $this->getJson('/it/catalog')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.site_options.0.id', $otherSite->id);
    $this->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$input, 'site_id' => $this->site->id])
        ->assertSessionHasErrors('catalog_item');
    $this->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$input])->assertRedirect();
    expect(ItTicket::query()->sole()->site_id)->toBe($otherSite->id)
        ->and(ItCatalogSubmission::query()->sole()->contract_snapshot['site_scope'])->toBe([$otherSite->id]);
    $this->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$input, 'site_id' => $this->site->id])
        ->assertSessionHasErrors('idempotency_key');
    expect(ItTicket::query()->count())->toBe(1);

    $otherSite->update(['is_active' => false]);
    $this->getJson('/it/catalog')->assertOk()->assertJsonCount(0, 'data');
    $this->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->worker->id, ...$input, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
});

test('catalogue Site authoring rejects empty or unavailable scopes and preserves published versions until reviewed', function () {
    $payload = [...catalogueCreateCommand($this->agent), 'site_scope' => []];
    $this->actingAs($this->agent)->postJson('/it/setup/catalogue-items', $payload)->assertUnprocessable()->assertJsonValidationErrors('site_scope');
    $inactive = Site::factory()->create(['is_active' => false]);
    $this->postJson('/it/setup/catalogue-items', [...$payload, 'site_scope' => [$inactive->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('site_scope');
    expect(ItCatalogItem::query()->count())->toBe(0)->and(ItSetupCommandReceipt::query()->count())->toBe(0);
    $payload['site_scope'] = [$this->site->id];
    $this->postJson('/it/setup/catalogue-items', $payload)->assertOk();
    $item = ItCatalogItem::query()->sole();
    $this->post("/it/setup/catalogue-items/{$item->id}/publish", ['expected_version' => $item->lock_version])->assertRedirect();
    $draft = $payload;
    unset($draft['request_uuid']);
    $draft['site_scope'] = null;
    $draft['expected_version'] = $item->fresh()->lock_version;
    $this->patch("/it/setup/catalogue-items/{$item->id}", $draft)->assertRedirect();
    expect($item->fresh()->site_scope)->toBeNull()
        ->and($item->fresh()->publishedContract()->site_scope)->toBe([$this->site->id]);
    $this->post("/it/setup/catalogue-items/{$item->id}/publish", ['expected_version' => $item->fresh()->lock_version])->assertRedirect();
    expect($item->fresh()->publishedContract()->site_scope)->toBeNull()
        ->and(ItCatalogVersion::query()->where('catalog_item_id', $item->id)->where('version', 1)->sole()->contract['site_scope'])->toBe([$this->site->id]);
});

test('site-limited provisioning cannot be requested for an employee at another approved Site', function () {
    $otherSite = Site::factory()->create();
    $agentProfile = HrEmployeeProfile::query()->where('user_id', $this->agent->id)->sole();
    $agentProfile->update(['secondary_site_ids' => [$otherSite->id]]);
    $otherProfile = HrEmployeeProfile::factory()->create(['primary_site_id' => $otherSite->id, 'is_active' => true]);
    $item = ItCatalogItem::factory()->create([
        'site_scope' => [$this->site->id], 'outcome_type' => 'provisioning', 'provisioning_type' => 'equipment',
        'form_schema' => ['fields' => [['key' => 'employee_profile_id', 'label' => 'Requested for', 'type' => 'employee', 'required' => true]]],
    ]);
    $this->actingAs($this->agent)->post("/it/catalog/{$item->id}/submissions", ['actor_user_id' => $this->agent->id,
        'schema_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'values' => ['employee_profile_id' => $otherProfile->id],
    ])->assertSessionHasErrors('values.employee_profile_id');
    expect(ItProvisioningRequest::query()->count())->toBe(0)->and(ItCatalogSubmission::query()->count())->toBe(0);
});

test('catalogue create recovery binds the original payload actor and immutable command outcome', function () {
    $payload = catalogueCreateCommand($this->agent);
    $response = $this->actingAs($this->agent)->postJson('/it/setup/catalogue-items', $payload)
        ->assertOk()->assertJsonPath('status', 'committed')->assertJsonPath('data.replayed', false);
    $id = $response->json('data.id');
    $this->postJson('/it/setup/catalogue-items', $payload)
        ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.replayed', true);
    $binding = ['actor_user_id' => $this->agent->id, 'resource' => 'catalogue-items'];
    $this->postJson("/it/setup/commands/{$payload['request_uuid']}/recover", $binding)
        ->assertOk()->assertJsonPath('data.id', $id);
    $this->postJson("/it/setup/commands/{$payload['request_uuid']}/cancel", $binding)
        ->assertOk()->assertJsonPath('status', 'committed');
    $changed = $payload;
    $changed['form_schema']['fields'][0]['options'] = ['Headset', 'Monitor'];
    $this->postJson('/it/setup/catalogue-items', $changed)->assertConflict();
    expect(ItCatalogItem::query()->count())->toBe(1)
        ->and(ItSetupCommandReceipt::query()->where('resource', 'catalogue-items')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.catalogue.item.created')->count())->toBe(1);
    $this->actingAs($this->worker)->postJson("/it/setup/commands/{$payload['request_uuid']}/recover", $binding)->assertForbidden();
    $otherManager = catalogUser('hr');
    $this->actingAs($otherManager)->postJson('/it/setup/catalogue-items', $payload)->assertForbidden();
    ItCatalogItem::findOrFail($id)->delete();
    $this->actingAs($this->agent)->postJson("/it/setup/commands/{$payload['request_uuid']}/recover", $binding)->assertNotFound();
});

test('catalogue cancellation wins before creation and failed audit leaves a safely retryable command', function () {
    $payload = catalogueCreateCommand($this->agent);
    $binding = ['actor_user_id' => $this->agent->id, 'resource' => 'catalogue-items'];
    $this->actingAs($this->agent)->postJson("/it/setup/commands/{$payload['request_uuid']}/recover", $binding)
        ->assertOk()->assertJsonPath('status', 'not_found');
    $this->postJson("/it/setup/commands/{$payload['request_uuid']}/cancel", $binding)
        ->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson('/it/setup/catalogue-items', $payload)
        ->assertOk()->assertJsonPath('status', 'cancelled');
    expect(ItCatalogItem::query()->count())->toBe(0);

    $retry = catalogueCreateCommand($this->agent);
    $dispatcher = AuditLog::getEventDispatcher();
    AuditLog::setEventDispatcher(clone $dispatcher);
    AuditLog::creating(fn () => throw new RuntimeException('Synthetic audit failure'));
    try {
        $this->postJson('/it/setup/catalogue-items', $retry)->assertStatus(500);
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
    }
    expect(ItCatalogItem::query()->count())->toBe(0)
        ->and(ItSetupCommandReceipt::query()->where('request_uuid', $retry['request_uuid'])->exists())->toBeFalse();
    $this->postJson('/it/setup/catalogue-items', $retry)->assertOk()->assertJsonPath('status', 'committed');
    expect(ItCatalogItem::query()->count())->toBe(1);
});

test('legacy publication without a Site field cannot inherit a newer draft restriction', function () {
    $otherSite = Site::factory()->create();
    $item = ItCatalogItem::factory()->create(['is_published' => false, 'site_scope' => [$otherSite->id]]);
    $legacyContract = $item->only(ItCatalogItem::CONTRACT_FIELDS);
    unset($legacyContract['site_scope']);
    $version = ItCatalogVersion::query()->create([
        'catalog_item_id' => $item->id, 'version' => 1, 'contract' => $legacyContract, 'provenance' => 'legacy_current',
    ]);
    $item->update(['is_published' => true, 'published_version_id' => $version->id]);
    $this->actingAs($this->worker)->getJson('/it/catalog')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.site_options.0.id', $this->site->id);
    expect($item->fresh()->publishedContract()->site_scope)->toBeNull()
        ->and($item->fresh()->site_scope)->toBe([$otherSite->id])
        ->and(array_key_exists('site_scope', $version->fresh()->contract))->toBeFalse();
});

test('publication rechecks Site availability instead of releasing a stale audience', function () {
    $payload = [...catalogueCreateCommand($this->agent), 'site_scope' => [$this->site->id]];
    $this->actingAs($this->agent)->postJson('/it/setup/catalogue-items', $payload)->assertOk();
    $item = ItCatalogItem::query()->sole();
    $this->site->update(['is_active' => false]);
    $this->post("/it/setup/catalogue-items/{$item->id}/publish", ['expected_version' => $item->lock_version])
        ->assertNotFound();
    expect($item->fresh()->is_published)->toBeFalse()
        ->and(ItCatalogVersion::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'it.catalogue.item.published')->count())->toBe(0);
});
