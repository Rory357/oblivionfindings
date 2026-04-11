<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_client_medical_page_renders_with_permission_override_witnesses(): void
    {
        $allowedWitness = $this->makeRoleUser('support_worker');
        $this->createEmployeeProfile($allowedWitness);
        $this->grantPermissions($allowedWitness, ['medications.controlled.witness']);

        $deniedWitness = $this->makeRoleUser('support_worker');
        $this->createEmployeeProfile($deniedWitness);
        $this->grantPermissionOverride($deniedWitness, 'medications.controlled.witness', false);

        $response = $this->actingAs($this->viewer)
            ->get(route('clients.medical.show', $this->client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/clients/medical')
                ->where('client.id', $this->client->id)
                ->has('witnesses')
            );

        $witnessIds = collect($response->viewData('page')['props']['witnesses'] ?? [])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($allowedWitness->id, $witnessIds);
        $this->assertNotContains($deniedWitness->id, $witnessIds);
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

    protected function createEmployeeProfile(User $user): void
    {
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
                'primary_site_id' => $this->site->id,
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
