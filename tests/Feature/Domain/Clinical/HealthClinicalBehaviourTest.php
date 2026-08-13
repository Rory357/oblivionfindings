<?php

namespace Tests\Feature\Domain\Clinical;

use App\Models\BehaviourAbcEntry;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HealthClinicalBehaviourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
    }

    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $found = Role::where('name', $role)->first();
        if ($found) {
            $user->roles()->attach($found);
        }

        return $user;
    }

    public function test_behaviour_register_renders(): void
    {
        $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
        BehaviourAbcEntry::factory()->create(['client_id' => $client->id]);

        $this->actingAs($this->userWithRole('clinical_lead'))
            ->get('/health-clinical/behaviour')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/Behaviour')
                ->has('entries.data', 1)
                ->has('stats')
                ->has('filter_options.functions')
                ->has('kpis'));
    }

    public function test_behaviour_forbidden_without_permission(): void
    {
        $this->actingAs($this->userWithRole('support_worker'))
            ->get('/health-clinical/behaviour')
            ->assertForbidden();
    }
}
