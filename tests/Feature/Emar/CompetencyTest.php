<?php

namespace Tests\Feature\Emar;

use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned Competency page resolves the active site's brand colour, serves
 * KPI counts, and the store now persists the previously-unfillable observed
 * rounds plus the new tri-state ("not seen") and restriction fields.
 */
class CompetencyTest extends TestCase
{
    use RefreshDatabase;

    private function seedCompetency(): array
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage']);
        $staff = $this->makeRoleUser('support_worker');
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);

        return compact('user', 'staff', 'site');
    }

    private function fullAreas(bool $allYes = true): array
    {
        $areas = ['medication_knowledge', 'five_rights', 'safety_checks', 'documentation', 'controlled_drugs', 'prn_assessment', 'insulin_competent', 'inhaler_competent', 'topical_competent', 'covert_admin_knowledge', 'error_reporting', 'allergy_awareness'];

        return collect($areas)->mapWithKeys(fn ($k) => [$k => $allYes])->all();
    }

    public function test_page_serves_brand_colour_and_kpis(): void
    {
        ['user' => $user, 'staff' => $staff, 'site' => $site] = $this->seedCompetency();
        MedicationCompetencyAssessment::query()->create(array_merge($this->fullAreas(), [
            'user_id' => $staff->id, 'assessor_id' => $user->id, 'assessment_type' => 'annual', 'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addMonths(11)->toDateString(),
            'total_score' => 12, 'pass_threshold' => 10, 'can_witness_controlled' => true,
        ]));

        $this->actingAs($user)
            ->get('/emar/competency?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Competency')
                ->where('site_brand_colour', '#5E35B1')
                ->has('assessments', 1)
                ->where('kpis.cd_witnesses', 1)
                ->has('kpis')
                ->has('staffWithoutAssessment')
            );
    }

    public function test_store_persists_observed_rounds_tristate_and_restrictions(): void
    {
        ['user' => $user, 'staff' => $staff] = $this->seedCompetency();

        $payload = array_merge($this->fullAreas(true), [
            'insulin_competent' => false, // marked not seen below
            'user_id' => $staff->id,
            'assessment_type' => 'initial',
            'assessment_date' => now()->toDateString(),
            'observed_rounds' => [['resident' => 'Aroha', 'med_type' => 'oral', 'outcome' => 'safe']],
            'not_seen_areas' => ['insulin_competent'],
            'restricted' => true,
            'restriction_notes' => 'Patches not yet observed',
            'action_plan' => 'Observe insulin next cycle',
        ]);

        $this->actingAs($user)
            ->from('/emar/competency')
            ->post('/emar/competency', $payload)
            ->assertSessionHasNoErrors();

        $a = MedicationCompetencyAssessment::query()->firstOrFail();
        $this->assertCount(1, $a->observed_rounds);
        $this->assertSame(['insulin_competent'], $a->not_seen_areas);
        $this->assertTrue($a->restricted);
        $this->assertSame(11, $a->total_score); // 12 areas yes, insulin set false
        $this->assertSame('passed', $a->status);
    }

    public function test_store_marks_failed_below_threshold(): void
    {
        ['user' => $user, 'staff' => $staff] = $this->seedCompetency();
        $areas = $this->fullAreas(false);
        // Only 5 competent — below the pass threshold of 10.
        foreach (['medication_knowledge', 'five_rights', 'safety_checks', 'documentation', 'allergy_awareness'] as $k) {
            $areas[$k] = true;
        }

        $this->actingAs($user)
            ->from('/emar/competency')
            ->post('/emar/competency', array_merge($areas, [
                'user_id' => $staff->id, 'assessment_type' => 'initial', 'assessment_date' => now()->toDateString(),
            ]))
            ->assertSessionHasNoErrors();

        $a = MedicationCompetencyAssessment::query()->firstOrFail();
        $this->assertSame(5, $a->total_score);
        $this->assertSame('failed', $a->status);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
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
}
