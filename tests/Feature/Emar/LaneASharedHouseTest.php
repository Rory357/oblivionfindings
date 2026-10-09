<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * eMAR audit Lane A10 (EA-006, P0; Stephan's decision D1): a worker clocked
 * in at a house records for every resident there they're assigned to
 * support, whichever person the roster shift names. A resident they aren't
 * assigned to, another house, or no clock-in stays blocked. House rounds
 * cover everyone, so a lone sleepover worker can finish the round.
 */
class LaneASharedHouseTest extends TestCase
{
    use RefreshDatabase;

    private Site $house;

    private Site $otherHouse;

    private ServiceContext $context;

    private User $worker;

    private Shift $shift;

    /** @var array<string, Client> */
    private array $people = [];

    /** @var array<string, ClientMedication> */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-07-01 22:10');
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->house = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null, 'name' => 'Kōwhai House']);
        $this->otherHouse = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null, 'name' => 'Rimu House']);
        $this->context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->worker = $this->staff('Sleepover Worker');

        foreach (['A' => $this->house, 'B' => $this->house, 'C' => $this->house, 'E' => $this->otherHouse] as $key => $site) {
            $this->person($key, $site);
        }
        // The roster shift names A. The worker supports B and C at the same
        // house and E at another house; A is on the shift, not assigned.
        $this->people['B']->supportWorkers()->attach($this->worker->id);
        $this->people['C']->supportWorkers()->attach($this->worker->id);
        $this->people['E']->supportWorkers()->attach($this->worker->id);

        $this->shift = Shift::factory()->create([
            'user_id' => $this->worker->id, 'client_id' => $this->people['A']->id, 'site_id' => $this->house->id,
            'service_context_id' => $this->context->id,
            'starts_at' => $this->nz('2026-07-01 21:50'), 'ends_at' => $this->nz('2026-07-02 08:00'),
            'actual_starts_at' => $this->nz('2026-07-01 21:55'), 'actual_ends_at' => null, 'status' => 'in_progress',
        ]);
        // Today's dose slots, as the hourly job keeps them.
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_lone_sleepover_worker_on_one_persons_shift_records_for_the_others_they_support(): void
    {
        foreach (['A', 'B', 'C'] as $key) {
            $this->requirements($key)->assertOk()->assertJsonPath('block_all', null);
        }

        foreach (['B', 'C'] as $key) {
            $this->record($key)->assertOk();
            $dose = ClientMedicationAdministration::query()->where('client_id', $this->people[$key]->id)->sole();
            $this->assertSame('given', $dose->status);
            $this->assertSame($this->worker->id, (int) $dose->administered_by);
            // The worker's own clocked-in shift, never a shift for B or C.
            $this->assertSame($this->shift->id, (int) $dose->shift_id);
        }
        $this->assertDatabaseCount('shift_clients', 0);

        $this->actingAs($this->worker)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('meds/today/index')
                ->where('schedule', function ($rows): bool {
                    $rows = collect($rows);
                    foreach (['A', 'B', 'C'] as $key) {
                        if (! $rows->contains(fn ($row) => (int) $row['client_id'] === $this->people[$key]->id)) {
                            return false;
                        }
                    }
                    // E is at another house: never recordable on this shift.
                    return $rows->every(fn ($row) => (int) $row['client_id'] !== $this->people['E']->id
                        || ($row['req']['block_all'] ?? null) !== null);
                }));
    }

    public function test_a_resident_at_the_same_house_they_are_not_assigned_to_stays_blocked(): void
    {
        $this->person('D', $this->house);
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();

        $requirements = $this->requirements('D');
        if ($requirements->status() === 200) {
            $this->assertNotNull($requirements->json('block_all'));
        } else {
            $this->assertContains($requirements->status(), [403, 404]);
        }
        $this->assertContains($this->record('D')->status(), [403, 404]);
        $this->assertSame(0, ClientMedicationAdministration::query()->where('client_id', $this->people['D']->id)->count());
    }

    public function test_an_assigned_resident_at_another_house_stays_blocked(): void
    {
        $this->requirements('E')->assertOk()->assertJsonPath('block_all.key', 'notClockedIn');
        $this->assertContains($this->record('E')->status(), [403, 404]);
        $this->assertSame(0, ClientMedicationAdministration::query()->where('client_id', $this->people['E']->id)->count());
    }

    public function test_an_assigned_resident_stays_blocked_until_the_worker_clocks_in(): void
    {
        $this->shift->forceFill(['actual_starts_at' => null, 'status' => 'scheduled'])->save();
        Cache::flush();

        $this->requirements('B')->assertOk()->assertJsonPath('block_all.key', 'notClockedIn');
        $this->assertContains($this->record('B')->status(), [403, 404]);
        $this->assertSame(0, ClientMedicationAdministration::query()->count());
    }

    public function test_the_lone_worker_can_complete_the_house_round(): void
    {
        $round = MedicationRound::query()->create([
            'site_id' => $this->house->id, 'service_context_id' => null,
            'name' => 'Evening round', 'scheduled_time' => '22:00', 'window_minutes' => 60,
            'round_date' => '2026-07-01', 'status' => 'in_progress',
            'assigned_to' => $this->worker->id, 'started_by' => $this->worker->id, 'started_at' => now(),
            'total_medications' => 3,
        ]);

        foreach (['A', 'B', 'C'] as $key) {
            $this->actingAs($this->worker)
                ->postJson("/emar/rounds/{$round->id}/guided/items/{$this->orders[$key]->id}", [
                    'status' => 'given',
                    'scheduled_for' => '2026-07-01T22:00:00+12:00',
                    'client_request_uuid' => (string) Str::uuid(),
                    'queued_offline' => false,
                ])
                ->assertOk()
                ->assertJsonPath('success', true);
        }

        $this->actingAs($this->worker)
            ->post("/emar/rounds/{$round->id}/guided/complete")
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->assertSame('completed', $round->fresh()->status);
        $this->assertSame(3, ClientMedicationAdministration::query()->where('medication_round_id', $round->id)->count());
    }

    private function requirements(string $key)
    {
        return $this->actingAs($this->worker)->getJson('/meds/today/doses/requirements?'.http_build_query([
            'client_medication_id' => $this->orders[$key]->id,
            'scheduled_for' => '2026-07-01T22:00:00+12:00',
        ]));
    }

    private function record(string $key)
    {
        return $this->actingAs($this->worker)->postJson('/meds/today/record', [
            'client_medication_id' => $this->orders[$key]->id,
            'scheduled_for' => '2026-07-01T22:00:00+12:00',
            'status' => 'given',
            'administered_at' => '2026-07-01T22:05:00',
            'amount_mode' => 'as_ordered',
            'client_request_uuid' => (string) Str::uuid(),
        ]);
    }

    private function person(string $key, Site $site): void
    {
        $this->people[$key] = Client::factory()->create([
            'site_id' => $site->id, 'service_context_id' => $this->context->id, 'status' => 'active',
            'first_name' => 'Resident '.$key, 'preferred_name' => 'Resident '.$key,
        ]);
        $now = Carbon::getTestNow();
        $this->clock('2026-06-20 09:00');
        $this->orders[$key] = ClientMedication::create([
            'client_id' => $this->people[$key]->id, 'name' => 'Synthetic order '.$key, 'dosage' => '1 tablet',
            'dose_amount' => 1, 'dose_unit' => 'tablet', 'frequency' => 'Daily', 'dose_times' => ['22:00'], 'is_prn' => false,
            'controlled_drug' => false, 'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'version' => 1,
            'start_date' => '2026-06-20',
        ]);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    private function nz(string $local): Carbon
    {
        return Carbon::parse($local, 'Pacific/Auckland')->utc();
    }

    private function clock(string $nz): void
    {
        $at = CarbonImmutable::parse($nz, 'Pacific/Auckland')->utc();
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    private function staff(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::where('name', 'support_worker')->sole()->id]);
        $permissions = Permission::whereIn('key', ['medications.view', 'medications.administer.record'])->get();
        $user->permissionOverrides()->syncWithoutDetaching($permissions->mapWithKeys(fn ($p) => [$p->id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->house->id,
            'secondary_site_ids' => [$this->otherHouse->id],
            'start_date' => now()->subYear(), 'end_date' => null, 'is_active' => true]);
        MedicationCompetencyAssessment::create(['user_id' => $user->id, 'assessor_id' => User::factory()->create()->id, 'assessment_type' => 'annual', 'status' => 'passed',
            'assessment_date' => now()->subMonths(2)->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonths(2), 'staff_acknowledged_at' => now()->subMonths(2)->addMinute(),
            'can_administer_unsupervised' => true, 'can_witness_controlled' => true, 'controlled_drugs' => true, 'restricted' => false, 'not_seen_areas' => []]);

        return $user->fresh();
    }
}
