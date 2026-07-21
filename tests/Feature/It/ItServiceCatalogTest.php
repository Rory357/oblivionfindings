<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItProvisioningRequest;
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
        'tenant_id' => 1,
        'name' => 'Request Microsoft 365 access',
        'slug' => 'request-microsoft-365-access',
        'sort_order' => 10,
        'form_schema' => catalogSchema(),
    ]);
    ItCatalogItem::factory()->unpublished()->create(['tenant_id' => 1, 'name' => 'Draft request']);
    ItCatalogItem::factory()->create([
        'tenant_id' => 1,
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
    $item = ItCatalogItem::factory()->create([
        'form_schema_version' => 3,
        'form_schema' => catalogSchema(),
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
    $draft = ItCatalogItem::factory()->unpublished()->create(['tenant_id' => 1]);
    $internal = ItCatalogItem::factory()->create(['tenant_id' => 1, 'internal_only' => true]);
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
