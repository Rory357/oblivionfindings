<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ModifiedDuty;
use App\Models\ReturnToWorkPlan;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkplaceInjury;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A worker sees their own workplace injuries in My HR, read-only (Stephan, 30 Oct: facts, care and the return-to-work
 * plan; no internal notes or files). Being the injured worker is the read rule; no H&S permission is involved.
 */
class MyInjuriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_a_support_worker_sees_their_own_injury_with_care_and_plan_but_no_notes(): void
    {
        [$worker, $site] = $this->staffAt('support_worker');
        $manager = User::factory()->create(['name' => 'Mere Manager']);
        $injury = WorkplaceInjury::factory()->accLodged()->create([
            'user_id' => $worker->id, 'site_id' => $site->id, 'status' => 'return_to_work',
            'injury_type' => 'manual_handling', 'body_part_affected' => 'Lower back',
            'description' => 'Lifted a laundry basket and felt a twinge.',
            'immediate_treatment' => 'Ice pack and rest.', 'medical_treatment_type' => 'gp_visit',
            'notes' => 'COORDINATOR-ONLY note about the worker',
            'coordinator_user_id' => $manager->id, 'coordinator_snapshot' => ['id' => $manager->id, 'name' => 'Mere Manager'],
        ]);
        $plan = ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $injury->id, 'worker_id' => $worker->id, 'manager_id' => $manager->id,
            'review_notes' => 'REVIEW-ONLY note', 'medical_clearance_notes' => 'CLEARANCE-ONLY note',
            'worker_agreement_notes' => 'AGREEMENT-ONLY note',
        ]);
        ModifiedDuty::factory()->create([
            'return_to_work_plan_id' => $plan->id, 'user_id' => $worker->id,
            'modified_duties_description' => 'Desk work only', 'notes' => 'DUTY-ONLY note',
        ]);
        // Someone else's injury at the same Site is never reachable.
        $colleague = $this->staffAt('support_worker', $site)[0];
        WorkplaceInjury::factory()->create(['user_id' => $colleague->id, 'site_id' => $site->id, 'description' => 'COLLEAGUE injury']);

        $response = $this->actingAs($worker)->get('/hr/my/injuries')->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hr/my/injuries')
            ->has('injuries', 1)
            ->where('injuries.0.id', $injury->id)
            ->where('injuries.0.description', 'Lifted a laundry basket and felt a twinge.')
            ->where('injuries.0.immediate_treatment', 'Ice pack and rest.')
            ->where('injuries.0.medical_treatment_type', 'gp_visit')
            ->where('injuries.0.acc_claim_number', $injury->acc_claim_number)
            ->where('injuries.0.coordinator', 'Mere Manager')
            ->where('injuries.0.plans.0.manager', 'Mere Manager')
            ->where('injuries.0.plans.0.duties.0.description', 'Desk work only')
            ->where('myHr.counts.injuries', 1)
            ->where('myHr.counts.openInjuries', 1)
            ->missing('injuries.0.notes')
            ->missing('injuries.0.plans.0.review_notes')
            ->missing('injuries.0.plans.0.medical_clearance_notes')
            ->missing('injuries.0.plans.0.worker_agreement_notes')
            ->missing('injuries.0.plans.0.duties.0.notes')
            ->missing('injuries.0.related_incident_id'));

        $body = (string) $response->getContent();
        foreach (['COORDINATOR-ONLY', 'REVIEW-ONLY', 'CLEARANCE-ONLY', 'AGREEMENT-ONLY', 'DUTY-ONLY', 'COLLEAGUE injury'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $body);
        }

        // The page opens no injury register.
        $this->get('/health-safety/injuries')->assertForbidden();
    }

    public function test_someone_with_no_injury_sees_an_empty_page_and_no_tab_count(): void
    {
        [$worker] = $this->staffAt('support_worker');

        $this->actingAs($worker)->get('/hr/my/injuries')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('injuries', 0)
                ->where('myHr.counts.injuries', 0));
    }

    /** @return array{0: User, 1: Site} */
    private function staffAt(string $role, ?Site $site = null): array
    {
        $site ??= Site::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'position_role' => $role,
            'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);

        return [$user->fresh(), $site];
    }
}
