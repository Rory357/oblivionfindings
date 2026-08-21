<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Support\EmarUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->seed(\Database\Seeders\RbacSeeder::class);

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
}
