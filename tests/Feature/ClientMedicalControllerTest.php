<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\ClientMedicalController;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Support\EmarUrl;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class ClientMedicalControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;

    protected Site $site;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->site = Site::factory()->create([
            'name' => 'Kowhai House',
            'type' => 'house',
        ]);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Residential Support',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);

        $this->viewer = $this->makeRoleUser('admin');
        $this->createEmployeeProfile($this->viewer);
    }

    public function test_client_medical_page_redirects_to_canonical_emar_medications_page(): void
    {
        $this->actingAs($this->viewer)
            ->get(route('clients.medical.show', $this->client))
            ->assertRedirect(EmarUrl::medications($this->client));
    }

    public function test_client_medical_stock_endpoint_rejects_controlled_medication_without_effects(): void
    {
        $medication = ClientMedication::create([
            'client_id' => $this->client->id,
            'name' => 'Controlled client-medical stock',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($this->viewer)
            ->put(route('clients.medical.medications.stock.update', [$this->client, $medication]), [
                'on_hand' => 9.5,
                'reason' => 'Direct count probe',
            ])
            ->assertSessionHasErrors('on_hand');

        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.stock.client_medical.update']);
    }

    public function test_client_medical_stock_conceals_controlled_targets_before_validation_without_exact_authority(): void
    {
        $actor = $this->makeRoleUser('support_worker');
        $this->createEmployeeProfile($actor);
        $this->grantPermissionOverride($actor, 'medications.stock.update', true);
        $this->grantPermissionOverride($actor, 'medications.controlled.view', false);
        $this->grantPermissionOverride($actor, 'medications.controlled.record', false);
        $ordinaryMedication = ClientMedication::create([
            'client_id' => $this->client->id,
            'name' => 'Ordinary direct stock target',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $controlledMedication = ClientMedication::create([
            'client_id' => $this->client->id,
            'name' => 'Hidden controlled direct stock target',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $controlledStock = ClientMedicationStock::create([
            'client_medication_id' => $controlledMedication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        foreach ([['on_hand' => 'invalid'], ['on_hand' => 9]] as $payload) {
            $this->actingAs($actor)
                ->put(route('clients.medical.medications.stock.update', [$this->client, $controlledMedication]), $payload)
                ->assertNotFound();
        }
        $this->actingAs($actor)
            ->put(route('clients.medical.medications.stock.update', [$this->client, 999999]), ['on_hand' => 'invalid'])
            ->assertNotFound();

        $this->actingAs($actor)
            ->put(route('clients.medical.medications.stock.update', [$this->client, $ordinaryMedication]), ['on_hand' => 'invalid'])
            ->assertSessionHasErrors('on_hand');

        $this->assertSame(10.0, (float) $controlledStock->refresh()->on_hand);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.stock.client_medical.update']);

        $this->grantPermissionOverride($actor, 'medications.controlled.record', true);
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($actor)
            ->put(route('clients.medical.medications.stock.update', [$this->client, $controlledMedication]), ['on_hand' => 'invalid'])
            ->assertNotFound();

        $this->grantPermissionOverride($actor, 'medications.controlled.view', true);
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($actor)
            ->put(route('clients.medical.medications.stock.update', [$this->client, $controlledMedication]), ['on_hand' => 'invalid'])
            ->assertSessionHasErrors([
                'on_hand' => 'Controlled drug stock counts must be recorded through the controlled-drug balance check with a second witness.',
            ]);
        $this->assertSame(10.0, (float) $controlledStock->refresh()->on_hand);
    }

    public function test_client_medical_stock_requires_exact_capability_and_conceals_foreign_site_ids(): void
    {
        $actor = $this->makeRoleUser('support_worker');
        $this->createEmployeeProfile($actor);
        $this->grantPermissionOverride($actor, 'clients.update', true);
        $this->grantPermissionOverride($actor, 'medications.stock.update', false);
        $localMedication = ClientMedication::create([
            'client_id' => $this->client->id,
            'name' => 'Local stock',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        $this->actingAs($actor)
            ->put(route('clients.medical.medications.stock.update', [$this->client, $localMedication]), [
                'on_hand' => 9.5,
                'reason' => 'Generic permission probe',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('client_medication_stocks', ['client_medication_id' => $localMedication->id]);
        $this->grantPermissionOverride($actor, 'medications.stock.update', true);
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');

        $foreignSite = Site::factory()->create(['type' => 'house']);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id, 'status' => 'active']);
        $foreignMedication = ClientMedication::create([
            'client_id' => $foreignClient->id,
            'name' => 'Foreign stock',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $foreignStock = ClientMedicationStock::create([
            'client_medication_id' => $foreignMedication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($actor)
            ->put(route('clients.medical.medications.stock.update', [$foreignClient, $foreignMedication]), [
                'on_hand' => 9.5,
                'reason' => 'Foreign direct-ID probe',
            ])
            ->assertNotFound();

        $this->assertSame(10.0, (float) $foreignStock->refresh()->on_hand);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.stock.client_medical.update']);
    }

    public function test_client_medical_stock_strict_audit_failure_rolls_back_domain_and_audit_state(): void
    {
        $medication = ClientMedication::create([
            'client_id' => $this->client->id,
            'name' => 'Non-controlled client-medical stock',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $audit) use (&$injectFailure): void {
            if ($injectFailure && $audit->action === 'medications.stock.client_medical.update') {
                throw new RuntimeException('Injected client-medical stock audit failure.');
            }
        });

        try {
            $this->actingAs($this->viewer)
                ->from(EmarUrl::medications($this->client))
                ->put(route('clients.medical.medications.stock.update', [$this->client, $medication]), [
                    'on_hand' => 9.5,
                    'reason' => 'Audited physical count',
                ])
                ->assertSessionHas('error');
        } finally {
            $injectFailure = false;
        }

        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.stock.client_medical.update']);
    }

    public function test_client_medical_administration_conceals_controlled_foreign_and_same_site_unassigned_targets_before_validation(): void
    {
        $actor = $this->makeRoleUser('support_worker');
        $this->createEmployeeProfile($actor);
        $this->grantPermissionOverride($actor, 'medications.administer.record', true);
        $this->grantPermissionOverride($actor, 'medications.controlled.record', false);
        $ordinary = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Ordinary direct administration target',
            'is_prn' => true,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $actor->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $actor->id,
            'status' => 'in_progress',
        ]);
        $sameSiteUnassignedClient = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id,
            'status' => 'active',
        ]);
        $sameSiteUnassignedMedication = ClientMedication::query()->create([
            'client_id' => $sameSiteUnassignedClient->id,
            'name' => 'Same-Site unassigned administration target',
            'is_prn' => true,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $controlled = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Hidden controlled administration target',
            'is_prn' => true,
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $foreignSite = Site::factory()->create(['type' => 'house']);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'status' => 'active',
        ]);
        $foreignMedication = ClientMedication::query()->create([
            'client_id' => $foreignClient->id,
            'name' => 'Foreign administration target',
            'is_prn' => true,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        foreach ([
            'clients.medical.medications.administrations.store',
            'operations.clients.medical.medications.administrations.store',
        ] as $routeName) {
            $this->actingAs($actor)
                ->post(route($routeName, [$this->client, $controlled]), ['status' => 'invalid'])
                ->assertNotFound();
            $this->actingAs($actor)
                ->post(route($routeName, [$this->client, 999999]), ['status' => 'invalid'])
                ->assertNotFound();
            $this->actingAs($actor)
                ->post(route($routeName, [$this->client, $foreignMedication]), ['status' => 'invalid'])
                ->assertNotFound();
            $this->actingAs($actor)
                ->post(route($routeName, [$sameSiteUnassignedClient, $sameSiteUnassignedMedication]), ['status' => 'invalid'])
                ->assertNotFound();
            $this->actingAs($actor)
                ->post(route($routeName, [$this->client, $ordinary]), ['status' => 'invalid'])
                ->assertSessionHasErrors('status');
        }

        $this->assertSame(0, ClientMedicationAdministration::query()->count());
    }

    public function test_client_medical_offline_validation_uses_the_historical_capture_time_for_the_concealment_probe(): void
    {
        $actor = $this->makeRoleUser('support_worker');
        $this->createEmployeeProfile($actor);
        $this->grantPermissionOverride($actor, 'medications.administer.record', true);
        $medication = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Historical offline administration target',
            'is_prn' => true,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $capturedAt = now()->subMinutes(30);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $actor->id,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subMinutes(20),
            'actual_starts_at' => now()->subHours(2),
            'actual_ends_at' => now()->subMinutes(20),
            'started_by' => $actor->id,
            'completed_by' => $actor->id,
            'status' => 'completed',
        ]);

        $this->actingAs($actor)
            ->post(route('clients.medical.medications.administrations.store', [$this->client, $medication]), [
                'status' => 'invalid',
                'client_request_uuid' => '5fbfc934-b8b5-4c3f-a165-76108286465f',
                'captured_offline_at' => $capturedAt->toIso8601String(),
                'origin_device_id' => 'historical-client-medical-device',
                'queued_offline' => true,
            ])
            ->assertSessionHasErrors('status');

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_client_medical_order_update_conceals_controlled_targets_and_prohibits_reclassification(): void
    {
        $actor = $this->makeRoleUser('support_worker');
        $this->createEmployeeProfile($actor);
        $this->grantPermissionOverride($actor, 'clients.update', true);
        $this->grantPermissionOverride($actor, 'medications.orders.manage', true);
        $this->grantPermissionOverride($actor, 'medications.controlled.view', false);
        $this->grantPermissionOverride($actor, 'medications.controlled.record', false);
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $actor->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);
        $ordinary = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Ordinary immutable classification',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $controlled = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Controlled immutable classification',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);

        foreach ([
            'clients.medical.medications.update',
            'operations.clients.medical.medications.update',
        ] as $routeName) {
            $this->actingAs($actor)
                ->put(route($routeName, [$this->client, $controlled]), ['name' => ''])
                ->assertNotFound();
            $this->actingAs($actor)
                ->put(route($routeName, [$this->client, 999999]), ['name' => ''])
                ->assertNotFound();
            $this->actingAs($actor)
                ->put(route($routeName, [$this->client, $ordinary]), ['name' => ''])
                ->assertSessionHasErrors('name');
            $this->actingAs($actor)
                ->put(route($routeName, [$this->client, $ordinary]), [
                    'name' => $ordinary->name,
                    'controlled_drug' => true,
                ])
                ->assertNotFound();
        }

        $this->grantPermissionOverride($actor, 'medications.controlled.record', true);
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($actor)
            ->put(route('clients.medical.medications.update', [$this->client, $controlled]), [
                'name' => $controlled->name,
                'controlled_drug' => false,
            ])
            ->assertNotFound();

        $this->grantPermissionOverride($actor, 'medications.controlled.view', true);
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($actor)
            ->put(route('clients.medical.medications.update', [$this->client, $ordinary]), [
                'name' => $ordinary->name,
                'controlled_drug' => true,
            ])
            ->assertSessionHasErrors('controlled_drug');
        $this->actingAs($actor)
            ->put(route('clients.medical.medications.update', [$this->client, $controlled]), [
                'name' => $controlled->name,
                'controlled_drug' => false,
            ])
            ->assertSessionHasErrors('controlled_drug');

        $this->assertFalse((bool) $ordinary->refresh()->controlled_drug);
        $this->assertTrue((bool) $controlled->refresh()->controlled_drug);
    }

    public function test_manual_medication_classification_requires_both_controlled_capabilities(): void
    {
        $actor = $this->makeRoleUser('support_worker');
        $this->createEmployeeProfile($actor);
        $this->grantPermissionOverride($actor, 'clients.update', true);
        $this->grantPermissionOverride($actor, 'medications.orders.manage', true);
        $this->grantPermissionOverride($actor, 'medications.controlled.view', false);
        $this->grantPermissionOverride($actor, 'medications.controlled.record', false);
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $actor->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);
        $routes = [
            'clients.medical.medications.store',
            'operations.clients.medical.medications.store',
        ];

        foreach ([[false, false], [true, false], [false, true]] as [$canView, $canRecord]) {
            $this->grantPermissionOverride($actor, 'medications.controlled.view', $canView);
            $this->grantPermissionOverride($actor, 'medications.controlled.record', $canRecord);
            $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');

            foreach ($routes as $routeName) {
                $ordinaryName = sprintf(
                    'Ordinary manual medication %s %d %d',
                    $routeName,
                    (int) $canView,
                    (int) $canRecord,
                );
                $this->actingAs($actor)
                    ->post(route($routeName, $this->client), [
                        'name' => $ordinaryName,
                        'controlled_drug' => false,
                    ])
                    ->assertRedirect();
                $this->assertDatabaseHas('client_medications', [
                    'client_id' => $this->client->id,
                    'name' => $ordinaryName,
                    'controlled_drug' => false,
                ]);

                $this->actingAs($actor)
                    ->post(route($routeName, $this->client), [
                        'name' => 'Untrusted controlled classifier',
                        'controlled_drug' => true,
                    ])
                    ->assertNotFound();
            }
        }

        $this->assertDatabaseMissing('client_medications', [
            'client_id' => $this->client->id,
            'name' => 'Untrusted controlled classifier',
        ]);

        $this->grantPermissionOverride($actor, 'medications.controlled.view', true);
        $this->grantPermissionOverride($actor, 'medications.controlled.record', true);
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');

        foreach ([
            ['clients.medical.medications.store', 'Privileged ordinary classifier', false],
            ['operations.clients.medical.medications.store', 'Privileged controlled classifier', true],
        ] as [$routeName, $name, $controlled]) {
            $this->actingAs($actor)
                ->post(route($routeName, $this->client), [
                    'name' => $name,
                    'controlled_drug' => $controlled,
                ])
                ->assertRedirect();
            $this->assertDatabaseHas('client_medications', [
                'client_id' => $this->client->id,
                'name' => $name,
                'controlled_drug' => $controlled,
            ]);
        }
    }

    public function test_medication_notifications_require_local_site_and_the_exact_event_and_controlled_capabilities(): void
    {
        $actor = $this->viewer;
        $localOrderReader = $this->notificationRecipient($this->site, [
            'medications.orders.manage' => true,
            'medications.controlled.view' => true,
        ]);
        $missingControlledView = $this->notificationRecipient($this->site, [
            'medications.orders.manage' => true,
            'medications.controlled.view' => false,
        ]);
        $ordinaryAssignee = $this->notificationRecipient($this->site, [
            'medications.orders.manage' => false,
            'medications.controlled.view' => false,
        ]);
        $this->client->supportWorkers()->syncWithoutDetaching([$ordinaryAssignee->id]);
        $foreignSite = Site::factory()->create(['name' => 'Foreign notification Site']);
        $globalManager = $this->makeRoleUser('provider_manager');
        $this->createEmployeeProfile($globalManager, $foreignSite);
        foreach ([
            'clinical.accessAllSites' => false,
            'sites.viewAll' => false,
            'medications.orders.manage' => true,
            'medications.controlled.view' => true,
        ] as $permission => $allowed) {
            $this->grantPermissionOverride($globalManager, $permission, $allowed);
        }

        Notification::fake();
        $this->invokeMedicationNotification(
            $actor,
            'medications.orders.manage',
            true,
            false,
            'controlled_order.changed',
        );

        Notification::assertSentTo($localOrderReader, AppEventNotification::class, fn (AppEventNotification $notification): bool => $notification->payload['event_key'] === 'controlled_order.changed');
        Notification::assertNotSentTo($missingControlledView, AppEventNotification::class);
        Notification::assertNotSentTo($ordinaryAssignee, AppEventNotification::class);
        Notification::assertNotSentTo($globalManager, AppEventNotification::class);

        $controlledRecorder = $this->notificationRecipient($this->site, [
            'medications.administer.record' => true,
            'medications.controlled.view' => true,
            'medications.controlled.record' => true,
        ]);
        $missingControlledRecord = $this->notificationRecipient($this->site, [
            'medications.administer.record' => true,
            'medications.controlled.view' => true,
            'medications.controlled.record' => false,
        ]);

        Notification::fake();
        $this->invokeMedicationNotification(
            $actor,
            'medications.administer.record',
            true,
            true,
            'controlled_administration.created',
        );

        Notification::assertSentTo($controlledRecorder, AppEventNotification::class);
        Notification::assertNotSentTo($missingControlledRecord, AppEventNotification::class);
        Notification::assertNotSentTo($localOrderReader, AppEventNotification::class);
        Notification::assertNotSentTo($globalManager, AppEventNotification::class);

        $ordinaryStockRecipient = $this->notificationRecipient($this->site, [
            'medications.stock.update' => true,
            'medications.controlled.view' => false,
            'medications.controlled.record' => false,
        ]);

        Notification::fake();
        $this->invokeMedicationNotification(
            $actor,
            'medications.stock.update',
            false,
            true,
            'ordinary_stock.updated',
        );

        Notification::assertSentTo($ordinaryStockRecipient, AppEventNotification::class);
        Notification::assertNotSentTo($ordinaryAssignee, AppEventNotification::class);
        Notification::assertNotSentTo($globalManager, AppEventNotification::class);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    protected function createEmployeeProfile(User $user, ?Site $site = null): void
    {
        $site ??= $this->site;

        HrEmployeeProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => 1,
                'employee_number' => 'EMP-MED-'.$user->id,
                'work_email' => $user->email,
                'position_title' => 'Operations',
                'position_role' => $user->role,
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
            ],
        );
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }

    protected function grantPermissionOverride(User $user, string $permissionKey, bool $allowed): void
    {
        $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();

        $user->permissionOverrides()->sync([
            $permission->id => ['allowed' => $allowed],
        ], false);
    }

    /** @param array<string, bool> $permissions */
    private function notificationRecipient(Site $site, array $permissions): User
    {
        $recipient = $this->makeRoleUser('support_worker');
        $this->createEmployeeProfile($recipient, $site);
        foreach ([
            'medications.orders.manage',
            'medications.administer.record',
            'medications.stock.update',
            'medications.controlled.view',
            'medications.controlled.record',
        ] as $permission) {
            $this->grantPermissionOverride($recipient, $permission, false);
        }
        foreach ($permissions as $permission => $allowed) {
            $this->grantPermissionOverride($recipient, $permission, $allowed);
        }
        $recipient->unsetRelation('permissionOverrides')->unsetRelation('roles');

        return $recipient;
    }

    private function invokeMedicationNotification(
        User $actor,
        string $capability,
        bool $controlled,
        bool $controlledRecordRequired,
        string $eventKey,
    ): void {
        $method = new ReflectionMethod(ClientMedicalController::class, 'notifyMedicationEvent');
        $method->setAccessible(true);
        $method->invoke(
            app(ClientMedicalController::class),
            $actor,
            $this->client,
            $capability,
            $controlled,
            $controlledRecordRequired,
            [
                'action' => 'updated',
                'entity' => 'medication evidence',
                'entity_id' => 987,
                'event_key' => $eventKey,
                'title' => 'Sensitive medication event',
                'url' => url("/clients/{$this->client->id}/medical"),
            ],
        );
    }
}
