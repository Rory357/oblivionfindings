<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCompetencyExemption;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Emar\MedsBoardPayloadService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Meds today's "Med-competent" chip (board_user.med_competent) is the
 * competency policy's decision for the viewer, not the record permission:
 * staff with no assessment, or one awaiting their acknowledgement, are not
 * shown as competent.
 */
class MedCompetentChipTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_permission_without_an_assessment_is_not_competent(): void
    {
        $worker = $this->worker();

        $this->assertFalse($this->medCompetent($worker));
        $this->actingAs($worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('board_user.med_competent', false));
    }

    public function test_an_assessment_awaiting_acknowledgement_is_not_competent(): void
    {
        $worker = $this->worker();
        $this->assessment($worker, ['staff_acknowledged_at' => null]);

        $this->assertFalse($this->medCompetent($worker));
    }

    public function test_a_passed_and_acknowledged_assessment_is_competent(): void
    {
        $worker = $this->worker();
        $this->assessment($worker);

        $this->assertTrue($this->medCompetent($worker));
        $this->actingAs($worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('board_user.med_competent', true));
    }

    public function test_an_expired_assessment_is_not_competent(): void
    {
        $worker = $this->worker();
        $this->assessment($worker, [
            'assessment_date' => now()->subYears(2)->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
            'assessor_declared_at' => now()->subYears(2),
            'staff_acknowledged_at' => now()->subYears(2)->addMinute(),
        ]);

        $this->assertFalse($this->medCompetent($worker));
    }

    public function test_an_approved_exemption_at_the_workers_site_is_competent(): void
    {
        $worker = $this->worker();
        MedicationCompetencyExemption::query()->create([
            'user_id' => $worker->id,
            'site_id' => $this->site->id,
            'scope' => MedicationCompetencyExemption::SCOPE_ADMINISTRATION,
            'reason' => 'Assessment booked; supervised meanwhile',
            'approved_by' => User::factory()->create()->id,
            'approved_at' => now()->subDay(),
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addWeek(),
        ]);

        $this->assertTrue($this->medCompetent($worker));
    }

    public function test_competence_without_the_record_permission_is_not_shown(): void
    {
        $viewer = $this->worker(['medications.view']);
        $this->assessment($viewer);

        $this->assertFalse($this->medCompetent($viewer));
    }

    public function test_the_decision_is_worked_out_once_per_request(): void
    {
        $worker = $this->worker();
        $this->assessment($worker);
        $board = app(MedsBoardPayloadService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertTrue($board->isMedCompetent($worker));
        $this->assertTrue($board->isMedCompetent($worker));
        $reads = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'from `medication_competency_assessments`'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(1, $reads);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function medCompetent(User $user): bool
    {
        return app(MedsBoardPayloadService::class)->boardUser($user)['med_competent'];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function worker(array $permissions = ['medications.administer.record']): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assessment(User $user, array $overrides = []): void
    {
        MedicationCompetencyAssessment::query()->create(array_merge([
            'user_id' => $user->id,
            'assessor_id' => User::factory()->create(['approved_at' => now()])->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ], $overrides));
    }
}
