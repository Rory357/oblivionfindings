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
use App\Services\EnhancedMarService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * C6(k): recording a scheduled dose must match a dose the order owes — its
 * dose-slot projection slot (the slot's due time; nothing before the order
 * was entered) — not the order's dose times re-read as they are now. And the
 * MAR reads an earlier day's dose from its slot's state: due while its window
 * is still open after midnight, "Not recorded" once it has ended.
 */
class RecordingGuardSlotTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Site $site;

    private ServiceContext $context;

    private Client $aroha;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep the explicit fallback contract; public defaults are covered separately.
        config(['medications.person_record' => 'legacy']);

        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->at('2026-09-26 12:00');

        $this->site = Site::factory()->create(['is_active' => true]);
        $this->context = ServiceContext::factory()->create(['name' => 'Recording guard', 'type' => 'residential', 'is_active' => true]);
        $this->aroha = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'status' => 'active',
        ]);

        $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->worker->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->value('id')]);
        $this->worker->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', ['medications.administer.record'])->pluck('id')
                ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => '2026-01-01',
            'end_date' => null,
            'is_active' => true,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id,
            'assessor_id' => User::factory()->create(['role' => 'manager', 'approved_at' => now()])->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => '2026-08-26',
            'expiry_date' => '2027-08-26',
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_spring_forward_dose_is_recorded_at_its_slots_due_time(): void
    {
        // Sunday 27 September 2026: the clocks go forward at 02:00, so the
        // 02:30 dose is due at 03:00 NZDT.
        $order = $this->order('Night dose', ['02:30']);
        $this->onShift('2026-09-27 00:00', '2026-09-27 08:00');
        $this->at('2026-09-27 03:10');

        $this->record($order, '2026-09-27T03:30:00+13:00')->assertNotFound();
        $this->assertSame(0, ClientMedicationAdministration::query()->count());

        $this->record($order, '2026-09-27T03:00:00+13:00')->assertRedirect();
        $this->assertSame('given', ClientMedicationAdministration::query()->sole()->status);
    }

    public function test_a_dose_due_before_the_order_was_entered_cannot_be_recorded(): void
    {
        // Entered at 10:00 on the 28th: that morning's 08:00 dose was never owed.
        $order = $this->order('Iron', ['08:00', '20:00'], '2026-09-28 10:00');
        $this->onShift('2026-09-28 07:00', '2026-09-28 21:00');
        $this->at('2026-09-28 10:05');

        $this->record($order, '2026-09-28T08:00:00+13:00')->assertNotFound();
        $this->assertSame(0, ClientMedicationAdministration::query()->count());
    }

    public function test_the_mar_reads_an_earlier_days_dose_from_its_slot(): void
    {
        $this->order('Late dose', ['23:30'], '2026-09-28 00:00');

        // 00:10 on the 29th: the 28th's 23:30 dose is inside its window
        // (until 00:30), so it is still due — not "missed".
        $this->at('2026-09-29 00:10');
        $row = $this->mar('2026-09-28')[0];
        $this->assertSame('due', $row['schedule_state']);
        $this->assertTrue($row['can_record']);

        // Once its window has ended: not recorded.
        $this->at('2026-09-29 00:31');
        $row = $this->mar('2026-09-28')[0];
        $this->assertSame('missed_auto', $row['schedule_state']);
        $this->assertSame('Not recorded', $row['schedule_state_label']['label']);
    }

    public function test_the_mar_chart_takes_its_dose_times_and_orders_from_the_slots(): void
    {
        // The spring-forward 02:30 dose (due 03:00), and an order whose
        // change waits for the order check.
        $this->order('Night dose', ['02:30']);
        $metformin = $this->order('Metformin', ['08:00']);
        $this->at('2026-09-27 07:00');
        $metformin->update(['dosage' => '2 tablets']);
        $this->at('2026-09-27 09:00');

        $reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $reader->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', ['medications.view', 'clients.viewAny'])->pluck('id')
                ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $reader->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => '2026-01-01',
            'end_date' => null,
            'is_active' => true,
        ]);

        $page = $this->actingAs($reader->fresh())
            ->get(route('emar.mar', ['client_id' => $this->aroha->id, 'date' => '2026-09-27']))
            ->assertOk();
        $meds = collect($page->inertiaProps('marData.scheduled'))->keyBy('name');
        $schedule = collect($page->inertiaProps('schedule'))->keyBy('medication_name');

        // The grid's column for each dose is the slot's due time, the time its schedule row carries.
        $this->assertSame(['03:00'], $meds['Night dose']['dose_times']);
        $this->assertSame('03:00', $schedule['Night dose']['time']);
        // The order waiting for its check is on the chart, its dose shown as waiting.
        $this->assertSame(['08:00'], $meds['Metformin']['dose_times']);
        $this->assertSame('pending_check', $schedule['Metformin']['status']);
        $this->assertSame(['pending_check'], array_column($meds['Metformin']['administrations'], 'status'));
        // An unrecorded dose reads as Meds today does, not "missed" an hour after.
        $this->assertSame(['overdue'], array_column($meds['Night dose']['administrations'], 'status'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mar(string $date): array
    {
        return app(EnhancedMarService::class)->build($this->aroha->fresh(), Carbon::parse($date), null, null, true)['scheduled'];
    }

    private function onShift(string $fromNz, string $toNz): void
    {
        Shift::factory()->create([
            'client_id' => $this->aroha->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'user_id' => $this->worker->id,
            'starts_at' => Carbon::parse($fromNz, 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse($toNz, 'Pacific/Auckland')->utc(),
            'actual_starts_at' => Carbon::parse($fromNz, 'Pacific/Auckland')->utc(),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);
    }

    private function record(ClientMedication $order, string $scheduledFor): TestResponse
    {
        return $this->actingAs($this->worker->fresh())
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $order->id,
                'scheduled_for' => $scheduledFor,
                'status' => 'given',
                'administered_at' => now()->toIso8601String(),
            ]);
    }

    /**
     * An order entered at $enteredNz (nothing due before an order's entry is owed).
     *
     * @param  list<string>  $doseTimes
     */
    private function order(string $name, array $doseTimes, string $enteredNz = '2026-09-26 12:00'): ClientMedication
    {
        $now = Carbon::getTestNow();
        $this->at($enteredNz);
        $order = ClientMedication::query()->create([
            'client_id' => $this->aroha->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'start_date' => '2026-09-01',
        ]);
        Carbon::setTestNow($now);

        return $order;
    }
}
