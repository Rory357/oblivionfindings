<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\DoseSlots\DoseSlotGenerator;
use App\Services\MedicationOverviewService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * C6(a): the eMAR dashboard and the /dashboard widget read their dose numbers
 * from the dose-slot projection (NF-25, the trend half of EM-01): P09's
 * definitions on NZ days, the reader's Sites narrowed by the person rule,
 * controlled doses counted for every reader but named only to those who may
 * see them.
 *
 * Now is Monday 15 June 2026, 09:30 NZST (UTC+12): an 08:00 dose is 20:00
 * UTC the day before, so a UTC-dated count would file it on the wrong day.
 * Orders start on the 10th, so the 9th owes nothing.
 */
class DashboardDoseProjectionTest extends TestCase
{
    use RefreshDatabase;

    private Site $kowhai;

    private Site $rimu;

    private Client $aroha;

    private Client $ben;

    private Client $cara;

    private ClientMedication $metformin;

    private ClientMedication $morphine;

    private ClientMedication $benIron;

    private ClientMedication $caraIron;

    private User $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->at('2026-06-15 09:30');

        $this->kowhai = Site::factory()->create(['name' => 'Kōwhai House', 'is_active' => true]);
        $this->rimu = Site::factory()->create(['name' => 'Rimu House', 'is_active' => true]);
        $this->aroha = Client::factory()->create(['site_id' => $this->kowhai->id, 'first_name' => 'Aroha', 'last_name' => 'Ngata', 'status' => 'active']);
        $this->ben = Client::factory()->create(['site_id' => $this->kowhai->id, 'first_name' => 'Ben', 'last_name' => 'Parata', 'status' => 'active']);
        $this->cara = Client::factory()->create(['site_id' => $this->rimu->id, 'first_name' => 'Cara', 'last_name' => 'Rewi', 'status' => 'active']);
        $this->recorder = User::factory()->create(['approved_at' => now()]);

        $this->metformin = $this->order($this->aroha, 'Metformin', ['08:00', '20:00']);
        $this->morphine = $this->order($this->aroha, 'Morphine', ['08:00'], ['controlled_drug' => true]);
        $this->benIron = $this->order($this->ben, 'Iron', ['08:00']);
        $this->caraIron = $this->order($this->cara, 'Iron', ['08:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_seven_day_trend_counts_due_doses_on_nz_days(): void
    {
        $this->generateSince('2026-06-01');
        $this->recordTheWeek();

        $payload = app(MedicationOverviewService::class)->payload();

        // given ÷ due (window ended) per NZ day; nothing due on the 9th = "Not applicable".
        $this->assertSame([
            ['Tue', '2026-06-09', true, 0, 0, null],
            ['Wed', '2026-06-10', true, 0, 5, 0.0],
            ['Thu', '2026-06-11', true, 0, 5, 0.0],
            ['Fri', '2026-06-12', true, 0, 5, 0.0],
            ['Sat', '2026-06-13', true, 4, 5, 80.0],
            ['Sun', '2026-06-14', true, 3, 5, 60.0],
            ['Mon', '2026-06-15', true, 2, 4, 50.0],
        ], array_map(fn (array $day): array => [
            $day['date'], $day['nz_date'], $day['available'], $day['given'], $day['total'], $day['rate'],
        ], $payload['trend']));
        $this->assertSame([null, 0.0, 0.0, 0.0, 80.0, 60.0, 50.0], array_column($payload['complianceTrend'], 'rate'));
        $this->assertSame([0, 0, 0, 0, 4, 3, 2], $payload['stats']['givenTrend']);
        $this->assertSame(['available_from' => '2026-06-01', 'notice' => null, 'day_available' => true, 'day_notice' => null], $payload['doseCoverage']);

        // Today, at 09:30: the 08:00 windows ended at 09:00; 20:00 isn't due.
        $stats = $payload['stats'];
        $this->assertSame(
            ['total' => 5, 'given' => 2, 'overdue' => 2, 'dueNow' => 2, 'pending' => 3, 'eligible' => 4, 'rate' => 50.0],
            ['total' => $stats['totalToday'], 'given' => $stats['givenToday'], 'overdue' => $stats['overdue'], 'dueNow' => $stats['dueNow'],
                'pending' => $stats['pendingToday'], 'eligible' => $stats['eligibleToday'], 'rate' => $stats['adminRate']],
        );
        $this->assertSame(5, $payload['outcomeBreakdown']['total']);

        // A dose recorded after midnight UTC but on the NZ day stays on that day.
        $saturday = app(MedicationOverviewService::class)->payload(Carbon::parse('2026-06-13'));
        $this->assertSame(4, $saturday['stats']['givenToday']);
        $this->assertSame(1, $saturday['stats']['overdue']);
        $this->assertSame(80.0, $saturday['stats']['adminRate']);
    }

    public function test_days_before_the_projection_holds_them_are_not_available(): void
    {
        // Live generation began on the 13th; no backfill.
        $this->generateSince('2026-06-13');
        $this->recordTheWeek();

        $payload = app(MedicationOverviewService::class)->payload();

        $this->assertSame(
            [false, false, false, false, true, true, true],
            array_column($payload['trend'], 'available'),
        );
        $this->assertSame([null, null, null, null, 4, 3, 2], array_column($payload['trend'], 'given'));
        $this->assertSame([null, null, null, null, 80.0, 60.0, 50.0], array_column($payload['complianceTrend'], 'rate'));
        $this->assertSame([4, 3, 2], $payload['stats']['givenTrend']);
        $this->assertSame('Not available before 13 June 2026', $payload['doseCoverage']['notice']);
        $this->assertTrue($payload['doseCoverage']['day_available']);

        $thursday = app(MedicationOverviewService::class)->payload(Carbon::parse('2026-06-11'));
        $this->assertFalse($thursday['doseCoverage']['day_available']);
        $this->assertSame('Not available before 13 June 2026', $thursday['doseCoverage']['day_notice']);
    }

    public function test_numbers_are_for_the_readers_sites_and_the_people_they_may_open(): void
    {
        $this->generateSince('2026-06-01');
        $this->recordTheWeek();

        // A Kōwhai lead who may open every Kōwhai person: Aroha and Ben, not Cara.
        $lead = $this->reader($this->kowhai, ['medications.view', 'clients.viewAny', 'shifts.manageAny']);
        $payload = app(MedicationOverviewService::class)->payload(null, $lead);
        // Today: Metformin 08:00 given; Morphine and Ben's iron overdue; Metformin 20:00 not due.
        $this->assertSame([4, 1, 2, 2, 33.3], $this->todayNumbers($payload));
        $this->assertSame(['Aroha Ngata', 'Ben Parata'], array_column($payload['clientBoard'], 'name'));
        // Kōwhai only: Saturday 4 of 4, Sunday 2 of 4, today 1 of 3.
        $this->assertSame([100.0, 50.0, 33.3], array_slice(array_column($payload['complianceTrend'], 'rate'), 4));

        $this->actingAs($lead)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('emarWidgets.dueNow', 2)
                ->where('emarWidgets.overdue', 2)
                ->where('emarWidgets.adminRate', 33.3));

        // A Kōwhai worker who may open only the person they support: Aroha.
        $worker = $this->reader($this->kowhai, ['medications.view'], [$this->aroha]);
        $payload = app(MedicationOverviewService::class)->payload(null, $worker);
        $this->assertSame([3, 1, 1, 1, 50.0], $this->todayNumbers($payload));
        $this->assertSame(['Aroha Ngata'], array_column($payload['clientBoard'], 'name'));
        $board = $payload['clientBoard'][0];
        $this->assertSame([3, 1, 1, 1, 2], [$board['total'], $board['given'], $board['overdue'], $board['pending'], $board['done'] + $board['pending']]);
    }

    public function test_controlled_doses_count_for_every_reader_but_are_named_only_to_those_who_may_see_them(): void
    {
        $this->generateSince('2026-06-01');
        $this->recordTheWeek();

        $without = $this->reader($this->kowhai, ['medications.view', 'clients.viewAny']);
        $with = $this->reader($this->kowhai, ['medications.view', 'clients.viewAny', 'medications.controlled.view']);
        $plain = app(MedicationOverviewService::class)->payload(null, $without);
        $controlled = app(MedicationOverviewService::class)->payload(null, $with);

        // The same numbers: Morphine's overdue dose is counted for both.
        $this->assertSame($this->todayNumbers($controlled), $this->todayNumbers($plain));
        $this->assertSame(array_column($controlled['complianceTrend'], 'rate'), array_column($plain['complianceTrend'], 'rate'));
        $this->assertSame(array_column($controlled['clientBoard'], 'overdue'), array_column($plain['clientBoard'], 'overdue'));

        // Named only to the reader who may see controlled medicines.
        $overdue = fn (array $payload): array => collect($payload['actionCentre'])
            ->where('type', 'overdue_dose')
            ->map(fn (array $item): string => $item['record']['row']['medication_name'].' '.$item['record']['row']['client_name'])
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['Iron Ben Parata', 'Morphine Aroha Ngata'], $overdue($controlled));
        $this->assertSame(['Iron Ben Parata'], $overdue($plain));
    }

    public function test_overdue_means_the_window_has_ended(): void
    {
        $this->generateSince('2026-06-01');
        // 08:45: every 08:00 dose is past its time but inside its window.
        $this->at('2026-06-15 08:45');

        $payload = app(MedicationOverviewService::class)->payload();

        $this->assertSame(0, $payload['stats']['overdue']);
        $this->assertSame(4, $payload['stats']['dueNow']);
        $this->assertSame([], collect($payload['actionCentre'])->where('type', 'overdue_dose')->values()->all());
        // Nothing has come due yet today: "Not applicable", not 0 %.
        $this->assertNull($payload['stats']['adminRate']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
    }

    private function nz(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'Pacific/Auckland')->utc();
    }

    /** Slots for the week, as live generation would have made them from $since. */
    private function generateSince(string $since): void
    {
        foreach ([$this->metformin, $this->morphine, $this->benIron, $this->caraIron] as $order) {
            DB::transaction(fn () => app(DoseSlotGenerator::class)->generate(
                $order, '2026-06-09', '2026-06-15', CarbonImmutable::parse($since.' 00:00', 'Pacific/Auckland')->utc(),
            ));
        }
    }

    private function recordTheWeek(): void
    {
        // Saturday 13th: all but Cara; Metformin's evening dose late, before NZ midnight.
        $this->record($this->metformin, '2026-06-13 08:00', 'given', '2026-06-13 08:10');
        $this->record($this->metformin, '2026-06-13 20:00', 'given', '2026-06-13 23:50');
        $this->record($this->morphine, '2026-06-13 08:00', 'given', '2026-06-13 08:05');
        $this->record($this->benIron, '2026-06-13 08:00', 'given', '2026-06-13 08:20');
        // Sunday 14th: Ben not recorded; Metformin's evening dose refused.
        $this->record($this->metformin, '2026-06-14 08:00', 'given', '2026-06-14 08:10');
        $this->record($this->metformin, '2026-06-14 20:00', 'refused', '2026-06-14 20:05');
        $this->record($this->morphine, '2026-06-14 08:00', 'given', '2026-06-14 08:05');
        $this->record($this->caraIron, '2026-06-14 08:00', 'given', '2026-06-14 08:15');
        // Today: Metformin and Cara given; Morphine and Ben not yet.
        $this->record($this->metformin, '2026-06-15 08:00', 'given', '2026-06-15 08:05');
        $this->record($this->caraIron, '2026-06-15 08:00', 'given', '2026-06-15 08:20');
    }

    /**
     * @param  list<string>  $doseTimes
     * @param  array<string, mixed>  $overrides
     */
    private function order(Client $client, string $name, array $doseTimes, array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'start_date' => '2026-06-10',
        ], $overrides));
    }

    private function record(ClientMedication $order, string $due, string $status, string $at): void
    {
        ClientMedicationAdministration::query()->create([
            'client_id' => $order->client_id,
            'client_medication_id' => $order->id,
            'administered_by' => $this->recorder->id,
            'scheduled_for' => $this->nz($due),
            'administered_at' => $this->nz($at),
            'status' => $status,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<Client>  $supports
     */
    private function reader(Site $site, array $permissions, array $supports = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        foreach ($supports as $client) {
            $client->supportWorkers()->attach($user->id);
        }

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: int, 2: int, 3: int, 4: float|null} total, given, overdue, due now, admin rate
     */
    private function todayNumbers(array $payload): array
    {
        $stats = $payload['stats'];

        return [$stats['totalToday'], $stats['givenToday'], $stats['overdue'], $stats['dueNow'], $stats['adminRate']];
    }
}
