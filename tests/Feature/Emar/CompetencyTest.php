<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCompetencyExemption;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationAdministratorCompetencyPolicy;
use Carbon\Carbon;
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
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage']);
        $staff = $this->makeRoleUser('support_worker');
        $this->assignCurrentSiteStaff($user, $site);
        $this->assignCurrentSiteStaff($staff, $site);

        return compact('user', 'staff', 'site');
    }

    private function fullAreas(bool $allYes = true): array
    {
        $areas = ['medication_knowledge', 'five_rights', 'safety_checks', 'documentation', 'controlled_drugs', 'prn_assessment', 'insulin_competent', 'inhaler_competent', 'topical_competent', 'covert_admin_knowledge', 'error_reporting', 'allergy_awareness'];

        return collect($areas)->mapWithKeys(fn ($k) => [$k => $allYes])->all();
    }

    public function test_later_declaration_and_acknowledgement_cannot_back_authorize_an_offline_capture(): void
    {
        ['user' => $assessor, 'staff' => $staff, 'site' => $site] = $this->seedCompetency();
        $capturedAt = Carbon::parse(
            '2026-08-20 23:55:00',
            config('app.worker_timezone', 'Pacific/Auckland'),
        );
        $capturedStorageAt = $capturedAt->copy()->timezone(config('app.timezone', 'UTC'));
        $olderValid = MedicationCompetencyAssessment::query()->create(array_merge($this->fullAreas(), [
            'user_id' => $staff->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => '2026-08-19',
            'expiry_date' => '2027-08-19',
            'total_score' => 12,
            'pass_threshold' => 10,
            'can_administer_unsupervised' => true,
            'assessor_declared_at' => $capturedStorageAt->copy()->subDay(),
            'staff_acknowledged_at' => $capturedStorageAt->copy()->subDay(),
        ]));
        MedicationCompetencyAssessment::query()->create(array_merge($this->fullAreas(), [
            'user_id' => $staff->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => '2026-08-20',
            'expiry_date' => '2027-08-20',
            'total_score' => 12,
            'pass_threshold' => 10,
            'can_administer_unsupervised' => true,
            // Persist explicit UTC/storage instants. Binding the NZ wall-clock
            // value directly would incorrectly include these future rows.
            'assessor_declared_at' => $capturedStorageAt->copy()->addMinutes(10),
            'staff_acknowledged_at' => $capturedStorageAt->copy()->addMinutes(15),
        ]));

        $decision = $this->app->make(MedicationAdministratorCompetencyPolicy::class)
            ->evaluate($staff, $site->id, $capturedAt);

        $this->assertTrue($decision['allowed']);
        $this->assertSame('valid', $decision['state']);
        $this->assertSame($olderValid->id, $decision['assessment_id']);
    }

    public function test_worker_timezone_capture_queries_exemption_by_utc_storage_instant(): void
    {
        ['user' => $approver, 'staff' => $staff, 'site' => $site] = $this->seedCompetency();
        $capturedAt = Carbon::parse(
            '2026-08-20 23:55:00',
            config('app.worker_timezone', 'Pacific/Auckland'),
        );
        $capturedStorageAt = $capturedAt->copy()->timezone(config('app.timezone', 'UTC'));
        $exemption = MedicationCompetencyExemption::query()->create([
            'user_id' => $staff->id,
            'site_id' => $site->id,
            'scope' => MedicationCompetencyExemption::SCOPE_ADMINISTRATION,
            'reason' => 'Time-limited supervised rollout.',
            'approved_by' => $approver->id,
            'approved_at' => $capturedStorageAt->copy()->subHour(),
            'starts_at' => $capturedStorageAt->copy()->subHour(),
            // Valid at 11:55 UTC but earlier than the unnormalised 23:55 NZ
            // SQL wall-clock binding that previously excluded this row.
            'expires_at' => $capturedStorageAt->copy()->addMinutes(5),
        ]);

        $decision = $this->app->make(MedicationAdministratorCompetencyPolicy::class)
            ->evaluate($staff, $site->id, $capturedAt);

        $this->assertTrue($decision['allowed']);
        $this->assertSame('exempt', $decision['state']);
        $this->assertSame($exemption->id, $decision['exemption_id']);
    }

    public function test_page_serves_brand_colour_and_kpis(): void
    {
        ['user' => $user, 'staff' => $staff, 'site' => $site] = $this->seedCompetency();
        MedicationCompetencyAssessment::query()->create(array_merge($this->fullAreas(), [
            'user_id' => $staff->id, 'assessor_id' => $user->id, 'assessment_type' => 'annual', 'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addMonths(11)->toDateString(),
            'total_score' => 12, 'pass_threshold' => 10, 'can_witness_controlled' => true,
            'assessor_declared_at' => now(), 'staff_acknowledged_at' => now(),
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

    public function test_assessment_payload_carries_detail_and_staff_jump_fields(): void
    {
        // Guards the cross-module parity contract the redesigned page relies on:
        // user_id drives the "View staff member" jump (/staff/{id}); observed_rounds
        // + assessor_comments + the 12 area booleans drive the enriched detail modal.
        ['user' => $user, 'staff' => $staff] = $this->seedCompetency();
        MedicationCompetencyAssessment::query()->create(array_merge($this->fullAreas(), [
            'user_id' => $staff->id, 'assessor_id' => $user->id, 'assessment_type' => 'annual', 'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addMonths(11)->toDateString(),
            'total_score' => 12, 'pass_threshold' => 10,
            'observed_rounds' => [['resident' => 'Aroha', 'med_type' => 'oral', 'outcome' => 'safe']],
            'assessor_comments' => 'Confident and methodical.',
        ]));

        $this->actingAs($user)
            ->get('/emar/competency')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Competency')
                ->has('assessments.0', fn (Assert $row) => $row
                    ->where('user_id', $staff->id)
                    ->where('user_name', $staff->name)
                    ->where('assessor_comments', 'Confident and methodical.')
                    ->has('observed_rounds', 1)
                    ->where('medication_knowledge', true)
                    ->etc()
                )
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

    public function test_store_persists_signoff_declarations(): void
    {
        // The assessor declaration and staff acknowledgement are independent,
        // authenticated actions by two different people.
        ['user' => $user, 'staff' => $staff] = $this->seedCompetency();

        $this->actingAs($user)
            ->from('/emar/competency')
            ->post('/emar/competency', array_merge($this->fullAreas(true), [
                'user_id' => $staff->id,
                'assessment_type' => 'initial',
                'assessment_date' => now()->toDateString(),
                'assessor_declared' => true,
                'staff_acknowledged' => true,
            ]))
            ->assertSessionHasNoErrors();

        $a = MedicationCompetencyAssessment::query()->firstOrFail();
        $this->assertNotNull($a->assessor_declared_at);
        $this->assertNull($a->staff_acknowledged_at);
        $this->assertFalse($a->isPassed());

        $this->actingAs($staff)
            ->post(route('emar.competency.acknowledge', $a))
            ->assertRedirect();
        $a->refresh();
        $this->assertNotNull($a->staff_acknowledged_at);
        $this->assertTrue($a->isPassed());

        // Serialized payload surfaces the declarations for the detail modal.
        $this->actingAs($user)
            ->get('/emar/competency')
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments.0', fn (Assert $row) => $row
                    ->where('assessor_declared_at', now()->toDateString())
                    ->where('staff_acknowledged_at', now()->toDateString())
                    ->etc()
                )
            );
    }

    public function test_passed_competency_cannot_be_self_certified_or_acknowledged_by_the_assessor(): void
    {
        ['user' => $user] = $this->seedCompetency();

        $this->actingAs($user)
            ->from('/emar/competency')
            ->post(route('emar.competency.store'), array_merge($this->fullAreas(true), [
                'user_id' => $user->id,
                'assessment_type' => 'initial',
                'assessment_date' => today()->toDateString(),
                'assessor_declared' => true,
                'staff_acknowledged' => true,
            ]))
            ->assertSessionHasErrors('user_id');
        $this->assertDatabaseCount('medication_competency_assessments', 0);

        $assessment = MedicationCompetencyAssessment::query()->create(array_merge($this->fullAreas(), [
            'user_id' => $user->id,
            'assessor_id' => $user->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today(),
            'expiry_date' => today()->addYear(),
            'total_score' => 12,
            'pass_threshold' => 10,
            'assessor_declared_at' => now(),
        ]));

        $this->actingAs($user)
            ->post(route('emar.competency.acknowledge', $assessment))
            ->assertNotFound();
        $this->assertNull($assessment->fresh()->staff_acknowledged_at);
        $this->assertFalse($assessment->fresh()->isPassed());
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

    public function test_competency_mutations_conceal_foreign_site_staff_and_assessments(): void
    {
        $this->seed(RbacSeeder::class);
        $localSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $actor = $this->makeRoleUser('support_worker');
        $localStaff = $this->makeRoleUser('support_worker');
        $otherLocalStaff = $this->makeRoleUser('support_worker');
        $foreignStaff = $this->makeRoleUser('support_worker');
        $this->grantPermissions($actor, ['medications.view', 'medications.orders.manage']);
        $this->assignCurrentSiteStaff($actor, $localSite);
        $this->assignCurrentSiteStaff($localStaff, $localSite);
        $this->assignCurrentSiteStaff($otherLocalStaff, $localSite);
        $this->assignCurrentSiteStaff($foreignStaff, $foreignSite);
        $localAssessment = MedicationCompetencyAssessment::query()->create(array_merge($this->fullAreas(), [
            'user_id' => $localStaff->id,
            'assessor_id' => $actor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today(),
            'expiry_date' => today()->addYear(),
            'total_score' => 12,
            'pass_threshold' => 10,
        ]));
        $foreignAssessment = MedicationCompetencyAssessment::query()->create(array_merge($this->fullAreas(), [
            'user_id' => $foreignStaff->id,
            'assessor_id' => $foreignStaff->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today(),
            'expiry_date' => today()->addYear(),
            'total_score' => 12,
            'pass_threshold' => 10,
        ]));
        $createPayload = array_merge($this->fullAreas(), [
            'user_id' => $foreignStaff->id,
            'assessment_type' => 'initial',
            'assessment_date' => today()->toDateString(),
        ]);

        $this->actingAs($actor)
            ->post(route('emar.competency.store'), $createPayload)
            ->assertNotFound();
        $this->actingAs($actor)
            ->put(route('emar.competency.update', $localAssessment), [
                'user_id' => $foreignStaff->id,
                'assessment_type' => '',
            ])
            ->assertNotFound();
        $this->actingAs($actor)
            ->put(route('emar.competency.update', $localAssessment), [
                'user_id' => $otherLocalStaff->id,
                'assessment_type' => 'renewal',
            ])
            ->assertNotFound();
        $this->actingAs($actor)
            ->put(route('emar.competency.update', $foreignAssessment), ['assessment_type' => 'renewal'])
            ->assertNotFound();
        $this->actingAs($actor)
            ->delete(route('emar.competency.destroy', $foreignAssessment))
            ->assertNotFound();
        $this->actingAs($actor)
            ->delete(route('emar.competency.destroy', $localAssessment))
            ->assertRedirect();

        $this->assertDatabaseCount('medication_competency_assessments', 2);
        $this->assertSame($localStaff->id, $localAssessment->fresh()->user_id);
        $this->assertSame('expired', $localAssessment->fresh()->status);
        $this->assertDatabaseHas('medication_competency_assessments', ['id' => $localAssessment->id]);
        $this->assertSame('annual', $foreignAssessment->fresh()->assessment_type);
        $this->assertNull($foreignAssessment->fresh()->deleted_at);
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

    private function assignCurrentSiteStaff(User $user, Site $site): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);
    }
}
