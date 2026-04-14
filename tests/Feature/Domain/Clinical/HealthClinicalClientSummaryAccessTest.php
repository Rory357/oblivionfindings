<?php

namespace Tests\Feature\Domain\Clinical;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HealthClinicalClientSummaryAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_client_summary_enforces_assignment_for_assigned_only_roles(): void
    {
        $clinicalLead = $this->createUserWithRole('clinical_lead');
        $assignedSupportWorker = $this->createUserWithRole('support_worker');
        $unassignedSupportWorker = $this->createUserWithRole('support_worker');
        $client = Client::factory()->create();

        $client->supportWorkers()->attach($assignedSupportWorker->id);

        $this->actingAs($clinicalLead)
            ->get('/health-clinical/clients/' . $client->id . '/summary')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/ClientSummary')
                ->where('client.id', $client->id)
            );

        $this->actingAs($assignedSupportWorker)
            ->get('/health-clinical/clients/' . $client->id . '/summary')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/ClientSummary')
                ->where('client.id', $client->id)
            );

        $this->actingAs($unassignedSupportWorker)
            ->get('/health-clinical/clients/' . $client->id . '/summary')
            ->assertForbidden();
    }

    protected function createUserWithRole(string $roleName): User
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
}
