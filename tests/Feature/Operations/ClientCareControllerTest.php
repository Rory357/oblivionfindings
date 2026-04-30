<?php

namespace Tests\Feature\Operations;

use App\Models\Client;
use App\Models\ClientCondition;
use App\Models\ClientEmergencyContact;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientRisk;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\EnhancedMarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class ClientCareControllerTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ServiceContext $serviceContext;

    private Client $client;

    private User $worker;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.worker_timezone' => 'Pacific/Auckland']);

        $this->site = Site::factory()->create(['name' => 'Care Readiness House']);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Care Readiness',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'organization_id' => 1,
            'first_name' => 'Aroha',
            'last_name' => 'Brown',
            'preferred_name' => 'Ari',
            'risk_level' => 'high',
        ]);

        $this->worker = $this->userWithPermissions([
            'clients.viewAssigned',
            'medications.administer.record',
            'medications.view',
            'shifts.viewAssigned',
        ], [
            'role' => 'support_worker',
            'organization_id' => 1,
        ]);
        $this->attachRole($this->worker, 'support_worker');
        $this->client->supportWorkers()->syncWithoutDetaching([$this->worker->id]);

        $this->manager = $this->userWithPermissions([
            'clients.viewAny',
            'clients.update',
            'medications.administer.record',
            'medications.view',
            'shifts.viewAny',
            'shifts.manageAny',
            'reports.viewAny',
        ], [
            'role' => 'admin',
            'organization_id' => 1,
        ]);
        $this->attachRole($this->manager, 'admin');
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_show_returns_the_care_page_for_an_assigned_support_worker(): void
    {
        $this->actingAs($this->worker)
            ->get(route('operations.clients.care', $this->client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/clients/care')
                ->where('client.id', $this->client->id)
                ->has('safety'));
    }

    public function test_show_returns_the_care_page_for_a_manager_with_clients_view_any(): void
    {
        $this->actingAs($this->manager)
            ->get(route('operations.clients.care', $this->client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/clients/care')
                ->where('client.id', $this->client->id)
                ->where('can.view_followups', true));
    }

    public function test_show_403s_for_user_without_view_permission_for_client(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->get(route('operations.clients.care', $this->client))
            ->assertForbidden();
    }

    public function test_show_payload_includes_care_readiness_keys(): void
    {
        ClientMedicalProfile::create([
            'client_id' => $this->client->id,
            'allergies' => ['penicillin'],
            'notes' => 'Use calm, direct prompts.',
        ]);
        ClientCondition::create([
            'client_id' => $this->client->id,
            'label' => 'Epilepsy',
            'severity' => 'high',
            'notes' => 'Seizure plan is current.',
        ]);
        ClientRisk::create([
            'client_id' => $this->client->id,
            'label' => 'Falls risk',
            'severity' => 'critical',
            'controls' => 'Use transfer belt.',
            'review_date' => now()->addMonth(),
            'active' => true,
        ]);
        ClientEmergencyContact::create([
            'client_id' => $this->client->id,
            'name' => 'Mere Brown',
            'relationship' => 'Mother',
            'phone' => '021 000 000',
            'contact_order' => 1,
        ]);
        $prn = $this->createPrnMedication($this->client, [
            'name' => 'Paracetamol PRN',
            'prn_reason' => 'Pain|Headache',
        ]);

        $this->actingAs($this->worker)
            ->get(route('operations.clients.care', $this->client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/clients/care')
                ->where('safety.has_any', true)
                ->where('safety.allergies.0.label', 'Penicillin')
                ->where('conditions.0.label', 'Epilepsy')
                ->where('active_risks.0.label', 'Falls risk')
                ->where('prn_medications.0.id', $prn->id)
                ->where('emergency_contacts.0.name', 'Mere Brown')
                ->where('can.record_prn', true)
                ->where('can.view_medical', true)
                ->where('can.view_followups', false)
                ->where('links.full_profile', route('operations.clients.show', $this->client))
                ->where('links.medical', route('operations.clients.medical.show', $this->client))
                ->where('links.risks', route('operations.clients.risks.index', $this->client))
                ->where('links.mar', route('operations.clients.mar.show', $this->client)));
    }

    public function test_record_prn_happy_path_records_administration_with_active_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-04-30 10:00:00', 'Pacific/Auckland'));

        $medication = $this->createPrnMedication($this->client);
        $shift = $this->createOpenShift([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(5),
            'actual_starts_at' => now()->subHour(),
        ]);

        $this->actingAs($this->worker)
            ->from(route('operations.clients.care', $this->client))
            ->post(route('operations.clients.care.prn', $this->client), [
                'client_medication_id' => $medication->id,
                'reason' => 'Pain',
                'dose_given' => '500mg',
                'notes' => 'Settled after fluids.',
            ])
            ->assertRedirect(route('operations.clients.care', $this->client))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_id' => $this->client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $this->worker->id,
            'shift_id' => $shift->id,
            'status' => 'given',
            'reason' => 'Pain',
            'dose_given' => '500mg',
        ]);
    }

    public function test_record_prn_validation_missing_reason_returns_422(): void
    {
        $medication = $this->createPrnMedication($this->client);

        $this->actingAs($this->worker)
            ->postJson(route('operations.clients.care.prn', $this->client), [
                'client_medication_id' => $medication->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_record_prn_refuses_medication_from_a_different_client(): void
    {
        $otherClient = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'organization_id' => 1,
        ]);
        $medication = $this->createPrnMedication($otherClient);

        $this->actingAs($this->worker)
            ->postJson(route('operations.clients.care.prn', $this->client), [
                'client_medication_id' => $medication->id,
                'reason' => 'Pain',
            ])
            ->assertNotFound();
    }

    public function test_record_prn_refuses_non_prn_medication(): void
    {
        $medication = $this->createScheduledMedication($this->client);

        $this->actingAs($this->worker)
            ->postJson(route('operations.clients.care.prn', $this->client), [
                'client_medication_id' => $medication->id,
                'reason' => 'Pain',
            ])
            ->assertStatus(422);
    }

    public function test_record_prn_appends_no_active_shift_marker_without_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-04-30 10:00:00', 'Pacific/Auckland'));

        $medication = $this->createPrnMedication($this->client);

        $this->actingAs($this->worker)
            ->from(route('operations.clients.care', $this->client))
            ->post(route('operations.clients.care.prn', $this->client), [
                'client_medication_id' => $medication->id,
                'reason' => 'Pain',
                'notes' => 'Covering during appointment.',
            ])
            ->assertRedirect(route('operations.clients.care', $this->client))
            ->assertSessionHas('success');

        $administration = ClientMedicationAdministration::query()->latest('id')->firstOrFail();

        $this->assertNull($administration->shift_id);
        $this->assertStringContainsString('[PRN from client page', $administration->notes);
        $this->assertStringContainsString('no active shift', $administration->notes);
        $this->assertStringContainsString('Covering during appointment.', $administration->notes);
    }

    public function test_record_prn_maps_service_failure_to_client_medication_id(): void
    {
        $medication = $this->createPrnMedication($this->client);

        $mock = Mockery::mock(EnhancedMarService::class);
        $mock->shouldReceive('recordAdministration')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'PRN limit reached',
            ]);
        $this->app->instance(EnhancedMarService::class, $mock);

        $this->actingAs($this->worker)
            ->from(route('operations.clients.care', $this->client))
            ->post(route('operations.clients.care.prn', $this->client), [
                'client_medication_id' => $medication->id,
                'reason' => 'Pain',
            ])
            ->assertRedirect(route('operations.clients.care', $this->client))
            ->assertSessionHasErrors(['client_medication_id' => 'PRN limit reached'])
            ->assertSessionDoesntHaveErrors('reason');
    }

    public function test_record_prn_does_not_link_stale_open_shift_but_links_current_overnight_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-04-30 06:00:00', 'Pacific/Auckland'));

        $medication = $this->createPrnMedication($this->client);
        $this->createOpenShift([
            'starts_at' => now()->subDays(2)->setTime(9, 0),
            'ends_at' => now()->subDays(2)->setTime(17, 0),
            'actual_starts_at' => now()->subDays(2)->setTime(9, 0),
        ]);
        $overnight = $this->createOpenShift([
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHours(8),
            'shift_type' => 'sleepover',
            'is_sleepover' => true,
        ]);

        $this->actingAs($this->worker)
            ->post(route('operations.clients.care.prn', $this->client), [
                'client_medication_id' => $medication->id,
                'reason' => 'Pain',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $medication->id,
            'shift_id' => $overnight->id,
            'reason' => 'Pain',
        ]);

        $overnight->update(['actual_ends_at' => now()->subMinute(), 'status' => 'completed']);

        $this->actingAs($this->worker)
            ->post(route('operations.clients.care.prn', $this->client), [
                'client_medication_id' => $medication->id,
                'reason' => 'Headache',
            ])
            ->assertSessionHas('success');

        $secondAdministration = ClientMedicationAdministration::query()
            ->where('reason', 'Headache')
            ->latest('id')
            ->firstOrFail();

        $this->assertNull($secondAdministration->shift_id);
        $this->assertStringContainsString('no active shift', $secondAdministration->notes);
    }

    public function test_shift_show_payload_includes_client_safety_and_care_link(): void
    {
        ClientMedicalProfile::create([
            'client_id' => $this->client->id,
            'allergies' => ['penicillin'],
        ]);
        ClientRisk::create([
            'client_id' => $this->client->id,
            'label' => 'Manual handling risk',
            'severity' => 'high',
            'controls' => 'Use hoist.',
            'active' => true,
        ]);

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(5),
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->get(route('operations.shifts.show', $shift))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/shifts/show')
                ->where('client_safety.has_any', true)
                ->where('client_safety.allergies.0.label', 'Penicillin')
                ->where('client_safety.critical_risks.0.label', 'Manual handling risk')
                ->where('links.client_care', route('operations.clients.care', $this->client)));
    }

    private function createPrnMedication(Client $client, array $overrides = []): ClientMedication
    {
        return ClientMedication::factory()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Paracetamol PRN',
            'dosage' => '500mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'prn_reason' => 'Pain',
            'max_per_day' => null,
            'min_hours_between_doses' => null,
            'route' => 'oral',
            'form' => 'tablet',
            'active' => true,
            'state' => 'active',
            'version' => 1,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ], $overrides));
    }

    private function createScheduledMedication(Client $client, array $overrides = []): ClientMedication
    {
        return ClientMedication::factory()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Daily Tablet',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => ['09:00'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'version' => 1,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ], $overrides));
    }

    private function createOpenShift(array $overrides = []): Shift
    {
        $startsAt = $overrides['starts_at'] ?? now()->subHour();

        return Shift::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => $startsAt,
            'ends_at' => now()->addHours(4),
            'actual_starts_at' => $startsAt,
            'actual_ends_at' => null,
            'status' => 'in_progress',
            'created_by' => $this->manager->id,
            'started_by' => $this->worker->id,
        ], $overrides));
    }

    private function userWithPermissions(array $permissionKeys, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'approved_at' => now(),
            'organization_id' => 1,
        ], $attributes));

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                [
                    'description' => $key,
                    'group' => str($key)->before('.')->value() ?: 'operations',
                    'module' => str($key)->before('.')->value() ?: 'operations',
                ],
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }

    private function attachRole(User $user, string $roleName): void
    {
        $role = Role::firstOrCreate(
            ['name' => $roleName],
            [
                'label' => ucfirst(str_replace('_', ' ', $roleName)),
                'level' => $roleName === 'admin' ? 100 : 40,
                'type' => 'system',
            ],
        );

        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}
