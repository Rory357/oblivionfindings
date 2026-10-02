<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * C6(i): the profile calendar's medication events. Recorded doses from their
 * records; the other scheduled doses from the dose-slot projection — the
 * same doses, due times and states as Meds today — for today and three NZ
 * days either side, from the day the projection's coverage starts. The
 * profile's first month is the NZ month.
 *
 * Orders entered Friday 12 June 2026 00:00 NZST (UTC+12); defaults: window
 * 30 before / 60 after, due soon 60 before the dose time.
 */
class ClientCalendarDoseStatesTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $aroha;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->at('2026-06-12 00:00');
        $this->site = Site::factory()->create(['is_active' => true]);
        $context = ServiceContext::factory()->create(['name' => 'Calendar doses', 'type' => 'residential', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->aroha = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $this->reader = $this->staff(['clients.viewAssigned', 'calendar.view', 'medications.view']);
        $this->aroha->supportWorkers()->attach($this->reader->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_calendar_shows_each_dose_with_the_projections_state(): void
    {
        $metformin = $this->order('Metformin', ['08:00', '20:00']);
        $this->at('2026-06-15 00:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $given = $this->record($metformin, '2026-06-13 08:00', 'given');
        $missed = $this->record($metformin, '2026-06-14 08:00', 'missed');

        $this->at('2026-06-15 09:30');
        $events = $this->calendar('2026-06-01T00:00:00+12:00', '2026-07-13T00:00:00+12:00');

        $this->assertSame([
            '2026-06-12 08:00' => 'Metformin — Not recorded',
            '2026-06-12 20:00' => 'Metformin — Not recorded',
            '2026-06-13 08:00' => 'Metformin — Given',
            '2026-06-13 20:00' => 'Metformin — Not recorded',
            '2026-06-14 08:00' => 'Metformin — Missed (recorded)',
            '2026-06-14 20:00' => 'Metformin — Not recorded',
            '2026-06-15 08:00' => 'Metformin — Overdue',
            '2026-06-15 20:00' => 'Metformin — Due',
            '2026-06-16 08:00' => 'Metformin — Due',
            '2026-06-16 20:00' => 'Metformin — Due',
            '2026-06-17 08:00' => 'Metformin — Due',
            '2026-06-17 20:00' => 'Metformin — Due',
            // Past live generation (today + 2): the order as it is now.
            '2026-06-18 08:00' => 'Metformin — Due',
            '2026-06-18 20:00' => 'Metformin — Due',
        ], $this->titlesByNzTime($events));

        // Recorded doses are their records; the others are the slots.
        $byTime = collect($events)->keyBy(fn (array $event): string => $this->nzTime($event['start']));
        $this->assertSame('med-'.$given->id, $byTime['2026-06-13 08:00']['id']);
        $this->assertSame('med-'.$missed->id, $byTime['2026-06-14 08:00']['id']);
        $this->assertSame('medsched-'.$metformin->id.'-202606142000', $byTime['2026-06-15 08:00']['id']);
        $this->assertSame('not_recorded', $byTime['2026-06-14 20:00']['extendedProps']['status']);

        // Today's doses carry Meds today's statuses.
        $today = collect($events)
            ->filter(fn (array $event): bool => str_starts_with($this->nzTime($event['start']), '2026-06-15'))
            ->sortBy('start')
            ->pluck('extendedProps.status')
            ->values()
            ->all();
        $this->assertSame(['overdue', 'upcoming'], $today);
        $this->assertSame($today, array_column($this->medsToday('2026-06-15'), 'status'));

        // Inside the window, and once it shows as due soon.
        $this->at('2026-06-15 08:30');
        $this->assertSame('Metformin — Due now', $this->titlesByNzTime($this->calendar('2026-06-15T00:00:00+12:00', '2026-06-16T00:00:00+12:00'))['2026-06-15 08:00']);
        $this->at('2026-06-15 19:00');
        $this->assertSame('Metformin — Due now', $this->titlesByNzTime($this->calendar('2026-06-15T00:00:00+12:00', '2026-06-16T00:00:00+12:00'))['2026-06-15 20:00']);
    }

    public function test_nothing_is_owed_before_the_order_was_entered_or_the_projection_began(): void
    {
        // Entered at 09:00 on the 12th: that morning's 08:00 dose was never owed.
        $this->order('Iron', ['08:00'], '2026-06-12 09:00');
        $this->at('2026-06-13 10:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();

        $this->assertSame([
            '2026-06-13 08:00' => 'Iron — Overdue',
            '2026-06-14 08:00' => 'Iron — Due',
            '2026-06-15 08:00' => 'Iron — Due',
            '2026-06-16 08:00' => 'Iron — Due',
        ], $this->titlesByNzTime($this->calendar('2026-06-01T00:00:00+12:00', '2026-07-01T00:00:00+12:00')));
    }

    public function test_a_dose_waiting_for_the_order_check_is_shown_and_never_overdue(): void
    {
        $order = $this->order('Metformin', ['08:00', '20:00']);
        $this->at('2026-06-15 00:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        // Changed at 07:00; the change waits for its check.
        $this->at('2026-06-15 07:00');
        $order->update(['dosage' => '2 tablets']);

        $this->at('2026-06-15 09:30');
        $events = collect($this->calendar('2026-06-15T00:00:00+12:00', '2026-06-16T00:00:00+12:00'));

        $this->assertSame(
            ['Metformin — Waiting for the order check', 'Metformin — Waiting for the order check'],
            $events->sortBy('start')->pluck('title')->values()->all(),
        );
        $this->assertSame(['pending_check'], $events->pluck('extendedProps.status')->unique()->values()->all());
        $this->assertSame(['pending_check', 'pending_check'], array_column($this->medsToday('2026-06-15'), 'status'));
    }

    public function test_controlled_doses_are_shown_only_to_readers_who_may_see_them(): void
    {
        $this->order('Metformin', ['08:00']);
        $morphine = $this->order('Morphine', ['08:00'], overrides: ['controlled_drug' => true]);
        $this->at('2026-06-13 00:00');
        $record = $this->record($morphine, '2026-06-12 08:00', 'given');
        $this->at('2026-06-13 08:15');

        $this->denyPermissions($this->reader, ['medications.controlled.view']);
        $ordinary = collect($this->calendar('2026-06-01T00:00:00+12:00', '2026-07-01T00:00:00+12:00'));
        $this->assertSame([], $ordinary->filter(fn (array $event): bool => str_contains($event['title'], 'Morphine'))->all());

        $controlled = $this->staff(['clients.viewAssigned', 'calendar.view', 'medications.view', 'medications.controlled.view']);
        $this->aroha->supportWorkers()->attach($controlled->id);
        $events = $this->calendar('2026-06-12T00:00:00+12:00', '2026-06-14T00:00:00+12:00', $controlled);
        $this->assertSame([
            '2026-06-12 08:00' => 'Morphine — Given',
            '2026-06-13 08:00' => 'Morphine — Due now',
        ], $this->titlesByNzTime(array_values(array_filter($events, fn (array $event): bool => str_starts_with($event['title'], 'Morphine')))));
        $this->assertContains('med-'.$record->id, array_column($events, 'id'));
    }

    public function test_the_spring_forward_dose_is_on_the_calendar_at_its_due_time(): void
    {
        // Sunday 27 September 2026: the clocks go forward at 02:00.
        $this->at('2026-09-26 12:00');
        $this->order('Night dose', ['02:30'], '2026-09-26 12:00');
        $this->at('2026-09-27 09:00');

        $events = collect($this->calendar('2026-09-27T00:00:00+12:00', '2026-09-29T00:00:00+13:00'))->sortBy('start')->values();

        // 02:30 doesn't exist that day: due at 03:00 NZDT, not 03:30.
        $this->assertSame(['2026-09-27T03:00:00+13:00', '2026-09-28T02:30:00+13:00'], $events->pluck('start')->all());
        $this->assertSame(['Night dose — Overdue', 'Night dose — Due'], $events->pluck('title')->all());
    }

    public function test_the_profiles_first_month_is_the_nz_month(): void
    {
        // Wednesday 1 July 2026, 09:30 NZST — still 30 June in UTC.
        $this->order('Metformin', ['08:00'], '2026-06-27 00:00');
        $this->at('2026-07-01 00:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $this->at('2026-07-01 09:30');

        $response = $this->actingAs($this->reader->fresh())
            ->get(route('operations.clients.show', $this->aroha))
            ->assertOk();
        $medication = collect($response->inertiaProps('calendar_events'))
            ->filter(fn (array $event): bool => ($event['extendedProps']['type'] ?? null) === 'medication');

        $this->assertSame([
            '2026-07-01 08:00' => 'Metformin — Overdue',
            '2026-07-02 08:00' => 'Metformin — Due',
            '2026-07-03 08:00' => 'Metformin — Due',
            '2026-07-04 08:00' => 'Metformin — Due',
        ], $this->titlesByNzTime($medication->values()->all()));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
    }

    private function nzTime(string $iso): string
    {
        return Carbon::parse($iso)->timezone('Pacific/Auckland')->format('Y-m-d H:i');
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, string>
     */
    private function titlesByNzTime(array $events): array
    {
        return collect($events)
            ->filter(fn (array $event): bool => ($event['extendedProps']['type'] ?? null) === 'medication')
            ->sortBy(fn (array $event): string => Carbon::parse($event['start'])->utc()->format('YmdHis').$event['title'])
            ->mapWithKeys(fn (array $event): array => [$this->nzTime($event['start']) => $event['title']])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function calendar(string $start, string $end, ?User $user = null): array
    {
        return $this->actingAs(($user ?? $this->reader)->fresh())
            ->getJson(route('client.calendar.events', ['client' => $this->aroha, 'start' => $start, 'end' => $end], false))
            ->assertOk()
            ->json();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function medsToday(string $date): array
    {
        return array_values($this->actingAs($this->reader->fresh())
            ->get('/meds/today?date='.$date)
            ->assertOk()
            ->inertiaProps('schedule'));
    }

    private function record(ClientMedication $order, string $dueNz, string $status): ClientMedicationAdministration
    {
        $due = Carbon::parse($dueNz, 'Pacific/Auckland')->utc();

        return ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id,
            'client_medication_id' => $order->id,
            'administered_by' => $this->reader->id,
            'scheduled_for' => $due,
            'administered_at' => $due->copy()->addMinutes(5),
            'status' => $status,
        ]);
    }

    /**
     * An order entered at $enteredNz (nothing due before an order's entry is owed).
     *
     * @param  list<string>  $doseTimes
     * @param  array<string, mixed>  $overrides
     */
    private function order(string $name, array $doseTimes, string $enteredNz = '2026-06-12 00:00', array $overrides = []): ClientMedication
    {
        $now = Carbon::getTestNow();
        $this->at($enteredNz);
        $order = ClientMedication::query()->create(array_merge([
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
