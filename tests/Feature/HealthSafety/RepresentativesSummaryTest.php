<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsRepresentative;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * G4-13 (board approved by Stephan 1 Nov 2026): the Health and safety representatives page's own numbers — active and
 * former representatives, elected in the last 12 months, training days recorded, the first three active faces by name —
 * and the "No longer a representative" view (every representative who isn't active).
 */
class RepresentativesSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-31T01:00:00Z'));
        $this->seed(RbacSeeder::class);
        $this->officer = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        $this->officer->roles()->attach(Role::where('name', 'health_safety_officer')->first());
    }

    private function rep(string $name, array $attrs): HsRepresentative
    {
        return HsRepresentative::create(['user_id' => User::factory()->create(['name' => $name])->id, 'site_id' => Site::factory()->create()->id,
            'election_method' => 'elected', 'elected_at' => '2026-02-01', 'status' => 'active', 'training_days_completed' => 1, 'created_by' => $this->officer->id, ...$attrs]);
    }

    public function test_the_representatives_numbers_and_former_view(): void
    {
        $this->rep('Mere Tane', ['training_days_completed' => 2]);
        $this->rep('Sam Wong', ['elected_at' => '2026-08-01']);
        $this->rep('Ana Folau', ['elected_at' => '2025-03-01', 'training_days_completed' => 2]);
        $this->rep('Zed Active', ['elected_at' => '2024-03-01', 'training_days_completed' => 0]);
        $former = $this->rep('Hemi Walker', ['status' => 'inactive', 'elected_at' => '2024-06-01', 'training_days_completed' => 2]);
        $resigned = $this->rep('Rita Resigned', ['status' => 'resigned', 'elected_at' => '2026-05-01']);

        $this->actingAs($this->officer)->get('/health-safety/worker-participation?tab=representatives')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/worker-participation/index')
                ->where('representatives_summary.active', 4)
                ->where('representatives_summary.former', 2)
                // Elected since 31 Oct 2025: Mere, Sam and Rita.
                ->where('representatives_summary.elected_12m', 3)
                // Active representatives only: 2 + 1 + 2 + 0.
                ->where('representatives_summary.training_days', 5)
                ->where('representatives_summary.faces', ['Ana Folau', 'Mere Tane', 'Sam Wong']));

        $this->actingAs($this->officer)->get('/health-safety/worker-participation?tab=representatives&status=former')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('rows.data', 2)
                ->where('rows.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$former->id, $resigned->id])->sort()->values()->all()));
    }
}
