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
        $worker = $this->workerOnShift(deny: ['medications.controlled.view', 'medications.controlled.record']);
        $this->at('2026-06-15 09:30');

        $myDay = $this->actingAs($worker)->get('/my-day')->assertOk();
        $names = collect($myDay->inertiaProps('medications_due'))->pluck('medication_name');
        $this->assertNotContains('Morphine', $names->all());
        $this->assertContains('Metformin', $names->all());
        // Not named, but counted: the badge counts every overdue dose (P09 Q6) —
        // Metformin, Vitamin D (not recorded here) and Morphine.
        $this->assertSame(3, $myDay->inertiaProps('auth.can.medications.overdueTodayCount'));
        $this->assertSame(2, $myDay->inertiaProps('stats.meds_overdue'));
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

        // Once she's someone they support, her doses count.
        $this->aroha->supportWorkers()->attach($worker->id);
        Cache::flush();
        $myDay = $this->actingAs($worker->fresh())->get('/my-day')->assertOk();
        // By 12:30 the 07:00, 08:00 (both) and 09:00 windows have ended.
        $this->assertSame(4, $myDay->inertiaProps('auth.can.medications.overdueTodayCount'));
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
