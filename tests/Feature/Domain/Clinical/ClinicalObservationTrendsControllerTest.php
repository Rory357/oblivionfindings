<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClinicalObservationTrendsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
    }

    public function test_client_trends_page_behaviour_and_access_control(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $supportWorker = $this->createUserWithRole('support_worker');
        $unassignedSupportWorker = $this->createUserWithRole('support_worker');
        $unauthorizedUser = User::factory()->create(['approved_at' => now()]);
        $client = Client::factory()->create();
        $client->supportWorkers()->attach($supportWorker->id);

        ClinicalObservation::factory()->weight()->create([
            'client_id' => $client->id,
            'recorded_at' => now()->subDays(4),
            'data' => ['weight_kg' => 71.2],
        ]);
        ClinicalObservation::factory()->pain()->create([
            'client_id' => $client->id,
            'recorded_at' => now()->subDays(3),
            'data' => ['score' => 5, 'location' => 'lower back'],
        ]);
        ClinicalObservation::factory()->vitals()->create([
            'client_id' => $client->id,
            'recorded_at' => now()->subDays(2),
            'data' => [
                'systolic' => 126,
                'diastolic' => 82,
                'pulse' => 74,
            ],
        ]);
        ClinicalObservation::factory()->fluidIntake()->create([
            'client_id' => $client->id,
            'recorded_at' => now()->subDay(),
            'data' => ['amount_ml' => 300, 'fluid_type' => 'water'],
        ]);
        ClinicalObservation::factory()->weight()->create([
            'client_id' => $client->id,
            'recorded_at' => now()->subDays(50),
            'data' => ['weight_kg' => 69.8],
        ]);
        ClinicalObservation::factory()->create([
            'client_id' => $client->id,
            'observation_type' => ObservationType::General,
            'data' => [],
            'recorded_at' => now()->subDays(2),
        ]);

        $this->actingAs($user)
            ->get('/health-clinical/clients/' . $client->id . '/trends?date_from=' . now()->subDays(7)->toDateString() . '&date_to=' . now()->toDateString())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/ClientTrends')
                ->where('client.id', $client->id)
                ->where('filters.date_from', now()->subDays(7)->toDateString())
                ->where('filters.date_to', now()->toDateString())
                ->where('trend_sets.weight.count', 1)
                ->where('trend_sets.weight.points.0.weight_kg', 71.2)
                ->where('trend_sets.pain.points.0.score', 5)
                ->where('trend_sets.vitals.points.0.systolic', 126)
                ->where('trend_sets.fluid_intake.points.0.amount_ml', 300)
                ->where('has_chartable_data', true)
                ->where('chartable_observation_count', 4)
            );

        $this->actingAs($user)
            ->get('/health-clinical/clients/' . $client->id . '/trends')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.date_from', now()->subDays(29)->toDateString())
                ->where('filters.date_to', now()->toDateString())
                ->where('trend_sets.weight.count', 1) // out-of-range entry excluded
                ->where('trend_sets.weight.points.0.weight_kg', 71.2)
            );

        $this->actingAs($supportWorker)
            ->get('/health-clinical/clients/' . $client->id . '/trends')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/ClientTrends')
                ->where('has_chartable_data', true)
                ->where('chartable_observation_count', 4)
            );

        $this->actingAs($unassignedSupportWorker)
            ->get('/health-clinical/clients/' . $client->id . '/trends')
            ->assertForbidden();

        $this->actingAs($unauthorizedUser)
            ->get('/health-clinical/clients/' . $client->id . '/trends')
            ->assertForbidden();
    }

    protected function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $user->roles()->attach($role);
        }

        return $user;
    }
}
