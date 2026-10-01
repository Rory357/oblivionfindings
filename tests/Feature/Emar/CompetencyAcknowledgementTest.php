<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\CompetencyAcknowledgement;
use App\Services\Medication\MedicationAdministratorCompetencyPolicy;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * eMAR P11 Acknowledge (fix-first, chunk 5): an assessment recorded through
 * the app counts only once the assessed person acknowledges it from their own
 * login. Until now nothing in the app let them, so a UI-recorded assessment
 * never counted. Meds today now shows their waiting assessment and opens the
 * v5 Acknowledge dialog.
 */
class CompetencyAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $assessor;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->assessor = $this->staff('admin', ['medications.view', 'medications.orders.manage']);
        $this->worker = $this->staff('support_worker', ['medications.view', 'medications.administer.record']);
    }

    public function test_an_assessment_recorded_in_the_app_counts_once_the_worker_acknowledges_it(): void
    {
        $assessment = $this->recordAssessment(['insulin_competent' => false, 'not_seen_areas' => ['covert_admin_knowledge'], 'covert_admin_knowledge' => false]);
        $policy = app(MedicationAdministratorCompetencyPolicy::class);
        $this->assertFalse($assessment->isPassed());
        $this->assertFalse($policy->evaluate($this->worker, $this->site->id, now())['allowed']);

        // Meds today shows the worker their waiting assessment.
        $this->actingAs($this->worker)
            ->get(route('meds.today'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pending_assessment.id', $assessment->id)
                ->where('pending_assessment.assessor', $this->assessor->name)
                ->where('pending_assessment.passed_areas', 10)
                ->where('pending_assessment.not_passed', ['Insulin administration'])
                ->where('pending_assessment.not_assessed', ['Covert administration'])
                ->where('pending_assessment.can_give_now', false));

        $this->actingAs($this->worker)
            ->from(route('meds.today'))
            ->post(route('emar.competency.acknowledge', $assessment))
            ->assertRedirect(route('meds.today'))
            ->assertSessionHas('success', 'Assessment acknowledged. You can record doses as given until '.$assessment->expiry_date->format('j M Y').'.');

        $assessment->refresh();
        $this->assertNotNull($assessment->staff_acknowledged_at);
        $this->assertTrue($assessment->isPassed());
        $this->assertTrue($policy->evaluate($this->worker, $this->site->id, now())['allowed']);
        $audit = AuditLog::query()->where('action', 'medications.competency.acknowledged')->sole();
        $this->assertSame($this->worker->id, (int) $audit->meta['actor_id']);
        $this->assertSame($assessment->id, (int) $audit->meta['assessment_id']);

        // Acknowledging again changes nothing and isn't audited twice.
        $this->actingAs($this->worker)->post(route('emar.competency.acknowledge', $assessment))->assertRedirect();
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.competency.acknowledged')->count());

        $this->actingAs($this->worker)
            ->get(route('meds.today'))
            ->assertInertia(fn (Assert $page) => $page->where('pending_assessment', null));
    }

    public function test_only_the_assessed_person_can_acknowledge_their_own_assessment(): void
    {
        $assessment = $this->recordAssessment();
        $colleague = $this->staff('support_worker', ['medications.view', 'medications.administer.record']);

        // Not the assessor, not a colleague.
        $this->actingAs($this->assessor)->post(route('emar.competency.acknowledge', $assessment))->assertNotFound();
        $this->actingAs($colleague)->post(route('emar.competency.acknowledge', $assessment))->assertNotFound();
        $this->assertNull($assessment->fresh()->staff_acknowledged_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'medications.competency.acknowledged')->count());

        // Nobody else is shown it to acknowledge.
        $this->actingAs($colleague)
            ->get(route('meds.today'))
            ->assertInertia(fn (Assert $page) => $page->where('pending_assessment', null));
    }

    public function test_nothing_waits_until_the_assessor_has_declared_it_and_it_passed(): void
    {
        $undeclared = $this->recordAssessment(['assessor_declared' => false]);
        $this->actingAs($this->worker)
            ->get(route('meds.today'))
            ->assertInertia(fn (Assert $page) => $page->where('pending_assessment', null));
        $this->actingAs($this->worker)->post(route('emar.competency.acknowledge', $undeclared))->assertNotFound();

        $failed = $this->recordAssessment(array_fill_keys(['medication_knowledge', 'five_rights', 'safety_checks'], false));
        $this->assertSame('failed', $failed->status);
        $this->actingAs($this->worker)
            ->get(route('meds.today'))
            ->assertInertia(fn (Assert $page) => $page->where('pending_assessment', null));
    }

    /** Record an assessment through the app, as an assessor does. */
    private function recordAssessment(array $over = []): MedicationCompetencyAssessment
    {
        $areas = collect(array_keys(CompetencyAcknowledgement::AREAS))->mapWithKeys(fn (string $key) => [$key => true])->all();
        $this->actingAs($this->assessor)
            ->from('/emar/competency')
            ->post('/emar/competency', array_merge($areas, [
                'user_id' => $this->worker->id,
                'assessment_type' => 'initial',
                'assessment_date' => now('Pacific/Auckland')->toDateString(),
                'assessor_declared' => true,
            ], $over))
            ->assertSessionHasNoErrors();

        return MedicationCompetencyAssessment::query()->latest('id')->firstOrFail();
    }

    /** @param  list<string>  $permissions */
    private function staff(string $role, array $permissions): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->firstOrFail()->id]);
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);

        return $user->fresh();
    }
}
