<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDoseSlot;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\EnhancedMarService;
use App\Services\Medication\DoseTimingSettings;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * C6(b): Meds today, the MAR schedule and EnhancedMarService's MAR rows take
 * each dose and its state from the dose-slot projection: due at the slot's
 * due time, "due" from when it shows as due soon (DoseTimingSettings) through
 * its window (DoseWindowResolver, both ends included), overdue/late once the
 * window has ended, and "Waiting for the order check" while an order's
 * change waits for its check — shown, never overdue, not recordable.
 *
 * Monday 15 June 2026 (NZST, UTC+12). Defaults: window 30 before / 60
 * after, due soon 60 before the dose time.
 */
class MedsTodayDoseStatesTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $aroha;

    private Client $ben;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->at('2026-06-15 00:00');
        $this->site = Site::factory()->create(['is_active' => true]);
        $context = ServiceContext::factory()->create(['name' => 'Dose states', 'type' => 'residential', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->aroha = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $this->ben = Client::factory()->create(['first_name' => 'Ben', 'last_name' => 'Parata', 'site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active']);

        // A worker rostered with Aroha all day, who supports her (the person
        // rule: before clock-in, only people they're assigned to show).
        $this->worker = $this->staff(['medications.administer.record', 'medications.view']);
        $this->aroha->supportWorkers()->attach($this->worker->id);
        Shift::factory()->create([
            'client_id' => $this->aroha->id,
            'site_id' => $this->site->id,
            'service_context_id' => $context->id,
            'user_id' => $this->worker->id,
            'starts_at' => $this->nz('2026-06-15 00:00'),
            'ends_at' => $this->nz('2026-06-15 23:59'),
            'status' => 'scheduled',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_states_follow_the_window_and_due_soon_at_its_edges(): void
    {
        $this->order($this->aroha, 'Metformin', ['08:00']);

        // [Meds today status, MAR schedule state] at each moment.
        $this->assertSame(['upcoming', 'upcoming'], $this->statesAt('2026-06-15 06:59'));
        $this->assertSame(['due', 'due_soon'], $this->statesAt('2026-06-15 07:00'));   // shows as due soon (60 before)
        $this->assertSame(['due', 'due'], $this->statesAt('2026-06-15 07:30'));        // the window opens (30 before)
        $this->assertSame(['due', 'due'], $this->statesAt('2026-06-15 09:00'));        // the window's end is included
        $this->assertSame(['overdue', 'late'], $this->statesAt('2026-06-15 09:01'));   // window ended
        $this->assertSame(['overdue', 'late'], $this->statesAt('2026-06-15 23:59'));   // late all day, no 180-minute "missed"

        // The next day, looking back: not recorded.
        $this->at('2026-06-16 10:00');
        $this->assertSame(['overdue'], array_column($this->medsToday('2026-06-15'), 'status'));
        $this->assertSame(['missed_auto'], array_column($this->mar('2026-06-15'), 'schedule_state'));

        // The window is the order's: the MAR row carries it.
        $this->at('2026-06-15 08:00');
        $row = $this->mar('2026-06-15')[0];
        $this->assertSame(
            [$this->nz('2026-06-15 07:30')->toIso8601String(), $this->nz('2026-06-15 09:00')->toIso8601String()],
            [Carbon::parse($row['window_start'])->utc()->toIso8601String(), Carbon::parse($row['window_end'])->utc()->toIso8601String()],
        );
    }

    public function test_due_soon_comes_from_the_setting(): void
    {
        $this->order($this->aroha, 'Metformin', ['08:00']);
        AppSetting::query()->updateOrCreate(['key' => DoseTimingSettings::DUE_SOON_MINUTES], ['value' => 90]);

        $this->assertSame(['upcoming', 'upcoming'], $this->statesAt('2026-06-15 06:29'));
        $this->assertSame(['due', 'due_soon'], $this->statesAt('2026-06-15 06:30'));

        // A shorter due-soon than the window: the window opens first.
        AppSetting::query()->updateOrCreate(['key' => DoseTimingSettings::DUE_SOON_MINUTES], ['value' => 15]);
        $this->assertSame(['upcoming', 'upcoming'], $this->statesAt('2026-06-15 07:29'));
        $this->assertSame(['due', 'due'], $this->statesAt('2026-06-15 07:30'));
    }

    public function test_a_dose_waiting_for_the_order_check_is_shown_but_never_overdue(): void
    {
        $order = $this->order($this->aroha, 'Metformin', ['08:00']);
        // Changed at 07:00; the change waits for its check.
        $this->at('2026-06-15 07:00');
        $order->update(['dosage' => '2 tablets']);

        $this->at('2026-06-15 09:30');
        $this->actingAs($this->worker->fresh())
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule', fn ($rows) => collect($rows)->pluck('status')->all() === ['pending_check'])
                ->where('stats.meds_overdue', 0)
                ->where('stats.due_now', 0)
                ->where('stats.meds_due', 0)
                ->where('due_now', []));

        // The MAR rows list it as an order awaiting verification, not as a dose to chase.
        $mar = app(EnhancedMarService::class)->build($this->aroha->fresh(), Carbon::parse('2026-06-15'), null, null, true);
        $this->assertSame([], $mar['scheduled']);
        $this->assertSame([$order->id], array_column($mar['awaiting_verification'], 'client_medication_id'));
    }

    public function test_the_record_dialog_gets_the_slots_due_time_on_the_spring_forward_day(): void
    {
        // Sunday 27 September 2026: the clocks go forward at 02:00.
        $this->at('2026-09-26 12:00');
        Shift::query()->where('user_id', $this->worker->id)->update([
            'starts_at' => $this->nz('2026-09-27 00:00'),
            'ends_at' => $this->nz('2026-09-27 23:00'),
        ]);
        $order = $this->order($this->aroha, 'Night dose', ['02:30'], '2026-09-26 12:00');

        $this->at('2026-09-27 09:00');
        $row = collect($this->medsToday('2026-09-27'))->sole();
        // 02:30 doesn't exist that day: due at 03:00 NZDT (14:00 UTC), not 03:30.
        $this->assertSame('2026-09-26T14:00:00+00:00', Carbon::parse($row['scheduled_for'])->utc()->toIso8601String());
        $this->assertSame('2026-09-26T14:00:00+00:00', Carbon::parse($this->mar('2026-09-27')[0]['scheduled_for'])->utc()->toIso8601String());

        // Recorded at that time, the slot gets its outcome and the row reads given.
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id,
            'client_medication_id' => $order->id,
            'administered_by' => $this->worker->id,
            'scheduled_for' => Carbon::parse($row['scheduled_for'])->utc(),
            'administered_at' => $this->nz('2026-09-27 03:05'),
            'status' => 'given',
        ]);
        $this->assertSame('given', MedicationDoseSlot::query()->where('client_medication_id', $order->id)->where('nz_date', '2026-09-27')->sole()->outcome);
        $this->assertSame(['given'], array_column($this->medsToday('2026-09-27'), 'status'));
    }

    public function test_a_worker_sees_only_their_people_and_a_lead_only_the_people_they_may_open(): void
    {
        $this->order($this->aroha, 'Metformin', ['08:00']);
        $this->order($this->ben, 'Iron', ['08:00']);
        $this->at('2026-06-15 09:30');

        // Rostered with Aroha only.
        $this->assertSame(['Aroha Ngata'], array_column($this->medsToday('2026-06-15'), 'client_name'));

        // A medication reader with no shift: the Site's people they may open.
        $reader = $this->staff(['medications.view']);
        $this->assertSame([], $this->medsToday('2026-06-15', $reader));
        $this->ben->supportWorkers()->attach($reader->id);
        $this->assertSame(['Ben Parata'], array_column($this->medsToday('2026-06-15', $reader->fresh()), 'client_name'));
    }

    public function test_controlled_doses_are_listed_only_for_readers_who_may_see_them(): void
    {
        $this->order($this->aroha, 'Metformin', ['08:00']);
        $this->order($this->aroha, 'Morphine', ['08:00'], overrides: ['controlled_drug' => true]);
        $this->at('2026-06-15 08:15');

        $this->denyPermissions($this->worker, ['medications.controlled.view']);
        $this->assertSame(['Metformin'], array_column($this->medsToday('2026-06-15'), 'medication_name'));

        $controlled = $this->staff(['medications.administer.record', 'medications.view', 'medications.controlled.view']);
        Shift::query()->where('user_id', $this->worker->id)->update(['user_id' => $controlled->id]);
        $this->aroha->supportWorkers()->attach($controlled->id);
        $rows = collect($this->medsToday('2026-06-15', $controlled))->sortBy('medication_name')->values();
        $this->assertSame(['Metformin', 'Morphine'], $rows->pluck('medication_name')->all());
        $this->assertSame(['due', 'due'], $rows->pluck('status')->all());
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
     * Meds today's status and the MAR row's state for the only dose, at a moment.
     *
     * @return array{0: string, 1: string}
     */
    private function statesAt(string $nz): array
    {
        $this->at($nz);
        $date = substr($nz, 0, 10);

        return [$this->medsToday($date)[0]['status'], $this->mar($date)[0]['schedule_state']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function medsToday(string $date, ?User $user = null): array
    {
        $response = $this->actingAs(($user ?? $this->worker)->fresh())
            ->get('/meds/today?date='.$date)
            ->assertOk();

        return array_values($response->inertiaProps('schedule'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mar(string $date): array
    {
        return app(EnhancedMarService::class)->build($this->aroha->fresh(), Carbon::parse($date), null, null, true)['scheduled'];
    }

    /**
     * An order entered at the start of the day (nothing due before an order's entry is owed).
     *
     * @param  list<string>  $doseTimes
     * @param  array<string, mixed>  $overrides
     */
    private function order(Client $client, string $name, array $doseTimes, string $enteredNz = '2026-06-15 00:00', array $overrides = []): ClientMedication
    {
        $now = Carbon::getTestNow();
        $this->at($enteredNz);
        $order = ClientMedication::query()->create(array_merge([
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
        Carbon::setTestNow($now);

        return $order;
    }

    /**
     * @param  list<string>  $permissions
     */
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
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => '2026-01-01',
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function denyPermissions(User $user, array $permissions): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
                ->all(),
        );
    }
}
