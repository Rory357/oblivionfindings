<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\DoseSlots\DoseSlotGenerator;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\DoseSlots\DoseWindowOverride;
use App\Services\Medication\DoseSlots\DoseWindowResolver;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * P01 foundation C4: the dose-slot read service — live state in SQL against
 * a bound now, P09's numbers, and the reader scope (Sites, the P02 person
 * rule, controlled medicines in totals but concealed in rows).
 *
 * The fixture, at Monday 15 June 2026, 9:30 am NZST (window 30 before / 60 after):
 *   Kōwhai House — Aroha: Metformin 08:00 · 09:15 · 12:00 · 20:00, and a
 *   controlled Morphine 08:00; Ben: Iron 08:00. Rimu House — Cara: Iron 08:00.
 *   Sunday 14 June: Metformin 08:00 given on time, 09:15 refused, 12:00 given
 *   late (1:30 pm), 20:00 away (hospital); Morphine given; Ben not recorded.
 *   Monday 15 June: Morphine given; nothing else recorded yet.
 */
class DoseSlotProjectionTest extends TestCase
{
    use RefreshDatabase;

    private Site $kowhai;

    private Client $aroha;

    private Client $ben;

    private Client $cara;

    private ClientMedication $metformin;

    private ClientMedication $morphine;

    private User $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        // Orders entered before the two days read (nothing due before an
        // order's entry is owed); now is Monday 09:30.
        $this->freezeNz('2026-06-13 00:00');
        $this->kowhai = Site::factory()->create(['name' => 'Kōwhai House', 'is_active' => true]);
        $rimu = Site::factory()->create(['name' => 'Rimu House', 'is_active' => true]);
        $this->aroha = Client::factory()->create(['site_id' => $this->kowhai->id, 'first_name' => 'Aroha', 'status' => 'active']);
        $this->ben = Client::factory()->create(['site_id' => $this->kowhai->id, 'first_name' => 'Ben', 'status' => 'active']);
        $this->cara = Client::factory()->create(['site_id' => $rimu->id, 'first_name' => 'Cara', 'status' => 'active']);
        $this->recorder = User::factory()->create(['approved_at' => now()]);

        $this->metformin = $this->order($this->aroha, 'Metformin', ['08:00', '09:15', '12:00', '20:00']);
        $this->morphine = $this->order($this->aroha, 'Morphine', ['08:00'], ['controlled_drug' => true]);
        $iron = $this->order($this->ben, 'Iron', ['08:00']);
        $this->order($this->cara, 'Iron', ['08:00']);
        $this->freezeNz('2026-06-15 09:30');
        foreach (ClientMedication::query()->get() as $order) {
            DB::transaction(fn () => app(DoseSlotGenerator::class)->generate($order, '2026-06-14', '2026-06-15', CarbonImmutable::now()));
        }

        $this->record($this->metformin, '2026-06-14 08:00', 'given', '2026-06-14 08:10');
        $this->record($this->metformin, '2026-06-14 09:15', 'refused', '2026-06-14 09:20');
        $this->record($this->metformin, '2026-06-14 12:00', 'given', '2026-06-14 13:30');
        $this->record($this->metformin, '2026-06-14 20:00', 'withheld', '2026-06-14 20:00', 'hospitalised');
        $this->record($this->morphine, '2026-06-14 08:00', 'given', '2026-06-14 08:05');
        $this->record($this->morphine, '2026-06-15 08:00', 'given', '2026-06-15 08:05');
        $this->assertNotNull($iron);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_states_are_worked_out_against_the_bound_now(): void
    {
        $rows = $this->rows(DoseSlotReaderScope::internal(), CarbonImmutable::now());

        $this->assertSame([
            'Sun 08:00 Aroha Metformin given',
            'Sun 08:00 Aroha Morphine given',
            'Sun 08:00 Ben Iron not_recorded',
            'Sun 09:15 Aroha Metformin refused',
            'Sun 12:00 Aroha Metformin given late',
            'Sun 20:00 Aroha Metformin away',
            'Mon 08:00 Aroha Metformin late',
            'Mon 08:00 Aroha Morphine given',
            'Mon 08:00 Ben Iron late',
            'Mon 09:15 Aroha Metformin due',
            'Mon 12:00 Aroha Metformin not_due',
            'Mon 20:00 Aroha Metformin not_due',
        ], $rows);

        // The same data at other moments: nothing stored changes.
        $slot = fn (string $nzNow): string => collect($this->projection()->rows(
            DoseSlotReaderScope::internal([$this->aroha->id]), '2026-06-15', '2026-06-15', $this->nz($nzNow),
        ))->first(fn (array $row): bool => $row['client_medication_id'] === $this->metformin->id && $row['ordered_time'] === '08:00')['state'];
        $this->assertSame('not_due', $slot('2026-06-15 07:29'));
        $this->assertSame('due', $slot('2026-06-15 07:30'));
        $this->assertSame('due', $slot('2026-06-15 09:00'));
        $this->assertSame('late', $slot('2026-06-15 09:01'));
        $this->assertSame('not_recorded', $slot('2026-06-16 00:01'));
    }

    public function test_rows_carry_the_window_and_the_outcome(): void
    {
        $row = $this->projection()->rows(DoseSlotReaderScope::internal(), '2026-06-14', '2026-06-14', CarbonImmutable::now())
            ->first(fn (array $row): bool => $row['client_medication_id'] === $this->metformin->id && $row['ordered_time'] === '12:00');

        $this->assertSame('2026-06-14T00:00:00+00:00', $row['due_at']);
        $this->assertSame('2026-06-13T23:30:00+00:00', $row['window_opens_at']);
        $this->assertSame('2026-06-14T01:00:00+00:00', $row['window_ends_at']);
        $this->assertSame('given', $row['outcome']);
        $this->assertSame('2026-06-14T01:30:00+00:00', $row['outcome_at']);
        $this->assertTrue($row['recorded_late']);
        $this->assertNotNull($row['outcome_administration_id']);
    }

    public function test_totals_follow_p09s_definitions(): void
    {
        $totals = $this->projection()->totals(DoseSlotReaderScope::internal([$this->aroha->id, $this->ben->id]), '2026-06-14', '2026-06-15', CarbonImmutable::now());

        $this->assertSame([
            'slots' => 12,
            'due' => 8,             // window ended; Away never due
            'given' => 4,
            'refused' => 1,
            'withheld' => 0,
            'missed' => 0,
            'not_recorded' => 3,    // Ben on Sunday; Metformin and Ben this morning
            'recorded_late' => 1,
            'away' => 1,
            'self_managed' => 0,
            'due_now' => 1,         // Metformin 09:15, inside its window: named, not counted
            'not_yet_due' => 2,
            'late_today' => 2,
            'pending_check' => 0,
            'given_rate' => 50.0,
        ], $totals);

        $byDay = $this->projection()->totalsBy('nz_date', DoseSlotReaderScope::internal([$this->aroha->id, $this->ben->id]), '2026-06-14', '2026-06-15', CarbonImmutable::now());
        $this->assertSame([5, 3], [$byDay['2026-06-14']['due'], $byDay['2026-06-15']['due']]);
        $this->assertSame(60.0, $byDay['2026-06-14']['given_rate']);

        $byPerson = $this->projection()->totalsBy('client_id', DoseSlotReaderScope::internal([$this->aroha->id, $this->ben->id]), '2026-06-14', '2026-06-15', CarbonImmutable::now());
        $this->assertSame(0.0, $byPerson[$this->ben->id]['given_rate']);
    }

    public function test_nothing_due_reads_not_applicable_never_zero_or_one_hundred_percent(): void
    {
        // 6:00 am: no dose has reached the end of its window today.
        $totals = $this->projection()->totals(DoseSlotReaderScope::internal([$this->ben->id]), '2026-06-15', '2026-06-15', $this->nz('2026-06-15 06:00'));

        $this->assertSame(0, $totals['due']);
        $this->assertNull($totals['given_rate']);
        $this->assertSame(1, $totals['not_yet_due']);
    }

    public function test_a_time_critical_override_moves_only_its_orders_window(): void
    {
        $critical = new class($this->metformin->id) implements DoseWindowOverride
        {
            public function __construct(private readonly int $orderId) {}

            public function lateMinutesForOrder(int $orderId): ?int
            {
                return $orderId === $this->orderId ? 15 : null;
            }
        };
        $projection = new DoseSlotProjection(new DoseWindowResolver(30, 60, [$critical]));

        // 9:31 am: Metformin's 09:15 window ended at 09:30; Ben's 08:00 default window still ended at 09:00.
        $rows = $projection->rows(DoseSlotReaderScope::internal(), '2026-06-15', '2026-06-15', $this->nz('2026-06-15 09:31'));
        $metformin0915 = $rows->first(fn (array $row): bool => $row['client_medication_id'] === $this->metformin->id && $row['ordered_time'] === '09:15');
        $this->assertSame('late', $metformin0915['state']);
        $this->assertSame('2026-06-14T21:30:00+00:00', $metformin0915['window_ends_at']);
        $this->assertSame('due', $this->projection()->rows(DoseSlotReaderScope::internal([$this->aroha->id]), '2026-06-15', '2026-06-15', $this->nz('2026-06-15 09:31'))
            ->first(fn (array $row): bool => $row['client_medication_id'] === $this->metformin->id && $row['ordered_time'] === '09:15')['state']);
    }

    public function test_a_house_reader_sees_their_houses_people_only(): void
    {
        $coordinator = $this->staff('coordinator', $this->kowhai);
        $scope = DoseSlotReaderScope::forMedicationReader($coordinator);

        $this->assertEqualsCanonicalizing([$this->aroha->id, $this->ben->id], $scope->clientIds);
        $this->assertTrue($scope->canViewControlled);
        $clientIds = $this->projection()->rows($scope, '2026-06-14', '2026-06-15', CarbonImmutable::now())->pluck('client_id')->unique()->values()->all();
        $this->assertEqualsCanonicalizing([$this->aroha->id, $this->ben->id], $clientIds);
    }

    public function test_the_person_rule_leaves_no_hidden_row_counts(): void
    {
        // A support worker assigned to Aroha only: Ben shares the house but isn't counted.
        $worker = $this->staff('support_worker', $this->kowhai);
        $this->aroha->supportWorkers()->attach($worker->id);
        $scope = DoseSlotReaderScope::forMedicationReader($worker);

        $this->assertSame([$this->aroha->id], $scope->clientIds);
        $totals = $this->projection()->totals($scope, '2026-06-14', '2026-06-15', CarbonImmutable::now());
        $this->assertSame(6, $totals['due']);  // Aroha's counted doses, controlled included
    }

    public function test_controlled_doses_are_in_every_total_but_rows_naming_them_are_concealed(): void
    {
        $worker = $this->staff('support_worker', $this->kowhai);
        $this->aroha->supportWorkers()->attach($worker->id);
        $worker->permissionOverrides()->syncWithoutDetaching([
            Permission::query()->where('key', 'medications.controlled.view')->value('id') => ['allowed' => false],
        ]);
        $scope = DoseSlotReaderScope::forMedicationReader($worker->fresh());
        $this->assertFalse($scope->canViewControlled);

        $totals = $this->projection()->totals($scope, '2026-06-14', '2026-06-15', CarbonImmutable::now());
        $this->assertSame(6, $totals['due']);
        $this->assertSame(4, $totals['given']);

        $morphine = $this->projection()->rows($scope, '2026-06-14', '2026-06-15', CarbonImmutable::now())
            ->filter(fn (array $row): bool => $row['controlled']);
        $this->assertCount(2, $morphine);
        foreach ($morphine as $row) {
            $this->assertTrue($row['concealed']);
            $this->assertNull($row['client_medication_id']);
            $this->assertNull($row['schedule_version_id']);
            $this->assertNull($row['outcome_administration_id']);
            $this->assertSame('given', $row['outcome']);
        }
    }

    public function test_an_empty_scope_reads_nothing(): void
    {
        $scope = DoseSlotReaderScope::internal([]);

        $this->assertCount(0, $this->projection()->rows($scope, '2026-06-14', '2026-06-15', CarbonImmutable::now()));
        $this->assertSame(0, $this->projection()->totals($scope, '2026-06-14', '2026-06-15', CarbonImmutable::now())['slots']);
    }

    public function test_bad_input_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->projection()->totalsBy('site_id', DoseSlotReaderScope::internal(), '2026-06-14', '2026-06-15', CarbonImmutable::now());
    }

    public function test_the_house_and_day_read_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasIndex('medication_dose_slots', 'med_dose_slots_client_day_idx'));
        $this->assertTrue(Schema::hasIndex('medication_dose_slots', 'med_dose_slots_day_due_idx'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function projection(): DoseSlotProjection
    {
        return app(DoseSlotProjection::class);
    }

    /**
     * @return list<string>
     */
    private function rows(DoseSlotReaderScope $scope, CarbonImmutable $now): array
    {
        $names = ClientMedication::query()->pluck('name', 'id');
        $people = Client::query()->pluck('first_name', 'id');

        return $this->projection()->rows($scope, '2026-06-14', '2026-06-15', $now)
            ->filter(fn (array $row): bool => $row['client_id'] !== $this->cara->id)
            ->map(fn (array $row): string => trim(sprintf(
                '%s %s %s %s %s %s',
                $row['nz_date'] === '2026-06-14' ? 'Sun' : 'Mon',
                $row['ordered_time'],
                $people[$row['client_id']],
                $names[$row['client_medication_id']],
                $row['state'],
                $row['recorded_late'] ? 'late' : '',
            )))
            ->values()
            ->all();
    }

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
            'start_date' => '2026-06-01',
        ], $overrides));
    }

    private function record(ClientMedication $order, string $due, string $status, string $at, ?string $reasonCode = null): void
    {
        ClientMedicationAdministration::query()->create([
            'client_id' => $order->client_id,
            'client_medication_id' => $order->id,
            'administered_by' => $this->recorder->id,
            'scheduled_for' => $this->nz($due),
            'administered_at' => $this->nz($at),
            'status' => $status,
            'reason_code' => $reasonCode,
        ]);
    }

    private function staff(string $role, Site $site): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }

    private function freezeNz(string $local): void
    {
        Carbon::setTestNow(Carbon::parse($local, 'Pacific/Auckland')->utc());
    }

    private function nz(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'Pacific/Auckland')->utc();
    }
}
