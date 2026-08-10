<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AuditLog;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItProvisioningRequest;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketCreatedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

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
        ->post("/it/catalog/{$item->id}/submissions", [
            'schema_version' => 2,
            'idempotency_key' => 'stale-form',
            'values' => ['details' => 'Please help', 'system_name' => 'VPN'],
        ])
        ->assertSessionHasErrors('schema_version');

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", [
            'schema_version' => 3,
            'idempotency_key' => 'missing-required',
            'values' => ['details' => 'Please help'],
        ])
        ->assertSessionHasErrors('values.system_name');

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", [
            'schema_version' => 3,
            'idempotency_key' => 'internal-injection',
            'values' => [
                'details' => 'Please help',
                'system_name' => 'VPN',
                'fulfilment_note' => 'Grant global admin',
            ],
        ])
        ->assertSessionHasErrors('values.fulfilment_note');

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", [
            'schema_version' => 3,
            'idempotency_key' => 'invalid-number-range',
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
        'idempotency_key' => 'vpn-request-001',
        'values' => ['details' => 'Need access for the on-call shift', 'system_name' => 'VPN'],
    ];

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", $payload)
        ->assertRedirect()
        ->assertSessionHas('it_catalog_submission');
    $this->actingAs($this->worker)
        ->post("/it/catalog/{$item->id}/submissions", $payload)
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
        ->post("/it/catalog/{$item->id}/submissions", [
            'schema_version' => 1,
            'idempotency_key' => 'security-request-001',
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
        ->post("/it/catalog/{$item->id}/submissions", [
            'schema_version' => 1,
            'idempotency_key' => 'laptop-request-001',
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
        ->and($provisioning->events()->where('type', 'created')->count())->toBe(1)
        ->and($submission->result->is($provisioning))->toBeTrue();
});

test('a requester cannot submit an unpublished or internal-only catalogue item', function () {
    $draft = ItCatalogItem::factory()->unpublished()->create();
    $internal = ItCatalogItem::factory()->create(['internal_only' => true]);
    $payload = [
        'schema_version' => 1,
        'idempotency_key' => 'blocked',
        'values' => [],
    ];

    $this->actingAs($this->worker)
        ->post("/it/catalog/{$draft->id}/submissions", $payload)
        ->assertNotFound();
    $this->actingAs($this->worker)
        ->post("/it/catalog/{$internal->id}/submissions", $payload)
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
        ->post("/it/setup/catalogue-items/{$item->id}/publish")
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
        ->patch("/it/setup/catalogue-items/{$item->id}", $payload)
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
        ])
        ->assertRedirect();
    expect($item->fresh()->is_published)->toBeFalse()
        ->and(AuditLog::query()
            ->where('action', 'it.catalogue.item.unpublished')
            ->where('meta->reason', 'The equipment catalogue is being replaced.')
            ->count())->toBe(1);
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
        ->post("/it/catalog/{$item->id}/submissions", [
            'schema_version' => 1,
            'idempotency_key' => 'forged-entity-options',
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
        ->post("/it/catalog/{$item->id}/submissions", [
            'schema_version' => 1,
            'idempotency_key' => 'canonical-entity-options',
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
