<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientLeaveRequest;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\Site;
use App\Models\User;
use App\Services\EnhancedMarService;
use App\Services\GuidedRoundService;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\DoseSlots\OverdueDoses;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * C7: a dose due while the person is away — approved client leave, or
 * respite booked at another Site — reads as "Away · reason" everywhere: never
 * due, late, overdue, badged or alerted, its own number in P09 and never
 * hidden. Away is read live: withdraw or change the record and the dose is
 * owed again, overdue included. A recorded outcome always wins.
 *
 * Monday 15 June 2026 (NZST, UTC+12); orders entered Friday 12 June.
 */
class AwayDosesTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $kowhai;

    private Client $aroha;

    private User $reader;

    private ClientMedication $metformin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->at('2026-06-12 00:00');
        $this->home = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false]);
        $this->kowhai = Site::factory()->create(['name' => 'Kowhai House', 'is_active' => true, 'archived' => false]);
        $this->aroha = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $this->home->id, 'status' => 'active']);
        $this->reader = $this->staff(['clients.viewAssigned', 'calendar.view', 'medications.view']);
        $this->aroha->supportWorkers()->attach($this->reader->id);
        $this->metformin = ClientMedication::query()->create([
            'client_id' => $this->aroha->id,
            'name' => 'Metformin',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => ['08:00', '20:00'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'start_date' => '2026-06-01',
        ]);
        $this->at('2026-06-15 00:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_dose_on_approved_leave_is_away_everywhere_and_never_overdue(): void
    {
        $leave = $this->leave('2026-06-15', '2026-06-16', 'approved');
        $this->at('2026-06-15 09:30');

        // The projection: away, by the leave; its own number in P09.
        $rows = $this->rows('2026-06-15');
        $this->assertSame(['away', 'away'], $rows->pluck('state')->all());
        $this->assertSame(['source' => 'leave', 'id' => $leave->id], $rows->first()['away']);
        $totals = app(DoseSlotProjection::class)->totals(DoseSlotReaderScope::internal([$this->aroha->id]), '2026-06-15', '2026-06-15', CarbonImmutable::now());
        $this->assertSame([2, 0, 0, null], [$totals['away'], $totals['due'], $totals['not_recorded'], $totals['given_rate']]);

        // Meds today: shown, away with its reason, not due or overdue.
        $schedule = $this->medsToday('2026-06-15');
        $this->assertSame(['away', 'away'], array_column($schedule, 'status'));
        $this->assertSame('On leave (until Tue 16 Jun)', $schedule[0]['away_reason']);

        // The MAR: "Away · reason", never overdue.
        $mar = app(EnhancedMarService::class)->build($this->aroha->fresh(), Carbon::parse('2026-06-15'), null, null, true)['scheduled'];
        $this->assertSame(['away', 'away'], array_column($mar, 'schedule_state'));
        $this->assertSame('Away · On leave (until Tue 16 Jun)', $mar[0]['schedule_state_label']['label']);
        $this->assertFalse($mar[0]['is_overdue']);

        // The profile calendar.
        $titles = collect($this->calendar('2026-06-15T00:00:00+12:00', '2026-06-16T00:00:00+12:00'))->pluck('title')->all();
        $this->assertSame(['Metformin — Away · On leave (until Tue 16 Jun)', 'Metformin — Away · On leave (until Tue 16 Jun)'], $titles);

        // Never alerted (Sunday's unrecorded doses, before the leave, still are).
        $this->assertSame([], $this->overdueOn('2026-06-15'));
        $this->assertSame(['2026-06-14 08:00', '2026-06-14 20:00'], $this->overdueOn('2026-06-14'));
    }

    public function test_withdrawn_or_unapproved_leave_leaves_the_dose_owed(): void
    {
        $leave = $this->leave('2026-06-15', '2026-06-16', 'requested');
        $this->at('2026-06-15 09:30');
        $this->assertSame(['late', 'not_due'], $this->rows('2026-06-15')->pluck('state')->all());

        $leave->update(['status' => 'approved']);
        $this->assertSame(['away', 'away'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame([], $this->overdueOn('2026-06-15'));

        // Withdrawn: owed again, and overdue.
        $leave->update(['status' => 'cancelled']);
        $this->assertSame(['late', 'not_due'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['overdue', 'upcoming'], array_column($this->medsToday('2026-06-15'), 'status'));
        $this->assertSame(['2026-06-15 08:00'], $this->overdueOn('2026-06-15'));
    }

    public function test_respite_at_another_site_is_away_from_its_start_until_its_end(): void
    {
        $booking = RespiteBooking::query()->create([
            'client_id' => $this->aroha->id,
            'location_id' => $this->kowhai->id,
            'start_at' => Carbon::parse('2026-06-15 10:00', 'Pacific/Auckland')->utc(),
            'end_at' => Carbon::parse('2026-06-16 10:00', 'Pacific/Auckland')->utc(),
            'status' => 'confirmed',
        ]);
        $this->at('2026-06-15 21:30');

        // 08:00 was before the stay: owed. 20:00 and the next morning: away.
        $this->assertSame(['late', 'away'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['away', 'not_due'], $this->rows('2026-06-16')->pluck('state')->all());
        $schedule = $this->medsToday('2026-06-15');
        $this->assertSame(['overdue', 'away'], array_column($schedule, 'status'));
        $this->assertSame('Respite at Kowhai House (until Tue 16 Jun)', $schedule[1]['away_reason']);

        // Respite at the person's own Site is not away.
        $booking->update(['location_id' => $this->home->id]);
        $this->assertSame(['late', 'late'], $this->rows('2026-06-15')->pluck('state')->all());
    }

    public function test_a_recorded_outcome_wins_over_away(): void
    {
        $this->leave('2026-06-15', '2026-06-15', 'approved');
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id,
            'client_medication_id' => $this->metformin->id,
            'administered_by' => $this->reader->id,
            'scheduled_for' => Carbon::parse('2026-06-15 08:00', 'Pacific/Auckland')->utc(),
            'administered_at' => Carbon::parse('2026-06-15 08:05', 'Pacific/Auckland')->utc(),
            'status' => 'given',
        ]);
        $this->at('2026-06-15 09:30');

        $this->assertSame(['given', 'away'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['given', 'away'], array_column($this->medsToday('2026-06-15'), 'status'));
    }

    public function test_an_away_dose_does_not_hold_a_round_open(): void
    {
        $this->leave('2026-06-15', '2026-06-15', 'approved');
        $round = MedicationRound::query()->create([
            'site_id' => $this->home->id,
            'name' => 'Morning round',
            'round_type' => 'scheduled',
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'round_date' => '2026-06-15',
            'status' => 'in_progress',
            'started_by' => $this->reader->id,
        ]);
        $this->at('2026-06-15 08:15');

        $service = app(GuidedRoundService::class);
        $cells = $service->cells($round->fresh(), true);
        $this->assertSame(['away'], array_column($cells, 'status'));
        $this->assertSame('On leave (until Mon 15 Jun)', $cells[0]['away_reason']);
        // Not owed in the round: left out of the total, the round 100% recorded.
        $progress = $service->progress($round->fresh(), true);
        $this->assertSame([0, 0, 100, 1], [$progress['total'], $progress['pending'], $progress['percent'], $progress['away']]);
        $this->assertTrue($service->canCompleteCanonicalRound($round->fresh()));
    }

    public function test_a_day_past_live_generation_reads_away_from_the_same_records(): void
    {
        $this->leave('2026-06-15', '2026-06-20', 'approved');
        $this->at('2026-06-15 09:30');

        // The 18th is past live generation (today + 2): the order as it is now.
        $titles = collect($this->calendar('2026-06-18T00:00:00+12:00', '2026-06-19T00:00:00+12:00'))->pluck('title')->all();
        $this->assertSame(['Metformin — Away · On leave (until Sat 20 Jun)', 'Metformin — Away · On leave (until Sat 20 Jun)'], $titles);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
    }

    private function leave(string $from, string $to, string $status): ClientLeaveRequest
    {
        return ClientLeaveRequest::query()->create([
            'client_id' => $this->aroha->id,
            'starts_on' => $from,
            'ends_on' => $to,
            'destination' => 'Whānau in Rotorua',
            'status' => $status,
            'requested_by' => $this->reader->id,
        ]);
    }

    /**
     * The overdue sweep's doses due on an NZ day, as "Y-m-d H:i".
     *
     * @return list<string>
     */
    private function overdueOn(string $nzDate): array
    {
        return app(OverdueDoses::class)->at(now(), [$this->aroha->id])
            ->map(fn (array $dose): string => $dose['due_at']->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))
            ->filter(fn (string $due): bool => str_starts_with($due, $nzDate))
            ->values()
            ->all();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function rows(string $nzDate): \Illuminate\Support\Collection
    {
        return app(DoseSlotProjection::class)->rows(DoseSlotReaderScope::internal([$this->aroha->id]), $nzDate, $nzDate, CarbonImmutable::now());
    }

    /** @return list<array<string, mixed>> */
    private function medsToday(string $date): array
    {
        return array_values($this->actingAs($this->reader->fresh())
            ->get('/meds/today?date='.$date)
            ->assertOk()
            ->inertiaProps('schedule'));
    }

    /** @return list<array<string, mixed>> */
    private function calendar(string $start, string $end): array
    {
        return collect($this->actingAs($this->reader->fresh())
            ->getJson(route('client.calendar.events', ['client' => $this->aroha, 'start' => $start, 'end' => $end], false))
            ->assertOk()
            ->json())
            ->filter(fn (array $event): bool => ($event['extendedProps']['type'] ?? null) === 'medication')
            ->sortBy('start')
            ->values()
            ->all();
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->home->id,
            'secondary_site_ids' => [],
            'start_date' => '2026-01-01',
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }
}
