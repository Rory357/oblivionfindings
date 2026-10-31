<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\WorkplaceInjuryRecoveryService;
use App\Services\HealthSafety\WorkplaceInjurySourceService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

abstract class WorkplaceInjurySourceTestCase extends TestCase
{
    use RefreshDatabase;

    protected Site $site;
    protected User $worker;
    protected User $manager;
    protected User $factual;
    protected User $capturer;
    protected User $medicalReader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12T01:00:00Z'));
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create();
        $this->worker = $this->staff();
        $this->manager = $this->staff(['hazards.view', 'hazards.create', 'hazards.manage', 'hr.wellbeing.view']);
        $this->factual = $this->staff(['hazards.view', 'hazards.manage']);
        $this->capturer = $this->staff(['hazards.create']);
        $this->medicalReader = $this->staff(['hr.wellbeing.view']);
    }

    protected function staff(array $permissions = [], ?Site $site = null): User
    {
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => ($site ?? $this->site)->id,
            'secondary_site_ids' => [], 'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true]);
        foreach ($permissions as $key) {
            $permission = Permission::where('key', $key)->firstOrFail();
            $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        return $actor;
    }

    protected function approveSite(User $actor, Site $site): void
    {
        $profile = HrEmployeeProfile::where('user_id', $actor->id)->sole();
        $profile->forceFill(['secondary_site_ids' => array_values(array_unique([...($profile->secondary_site_ids ?? []), $site->id]))])->save();
    }

    protected function revoke(User $actor, string $key): void
    {
        $actor->load('roles.permissions', 'permissionOverrides');
        $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => false]]);
    }

    protected function source(): WorkplaceInjurySourceService { return app(WorkplaceInjurySourceService::class); }
    protected function recovery(): WorkplaceInjuryRecoveryService { return app(WorkplaceInjuryRecoveryService::class); }
    protected function access(): InjuryAccessService { return app(InjuryAccessService::class); }

    protected function createPayload(array $overrides = []): array
    {
        return [...['user_id' => $this->worker->id, 'site_id' => $this->site->id, 'injury_date' => '2026-10-10T03:00:00+13:00',
            'injury_type' => 'strain', 'body_part_affected' => 'Shoulder', 'severity' => 'minor', 'description' => 'Recorded lifting injury.',
            'medical_treatment_type' => 'gp_visit', 'immediate_treatment' => 'Private treatment fact', 'notes' => 'Private enduring narrative',
            'acc_claim_number' => 'PRIVATE-CLAIM', 'idempotency_key' => (string) Str::uuid()], ...$overrides];
    }

    protected function injury(array $overrides = [], ?User $actor = null): WorkplaceInjury
    {
        $receipt = $this->source()->create($actor ?? $this->manager, $this->createPayload($overrides));
        return WorkplaceInjury::findOrFail($receipt['injury_id']);
    }

    protected function command(WorkplaceInjury $injury, array $facts = [], ?string $key = null): array
    {
        return ['expected_version' => (int) $injury->fresh()->source_revision, 'idempotency_key' => $key ?? (string) Str::uuid(), ...$facts];
    }

    protected function capacityPayload(WorkplaceInjury $injury, array $facts = []): array
    {
        return $this->command($injury, ['assessment_date' => '2026-10-11', 'assessor_name' => 'Attributed external issuer',
            'assessor_type' => 'gp', 'capacity_status' => 'fit_for_modified_duties', 'assessment_summary' => 'Private assessment',
            'restrictions' => 'Private proposed restriction prose', ...$facts]);
    }

    protected function planPayload(WorkplaceInjury $injury, array $facts = []): array
    {
        return $this->command($injury, ['plan_start_date' => '2026-10-12', 'plan_end_date' => '2026-11-01',
            'goals' => ['Recorded recovery goal'], 'stages' => [['name' => 'Graduated duties', 'start_date' => '2026-10-12',
                'end_date' => '2026-11-01', 'hours_per_week' => 20, 'duties_description' => 'Recorded proposed duties']],
            'medical_clearance_notes' => 'Attributed evidence, not system fitness', ...$facts]);
    }

    protected function denial(callable $call, int $status = 403): void
    {
        try { $call(); $this->fail('Expected current source authority denial.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); }
        catch (ModelNotFoundException $error) { $this->assertSame(404, $status); }
    }

    protected function validation(callable $call, string $field): void
    {
        try { $call(); $this->fail('Expected source draft preservation.'); }
        catch (ValidationException $error) { $this->assertArrayHasKey($field, $error->errors()); }
    }
}
