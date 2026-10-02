<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AttendanceService;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Emar\ShiftMedicationSnapshotService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

/**
 * C6(d)+(e): My Day, the sidebar badge, the handover snapshot and the
 * clock-out "unsigned doses" count read the same doses and states as Meds
 * today and the MAR (the dose-slot projection), so they agree on one shift.
 *
 * Monday 15 June 2026, 09:30 NZST. A worker clocked in at 07:00 on a
 * 07:00–15:00 shift with Aroha. Defaults: window 30 before / 60 after, due
 * soon 60 before.
 */
class FrontlineDoseAgreementTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $aroha;

    private ServiceContext $context;

    private Shift $shift;

    /** @var array<string, ClientMedication> */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->at('2026-06-15 00:00');
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->context = ServiceContext::factory()->create(['name' => 'Agreement', 'type' => 'residential', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->aroha = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active']);

        // Entered at the start of the day.
        $this->orders['Metformin'] = $this->order('Metformin', ['07:00']);      // window ended 08:00: overdue
        $this->orders['Iron'] = $this->order('Iron', ['09:00']);                // window open: due
        $this->orders['Vitamin D'] = $this->order('Vitamin D', ['08:00']);      // given
        $this->orders['Calcium'] = $this->order('Calcium', ['12:00']);          // later
        $this->orders['Paracetamol'] = $this->order('Paracetamol', ['08:30']);  // waits for the order check
        $this->orders['Morphine'] = $this->order('Morphine', ['08:00'], ['controlled_drug' => true]); // overdue

        $this->at('2026-06-15 07:00');
        $this->orders['Paracetamol']->update(['dosage' => '2 tablets']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_my_day_meds_today_handover_and_clock_out_agree_on_every_dose(): void
    {
        $worker = $this->workerOnShift();
        $this->at('2026-06-15 08:05');
        $this->recordGiven('Vitamin D', '2026-06-15 08:00', '2026-06-15 08:05', $worker);
        $this->at('2026-06-15 09:30');

        $expected = [
            'Calcium 12:00' => 'upcoming',
            'Iron 09:00' => 'due',
            'Metformin 07:00' => 'overdue',
            'Morphine 08:00' => 'overdue',
            'Paracetamol 08:30' => 'pending_check',
            'Vitamin D 08:00' => 'given',
        ];

        // Meds today.
        $board = $this->actingAs($worker)->get('/meds/today')->assertOk();
        $this->assertSame($expected, $this->byDose($board->inertiaProps('schedule'), 'medication_name', 'scheduled_for'));

        // My Day: the same doses, the same states.
        Cache::flush();
        $myDay = $this->actingAs($worker)->get('/my-day')->assertOk();
        $this->assertSame($expected, $this->byDose($myDay->inertiaProps('medications_due'), 'medication_name', 'scheduled_for'));
        $this->assertSame(2, $myDay->inertiaProps('stats.meds_overdue'));
        $this->assertSame(5, $myDay->inertiaProps('stats.meds_due'));   // the dose waiting for the check isn't counted
        // A controlled-medicine reader sees every dose: nothing is left out.
        $this->assertSame(0, $myDay->inertiaProps('medications_hidden_controlled'));
        $this->assertSame(0, $myDay->inertiaProps('medications_hidden_controlled_overdue'));
        $this->assertSame(0, $board->inertiaProps('hidden_controlled_doses'));
        $this->assertSame(0, $board->inertiaProps('hidden_controlled_overdue'));
        $paracetamol = collect($myDay->inertiaProps('medications_due'))->firstWhere('medication_name', 'Paracetamol');
        $this->assertFalse($paracetamol['can_give']);
        $this->assertFalse($paracetamol['can_record']);

        // The sidebar badge: the same overdue doses.
        $this->assertSame(2, $myDay->inertiaProps('auth.can.medications.overdueTodayCount'));

        // The handover snapshot: still to do = every unrecorded dose of the
        // shift but the one waiting for the check; omissions = overdue.
        $snapshot = app(ShiftMedicationSnapshotService::class)->forShift($this->shift->fresh(), true);
        $this->assertSame(
            ['due' => 4, 'given' => 1, 'missed' => 0, 'omissions' => 2],
            array_intersect_key($snapshot['counts'], array_flip(['due', 'given', 'missed', 'omissions'])),
        );

        // Clock-out: the same four doses still need a MAR entry.
        $this->assertSame(4, $this->unsignedDoses());
        $unrecorded = collect($board->inertiaProps('schedule'))
            ->whereNull('recorded')
            ->where('status', '!=', 'pending_check')
            ->count();
        $this->assertSame($unrecorded, $this->unsignedDoses());
    }

    public function test_my_day_names_controlled_doses_only_to_readers_who_may_see_them(): void
    {
        // A second controlled medicine, due later in the shift (12:00): hidden,
        // but not overdue — so the hidden total and the hidden overdue differ.
        $this->at('2026-06-15 00:00');
        $this->orders['Oxycodone'] = $this->order('Oxycodone', ['12:00'], ['controlled_drug' => true]);
        $worker = $this->workerOnShift(deny: ['medications.controlled.view', 'medications.controlled.record']);
        $this->at('2026-06-15 09:30');

        $myDay = $this->actingAs($worker)->get('/my-day')->assertOk();
        $names = collect($myDay->inertiaProps('medications_due'))->pluck('medication_name');
        $this->assertNotContains('Morphine', $names->all());
        $this->assertNotContains('Oxycodone', $names->all());
        $this->assertContains('Metformin', $names->all());
        // Not named, but counted: the badge counts every overdue dose (P09 Q6) —
        // Metformin, Vitamin D (not recorded here) and Morphine.
        $badge = $myDay->inertiaProps('auth.can.medications.overdueTodayCount');
        $this->assertSame(3, $badge);
        $this->assertSame(2, $myDay->inertiaProps('stats.meds_overdue'));
        // The list says what it leaves out: 2 controlled doses (Morphine 08:00,
        // Oxycodone 12:00), 1 of them overdue — 2 listed overdue + 1 hidden
        // overdue = the badge.
        $this->assertSame(2, $myDay->inertiaProps('medications_hidden_controlled'));
        $this->assertSame(1, $myDay->inertiaProps('medications_hidden_controlled_overdue'));
        $this->assertSame($badge, $myDay->inertiaProps('stats.meds_overdue') + $myDay->inertiaProps('medications_hidden_controlled_overdue'));

        $board = $this->actingAs($worker)->get('/meds/today')->assertOk();
        $rows = collect($board->inertiaProps('schedule'));
        $this->assertNotContains('Morphine', $rows->pluck('medication_name')->all());
        $this->assertNotContains('Oxycodone', $rows->pluck('medication_name')->all());
        $this->assertSame(2, $board->inertiaProps('hidden_controlled_doses'));
        $this->assertSame(1, $board->inertiaProps('hidden_controlled_overdue'));
        $this->assertSame($badge, $rows->where('status', 'overdue')->count() + $board->inertiaProps('hidden_controlled_overdue'));
    }

    public function test_a_dose_recorded_as_missed_reads_missed_recorded_and_is_never_overdue(): void
    {
        $worker = $this->workerOnShift();
        $this->at('2026-06-15 08:30');
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id,
            'client_medication_id' => $this->orders['Metformin']->id,
            'administered_by' => $worker->id,
            'scheduled_for' => $this->nz('2026-06-15 07:00'),
            'administered_at' => $this->nz('2026-06-15 08:30'),
            'status' => 'missed',
            'reason' => 'Asleep through the round',
        ]);
        $this->at('2026-06-15 09:30');

        $board = $this->actingAs($worker)->get('/meds/today')->assertOk();
        $this->assertSame('missed', $this->byDose($board->inertiaProps('schedule'), 'medication_name', 'scheduled_for')['Metformin 07:00']);

        Cache::flush();
        $myDay = $this->actingAs($worker)->get('/my-day')->assertOk();
        $this->assertSame(
            $this->byDose($board->inertiaProps('schedule'), 'medication_name', 'scheduled_for'),
            $this->byDose($myDay->inertiaProps('medications_due'), 'medication_name', 'scheduled_for'),
        );
        // Not overdue, not due: Morphine and Vitamin D are the overdue doses
        // now; due = every listed dose but the missed one and the one waiting
        // for the check.
        $this->assertSame(2, $myDay->inertiaProps('stats.meds_overdue'));
        $this->assertSame(4, $myDay->inertiaProps('stats.meds_due'));
        $this->assertSame(2, $myDay->inertiaProps('auth.can.medications.overdueTodayCount'));
    }

    public function test_my_day_and_the_badge_count_only_people_the_worker_may_open(): void
    {
        // Rostered for later today but not clocked in, and not assigned to Aroha:
        // the worker may not open her chart yet, so nothing of hers is counted.
        $worker = $this->staff();
        Shift::factory()->create([
            'client_id' => $this->aroha->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'user_id' => $worker->id,
            'starts_at' => $this->nz('2026-06-15 13:00'),
            'ends_at' => $this->nz('2026-06-15 21:00'),
            'status' => 'scheduled',
        ]);
        $this->at('2026-06-15 12:30');

        $myDay = $this->actingAs($worker)->get('/my-day')->assertOk();
        $this->assertSame([], $myDay->inertiaProps('medications_due'));
        $this->assertSame(0, $myDay->inertiaProps('auth.can.medications.overdueTodayCount'));
        // Meds today agrees: none of her medicines before clock-in, and it
        // says why rather than claiming there's no shift.
        $board = $this->actingAs($worker)->get('/meds/today')->assertOk();
        $this->assertSame([], $board->inertiaProps('schedule'));
        $this->assertSame([], $board->inertiaProps('prn_medications'));
        $this->assertTrue($board->inertiaProps('has_shift_context'));
        $this->assertSame(1, $board->inertiaProps('people_after_clock_in'));

        // Once she's someone they support, her doses count.
        $this->aroha->supportWorkers()->attach($worker->id);
        Cache::flush();
        $myDay = $this->actingAs($worker->fresh())->get('/my-day')->assertOk();
        // By 12:30 the 07:00, 08:00 (both) and 09:00 windows have ended.
        $this->assertSame(4, $myDay->inertiaProps('auth.can.medications.overdueTodayCount'));
        $board = $this->actingAs($worker->fresh())->get('/meds/today')->assertOk();
        $this->assertSame(4, collect($board->inertiaProps('schedule'))->where('status', 'overdue')->count());
        $this->assertSame(0, $board->inertiaProps('people_after_clock_in'));
    }

    public function test_meds_today_shows_the_person_a_worker_is_clocked_in_with_and_not_the_next_shifts_person(): void
    {
        // sw2's case: clocked in with Aroha (not assigned to her), and rostered
        // later today with Ben, whom they don't support and haven't clocked in
        // for. Aroha's medicines show (a clocked-in covering shift); Ben's
        // don't, until they clock in to his shift.
        $ben = Client::factory()->create(['first_name' => 'Ben', 'last_name' => 'Tane', 'site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active']);
        $this->at('2026-06-15 00:00');
        ClientMedication::query()->create([
            'client_id' => $ben->id, 'name' => 'Sertraline', 'dosage' => '1 tablet', 'frequency' => 'Daily',
            'dose_times' => ['20:00'], 'is_prn' => false, 'active' => true, 'state' => 'active', 'start_date' => '2026-06-01',
        ]);
        $worker = $this->staff();
        foreach ([[$this->aroha, '07:00', '15:00', 'in_progress', '07:00'], [$ben, '15:00', '23:00', 'scheduled', null]] as [$person, $from, $to, $status, $clockedIn]) {
            Shift::factory()->create([
                'client_id' => $person->id,
                'site_id' => $this->site->id,
                'service_context_id' => $this->context->id,
                'user_id' => $worker->id,
                'starts_at' => $this->nz("2026-06-15 {$from}"),
                'ends_at' => $this->nz("2026-06-15 {$to}"),
                'actual_starts_at' => $clockedIn ? $this->nz("2026-06-15 {$clockedIn}") : null,
                'status' => $status,
            ]);
        }
        $this->at('2026-06-15 09:30');

        $board = $this->actingAs($worker)->get('/meds/today')->assertOk();
        $people = collect($board->inertiaProps('schedule'))->pluck('client_name')->unique()->values()->all();
        $this->assertSame(['Aroha Ngata'], $people);
        $this->assertSame(1, $board->inertiaProps('people_after_clock_in'));
        $this->assertSame([$this->aroha->id], collect($board->inertiaProps('clients'))->pluck('id')->all());

        // My Day and the badge read the same people.
        Cache::flush();
        $myDay = $this->actingAs($worker)->get('/my-day')->assertOk();
        $this->assertNotContains('Ben Tane', collect($myDay->inertiaProps('medications_due'))->pluck('client_name')->all());
    }

    public function test_an_overnight_handover_counts_the_doses_of_both_days_as_clock_out_does(): void
    {
        // Entered the evening before; Thyroxine is first owed on the 15th.
        $this->at('2026-06-14 18:00');
        $this->orders['Melatonin'] = $this->order('Melatonin', ['21:00']);
        $this->orders['Thyroxine'] = $this->order('Thyroxine', ['06:00']);
        $worker = $this->staff();
        $this->aroha->supportWorkers()->attach($worker->id);
        $this->shift = Shift::factory()->create([
            'client_id' => $this->aroha->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'user_id' => $worker->id,
            'starts_at' => $this->nz('2026-06-14 20:00'),
            'ends_at' => $this->nz('2026-06-15 08:00'),
            'actual_starts_at' => $this->nz('2026-06-14 20:00'),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);
        $this->at('2026-06-14 21:05');
        $this->recordGiven('Melatonin', '2026-06-14 21:00', '2026-06-14 21:05', $worker);
        $this->at('2026-06-15 09:30');

        // In the shift: Melatonin 21:00 (given) on the 14th; Thyroxine 06:00,
        // Metformin 07:00, Vitamin D and Morphine 08:00 on the 15th, all with
        // their windows ended (still to do, and omissions). Paracetamol 08:30
        // is after the shift.
        $snapshot = app(ShiftMedicationSnapshotService::class)->forShift($this->shift->fresh(), true);
        $this->assertSame(
            ['due' => 4, 'given' => 1, 'missed' => 0, 'omissions' => 4],
            array_intersect_key($snapshot['counts'], array_flip(['due', 'given', 'missed', 'omissions'])),
        );
        $this->assertSame(4, $this->unsignedDoses());
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
    }

    private function nz(string $local): Carbon
    {
        return Carbon::parse($local, 'Pacific/Auckland')->utc();
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<string, string> "Name HH:MM" => status, sorted
     */
    private function byDose(iterable $rows, string $nameKey, string $timeKey): array
    {
        $byDose = collect($rows)
            ->mapWithKeys(fn (array $row): array => [
                $row[$nameKey].' '.Carbon::parse($row[$timeKey])->timezone('Pacific/Auckland')->format('H:i') => $row['status'],
            ])
            ->all();
        ksort($byDose);

        return $byDose;
    }

    private function unsignedDoses(): int
    {
        $method = new ReflectionMethod(AttendanceService::class, 'countUnsignedMedicationDoses');

        return $method->invoke(app(AttendanceService::class), $this->shift->fresh());
    }

    private function recordGiven(string $order, string $due, string $at, User $by): void
    {
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id,
            'client_medication_id' => $this->orders[$order]->id,
            'administered_by' => $by->id,
            'scheduled_for' => $this->nz($due),
            'administered_at' => $this->nz($at),
            'status' => 'given',
        ]);
    }

    /**
     * @param  list<string>  $doseTimes
     * @param  array<string, mixed>  $overrides
     */
    private function order(string $name, array $doseTimes, array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->aroha->id,
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

    /**
     * A worker clocked in at 07:00 on a 07:00–15:00 shift with Aroha.
     * Aroha is one of the people they support.
     *
     * @param  list<string>  $deny
     */
    private function workerOnShift(array $deny = []): User
    {
        $worker = $this->staff($deny);
        $this->aroha->supportWorkers()->attach($worker->id);
        $this->shift = Shift::factory()->create([
            'client_id' => $this->aroha->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'user_id' => $worker->id,
            'starts_at' => $this->nz('2026-06-15 07:00'),
            'ends_at' => $this->nz('2026-06-15 15:00'),
            'actual_starts_at' => $this->nz('2026-06-15 07:00'),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);

        return $worker->fresh();
    }

    /**
     * A support worker (the seeded role), less any denied permissions.
     *
     * @param  list<string>  $deny
     */
    private function staff(array $deny = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $deny)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => '2026-01-01',
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }
}
