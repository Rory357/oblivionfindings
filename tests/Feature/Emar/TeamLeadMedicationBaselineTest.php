<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * eMAR role baseline (1 Oct 2026): house leads (team_lead) hold the same
 * frontline medication keys as support workers. The role grant alone never
 * lets anyone give a medicine: competency and a covering clocked-in shift
 * still decide that.
 */
class TeamLeadMedicationBaselineTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_01_100000_grant_team_lead_frontline_medication_keys.php';

    private const FRONTLINE_KEYS = [
        'medications.administer.record',
        'medications.administer.correct',
        'medications.controlled.view',
        'medications.controlled.record',
        'medications.controlled.witness',
    ];

    private Site $site;

    private Client $client;

    private ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->site = Site::factory()->create(['is_active' => true]);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Team lead baseline',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_seeder_gives_team_lead_every_frontline_medication_key_a_support_worker_holds_and_nothing_more(): void
    {
        $teamLead = $this->medicationKeys('team_lead');
        $supportWorker = $this->medicationKeys('support_worker');

        $this->assertSame([], array_values(array_diff($supportWorker, $teamLead)));
        $this->assertEqualsCanonicalizing([
            'medications.view',
            'medications.orders.verify',
            'medications.witness_pin.reset',
            ...self::FRONTLINE_KEYS,
        ], $teamLead);
    }

    public function test_the_grant_migration_adds_only_the_five_team_lead_grants_is_idempotent_and_rolls_back_only_them(): void
    {
        $migration = require base_path(self::MIGRATION);
        $teamLeadId = Role::query()->where('name', 'team_lead')->firstOrFail()->id;
        $keyIds = Permission::query()->whereIn('key', self::FRONTLINE_KEYS)->pluck('id');
        $this->assertCount(5, $keyIds);

        // A deployed database never ran the new seeder: team_lead starts from
        // main's set (view, orders.verify, witness_pin.reset).
        DB::table('role_permission')->where('role_id', $teamLeadId)->whereIn('permission_id', $keyIds)->delete();
        $before = $this->rolePermissionRows();
        $this->assertSame([], array_values(array_intersect(self::FRONTLINE_KEYS, $this->medicationKeys('team_lead'))));

        $migration->up();

        $after = $this->rolePermissionRows();
        $this->assertSame([], array_values(array_diff($before, $after)));
        $this->assertEqualsCanonicalizing(
            $keyIds->map(fn (int $id): string => $teamLeadId.':'.$id)->all(),
            array_values(array_diff($after, $before)),
        );
        foreach (self::FRONTLINE_KEYS as $key) {
            $this->assertContains($key, $this->medicationKeys('team_lead'));
        }

        $migration->up();
        $this->assertSame($after, $this->rolePermissionRows());

        $migration->down();
        $this->assertSame($before, $this->rolePermissionRows());
        $this->assertSame(5, Permission::query()->whereIn('key', self::FRONTLINE_KEYS)->count());
        foreach (self::FRONTLINE_KEYS as $key) {
            $this->assertContains($key, $this->medicationKeys('support_worker'));
        }
    }

    public function test_a_team_lead_with_qualified_competency_and_a_covering_shift_records_a_scheduled_dose(): void
    {
        $lead = $this->teamLeadOnShift();
        $this->qualifiedCompetency($lead);
        $medication = $this->scheduledMedication();
        $scheduledFor = Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'));

        // No per-user overrides: the role baseline alone opens the route.
        $this->assertSame([], $lead->permissionOverrides()->pluck('key')->all());

        $this->actingAs($lead)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'given',
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame('given', $administration->status);
        $this->assertSame($lead->id, (int) $administration->administered_by);
        $this->assertSame((int) $medication->id, (int) $administration->client_medication_id);
    }

    public function test_a_team_lead_without_qualified_competency_is_still_refused(): void
    {
        $lead = $this->teamLeadOnShift();
        // A bare "passed" row with no assessor declaration reads as unassessed.
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $lead->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);
        $medication = $this->scheduledMedication();
        $scheduledFor = Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'));

        $this->assertTrue($lead->canDo('medications.administer.record'));

        $this->actingAs($lead)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'given',
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHasErrors('status');

        $this->assertStringContainsString(
            'You cannot sign this dose as given',
            (string) session('errors')->first('status'),
        );
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    private function teamLeadOnShift(): User
    {
        $lead = User::factory()->create([
            'role' => 'team_lead',
            'approved_at' => now(),
        ]);
        $lead->roles()->syncWithoutDetaching([Role::query()->where('name', 'team_lead')->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $lead->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $lead->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);

        return $lead->fresh();
    }

    private function qualifiedCompetency(User $staff): void
    {
        $assessor = User::factory()->create([
            'role' => 'manager',
            'approved_at' => now(),
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $staff->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);
    }

    private function scheduledMedication(): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Morning tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => ['09:30'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ]);
    }

    /** @return list<string> */
    private function medicationKeys(string $role): array
    {
        return Role::query()->where('name', $role)->firstOrFail()
            ->permissions()
            ->where('key', 'like', 'medications.%')
            ->orderBy('key')
            ->pluck('key')
            ->all();
    }

    /** @return list<string> every role grant as "role_id:permission_id", sorted */
    private function rolePermissionRows(): array
    {
        return DB::table('role_permission')
            ->orderBy('role_id')
            ->orderBy('permission_id')
            ->get(['role_id', 'permission_id'])
            ->map(fn (object $row): string => $row->role_id.':'.$row->permission_id)
            ->all();
    }
}
