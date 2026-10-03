<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * P02-1b: the client profile's medication day. Medicines × the day's dose
 * times, each dose with Meds today's status (and the projection's own state
 * and window), behind the per-person gate, controlled medicines left out
 * and counted, days before the projection's coverage given its notice.
 *
 * Orders entered Friday 12 June 2026 00:00 NZST (UTC+12); defaults: window
 * 30 before / 60 after, due soon 60 before the dose time.
 */
class ClientMedicationDayTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ServiceContext $context;

    private Client $aroha;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->at('2026-06-12 00:00');
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->context = ServiceContext::factory()->create(['name' => 'Medication day', 'type' => 'residential', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->aroha = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active']);
        $this->reader = $this->staff(['clients.viewAssigned', 'medications.view']);
        $this->aroha->supportWorkers()->attach($this->reader->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_day_lists_each_medicine_by_dose_time_with_meds_todays_states(): void
    {
        $metformin = $this->order('Metformin', ['08:00', '20:00']);
        $this->order('Iron', ['13:00']);
        $this->at('2026-06-15 00:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $this->record($metformin, '2026-06-15 08:00', 'given');

        $this->at('2026-06-15 14:30');
        $day = $this->day();

        $this->assertSame('2026-06-15', $day['date']);
        $this->assertSame('2026-06-15', $day['today']);
        $this->assertSame(['08:00', '13:00', '20:00'], $day['times']);
        $this->assertSame(['Iron', 'Metformin'], array_column($day['medicines'], 'name'));
        $cells = $this->cells($day);
        $this->assertSame('given', $cells['Metformin 08:00']['status']);
        $this->assertSame('08:05', $cells['Metformin 08:00']['recorded']['time']);
        // Window ended today with nothing recorded: overdue, the slot "late".
        $this->assertSame('overdue', $cells['Iron 13:00']['status']);
        $this->assertSame('late', $cells['Iron 13:00']['state']);
        $this->assertSame('upcoming', $cells['Metformin 20:00']['status']);

        // Inside the window: due, the slot's state "due" (Due now).
        $this->at('2026-06-15 19:45');
        $cells = $this->cells($this->day());
        $this->assertSame('due', $cells['Metformin 20:00']['status']);
        $this->assertSame('due', $cells['Metformin 20:00']['state']);
        $this->assertStringStartsWith('2026-06-15T19:30', $cells['Metformin 20:00']['window_opens_at']);
        $this->assertStringStartsWith('2026-06-15T21:00', $cells['Metformin 20:00']['window_ends_at']);
    }

    public function test_a_dose_waiting_for_the_order_check_is_shown_as_waiting(): void
    {
        $order = $this->order('Metformin', ['08:00', '20:00']);
        $this->at('2026-06-15 00:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $this->at('2026-06-15 07:00');
        $order->update(['dosage' => '2 tablets']);

        $this->at('2026-06-15 09:30');
        $cells = $this->cells($this->day());

        $this->assertSame('pending_check', $cells['Metformin 08:00']['status']);
    }

    public function test_a_given_reoffer_after_a_refusal_reads_as_given(): void
    {
        // Since P01 C1 a re-offer is a second record in the same slot: the
        // slot reads as the latest, never the first.
        $order = $this->order('Metformin', ['08:00']);
        $this->at('2026-06-15 00:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $refused = $this->record($order, '2026-06-15 08:00', 'refused');
        $given = $this->record($order, '2026-06-15 08:00', 'given');
        $given->forceFill([
            'reoffer_of_id' => $refused->id,
            'administered_at' => Carbon::parse('2026-06-15 08:25', 'Pacific/Auckland')->utc(),
        ])->save();

        $this->at('2026-06-15 10:00');
        $cell = $this->cells($this->day())['Metformin 08:00'];

        $this->assertSame('given', $cell['status']);
        $this->assertSame($given->id, $cell['recorded']['id']);
    }

    public function test_controlled_medicines_are_left_out_and_counted_for_a_reader_without_access(): void
    {
        $this->order('Metformin', ['08:00']);
        $this->order('Morphine', ['08:00'], overrides: ['controlled_drug' => true]);
        $this->at('2026-06-15 00:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $this->at('2026-06-15 09:00');

        $day = $this->day();
        $this->assertSame(['Metformin'], array_column($day['medicines'], 'name'));
        $this->assertSame(1, $day['hidden_controlled']['total']);

        $this->grant($this->reader, ['medications.controlled.view']);
        $day = $this->day();
        $this->assertSame(['Metformin', 'Morphine'], array_column($day['medicines'], 'name'));
        $this->assertSame(0, $day['hidden_controlled']['total']);
    }

    public function test_the_day_follows_the_per_person_gate(): void
    {
        $stranger = $this->staff(['clients.viewAssigned', 'medications.view']);
        $this->actingAs($stranger)
            ->getJson(route('emar.clients.day', ['client' => $this->aroha->id]))
            ->assertNotFound();

        $noMedicationAccess = $this->staff(['clients.viewAssigned']);
        $this->aroha->supportWorkers()->attach($noMedicationAccess->id);
        $this->actingAs($noMedicationAccess)
            ->getJson(route('emar.clients.day', ['client' => $this->aroha->id]))
            ->assertForbidden();

        $elsewhere = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id]);
        $this->actingAs($this->reader)
            ->getJson(route('emar.clients.day', ['client' => $elsewhere->id]))
            ->assertNotFound();
    }

    public function test_days_step_back_to_the_projections_coverage_and_forward_to_tomorrow(): void
    {
        $this->order('Metformin', ['08:00', '20:00']);
        $this->at('2026-06-15 00:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $this->at('2026-06-15 10:00');

        $tomorrow = $this->day('2026-06-16');
        $this->assertTrue($tomorrow['coverage']['complete']);
        $this->assertSame(['upcoming', 'upcoming'], array_column($this->cells($tomorrow), 'status'));

        $this->actingAs($this->reader)
            ->getJson(route('emar.clients.day', ['client' => $this->aroha->id, 'date' => '2026-06-17']))
            ->assertUnprocessable();

        // Before the projection began: its notice, never a grid read from today's orders.
        $from = $this->day()['coverage']['available_from'];
        $before = $this->day(Carbon::parse($from)->subDay()->toDateString());
        $this->assertFalse($before['coverage']['complete']);
        $this->assertSame($from, $before['coverage']['available_from']);
        $this->assertSame([], $before['medicines']);
        $this->assertTrue($this->day($from)['coverage']['complete']);
    }

    public function test_recording_is_offered_only_to_someone_who_may_record_for_the_person_now(): void
    {
        $this->order('Metformin', ['08:00']);
        $this->at('2026-06-15 00:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $this->at('2026-06-15 08:10');

        $day = $this->day();
        $this->assertFalse($day['can']['record']);
        $this->assertSame('no_permission', $day['can']['record_reason']);
        $this->assertNull($day['recorder']);

        $this->grant($this->reader, ['medications.administer.record']);
        $day = $this->day();
        $this->assertFalse($day['can']['record']);
        $this->assertSame('no_shift', $day['can']['record_reason']);

        $this->clockedIn($this->reader);
        $day = $this->day();
        $this->assertTrue($day['can']['record']);
        $this->assertNull($day['can']['record_reason']);
        $this->assertSame($this->aroha->id, $day['recorder']['client']['id']);
    }

    /** @return array<string, mixed> */
    public function test_prn_day_counts_reject_foreign_person_evidence_on_today_and_an_earlier_day(): void
    {
        $order = $this->order('Paracetamol', [], overrides: ['is_prn' => true]);
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        foreach (['2026-06-14', '2026-06-15'] as $date) {
            foreach ([[$this->aroha->id, '09:00'], [$other->id, '13:00']] as [$person, $time]) {
                ClientMedicationAdministration::query()->create(['client_id' => $person, 'client_medication_id' => $order->id, 'administered_by' => $this->reader->id, 'administered_at' => Carbon::parse($date.' '.$time, 'Pacific/Auckland')->utc(), 'status' => 'given']);
            }
        }
        $this->at('2026-06-15 14:00');
        foreach (['2026-06-14', '2026-06-15'] as $date) {
            $row = $this->day($date)['prn']['rows'][0];
            $this->assertSame(1, $row['given_on_day']);
            $this->assertSame('09:00', $row['last_given_on_day']);
        }
    }

    public function test_a_past_day_keeps_stopped_and_superseded_orders_and_their_doses(): void
    {
        $scheduled = $this->order('Old scheduled', ['08:00'], overrides: ['dosage' => '5 mg']);
        $prn = $this->order('Old PRN', [], overrides: ['is_prn' => true]);
        $replaced = $this->order('Replacement history', ['10:00'], overrides: ['dosage' => '2 tablets']);
        $this->at('2026-06-14 00:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $this->record($scheduled, '2026-06-14 08:00', 'given');
        ClientMedicationAdministration::query()->create(['client_id' => $this->aroha->id, 'client_medication_id' => $prn->id, 'administered_by' => $this->reader->id, 'administered_at' => Carbon::parse('2026-06-14 11:00', 'Pacific/Auckland')->utc(), 'status' => 'given']);
        $this->at('2026-06-15 07:00');
        foreach ([$scheduled, $prn] as $order) $order->update(['active' => false, 'state' => 'ceased', 'ceased_at' => now(), 'ceased_reason' => 'Written stop', 'ceased_by' => $this->reader->id]);
        $replacement = $replaced->createVersion($this->reader->id, 'Written change');
        $replacement->update(['dosage' => '3 tablets']);
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $day = $this->day('2026-06-14');
        $this->assertSame('given', $this->cells($day)['Old scheduled 08:00']['status']);
        $this->assertSame('5 mg', $this->cells($day)['Old scheduled 08:00']['dose']);
        $this->assertSame('2 tablets', $this->cells($day)['Replacement history 10:00']['dose']);
        $this->assertSame('Old PRN', $day['prn']['rows'][0]['name']);
        $this->assertSame(1, $day['prn']['rows'][0]['given_on_day']);
        $this->assertNotContains('Old scheduled', array_column($this->day()['medicines'], 'name'));
    }

    /** @return array<string, mixed> */
    private function day(?string $date = null): array
    {
        return $this->actingAs($this->reader->fresh())
            ->getJson(route('emar.clients.day', array_filter(['client' => $this->aroha->id, 'date' => $date])))
            ->assertOk()
            ->json();
    }

    /**
     * Every dose keyed "{medicine} {time}".
     *
     * @param  array<string, mixed>  $day
     * @return array<string, array<string, mixed>>
     */
    private function cells(array $day): array
    {
        $cells = [];
        foreach ($day['medicines'] as $medicine) {
            foreach ($medicine['cells'] as $time => $doses) {
                $cells[$medicine['name'].' '.$time] = $doses[0];
            }
        }

        return $cells;
    }

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
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

    /** A clocked-in shift covering Aroha now (recording authority). */
    private function clockedIn(User $worker): void
    {
        Shift::factory()->create([
            'client_id' => $this->aroha->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'user_id' => $worker->id,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->addHours(4),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'status' => 'in_progress',
            'started_by' => $worker->id,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function grant(User $user, array $permissions): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->grant($user, $permissions);
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
