<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationRound;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\MedicationOverdueNotification;
use App\Services\Medication\MedicationSignalService;
use App\Services\MedicationAlertService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * C6(f): the overdue job and the Control Room overdue alert read the
 * dose-slot projection — a dose is overdue once its window has ended with
 * nothing recorded, exactly as Meds today, My Day and the badge say — and an
 * alert resolves itself once its doses are recorded or no longer owed.
 *
 * Monday 15 June 2026 (NZST). Window 30 before / 60 after. Orders entered at
 * the start of the day.
 */
class OverdueDoseAlertsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ServiceContext $context;

    private Client $aroha;

    private Client $ben;

    /** @var array<string, ClientMedication> */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        // The schema dump records this migration as run without its rows:
        // replay it so signals route through the seeded overdue rule (one
        // alert per person within its 30-minute grouping window).
        (require database_path('migrations/2026_04_10_240000_seed_medication_signal_types_and_rules.php'))->up();
        Cache::flush();
        $this->at('2026-06-15 00:00');
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->context = ServiceContext::factory()->create(['name' => 'Overdue', 'type' => 'residential', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->aroha = $this->person('Aroha', 'Ngata');
        $this->ben = $this->person('Ben', 'Tane');

        $this->orders['Metformin'] = $this->order($this->aroha, 'Metformin', ['07:00']);
        $this->orders['Vitamin D'] = $this->order($this->aroha, 'Vitamin D', ['08:00']);
        $this->orders['Morphine'] = $this->order($this->aroha, 'Morphine', ['08:00'], ['controlled_drug' => true]);
        $this->orders['Paracetamol'] = $this->order($this->aroha, 'Paracetamol', ['08:30']);
        $this->orders['Iron'] = $this->order($this->aroha, 'Iron', ['09:00']);

        // Changed at 07:00: its 08:30 dose waits for the order check.
        $this->at('2026-06-15 07:00');
        $this->orders['Paracetamol']->update(['dosage' => '2 tablets']);
        $this->at('2026-06-15 08:05');
        $this->record('Vitamin D', '08:00', 'given');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_job_raises_the_doses_meds_today_calls_overdue_once_each(): void
    {
        $worker = $this->workerWithAroha();

        // 8:30: only the 7:00 dose's window has ended.
        $this->at('2026-06-15 08:30');
        $this->artisan('emar:send-alerts')->assertSuccessful();
        $this->assertSame(['Metformin 07:00'], $this->overdueSignalDoses());

        // 9:30: the 8:00 Morphine window has ended too. Vitamin D was given,
        // Paracetamol waits for the order check, Iron is in its window.
        $this->at('2026-06-15 09:30');
        $this->artisan('emar:send-alerts')->assertSuccessful();
        $this->assertSame(['Metformin 07:00', 'Morphine 08:00'], $this->overdueSignalDoses());

        // Meds today calls the same doses overdue.
        $board = collect($this->actingAs($worker)->get('/meds/today')->assertOk()->inertiaProps('schedule'))
            ->where('status', 'overdue')
            ->map(fn (array $row): string => $row['medication_name'].' '.$row['time'])
            ->sort()->values()->all();
        $this->assertSame(['Metformin 07:00', 'Morphine 08:00'], $board);

        // Each dose raised once: running again changes nothing.
        $signals = Signal::query()->count();
        $alerts = ControlRoomAlert::query()->count();
        $this->at('2026-06-15 09:45');
        Cache::flush();
        $this->artisan('emar:send-alerts')->assertSuccessful();
        $this->assertSame($signals, Signal::query()->count());
        $this->assertSame($alerts, ControlRoomAlert::query()->count());

        // The dashboard row counts both, naming no controlled medicine.
        $this->assertSame('2 overdue dose(s): Metformin, Controlled medicine', $this->dashboardRow($this->aroha)?->message);
        $this->assertStringNotContainsString('Morphine', ControlRoomAlert::query()->get()->toJson());
        $this->assertStringNotContainsString('Morphine', Signal::query()->get()->toJson());
    }

    public function test_a_persons_doses_overdue_together_are_one_alert_and_two_people_are_two(): void
    {
        $this->order($this->ben, 'Sertraline', ['07:30'], enteredNz: '2026-06-15 00:00');
        $this->at('2026-06-15 09:30');

        $this->artisan('emar:send-alerts')->assertSuccessful();

        $alerts = $this->openAlerts();
        $this->assertSame([$this->aroha->id, $this->ben->id], $alerts->pluck('client_id')->sort()->values()->all());
        // No signal left stuck: Ben's dose isn't grouped into Aroha's alert.
        $this->assertSame(0, Signal::query()->where('status', 'pending')->count());
        $this->assertSame(3, Signal::query()->where('signal_type_code', MedicationSignalService::TYPE_OVERDUE)->count());
    }

    public function test_an_alert_resolves_itself_once_its_doses_are_recorded(): void
    {
        $this->at('2026-06-15 09:30');
        $this->artisan('emar:send-alerts')->assertSuccessful();
        $alert = $this->openAlerts()->sole();

        // Metformin given late: Morphine is still overdue, so it stays open,
        // and the dashboard row's count follows straight away.
        $this->at('2026-06-15 09:40');
        $this->record('Metformin', '07:00', 'given');
        $this->assertTrue($alert->fresh()->isActionable());
        $this->assertSame('1 overdue dose(s): Controlled medicine', $this->dashboardRow($this->aroha)?->message);

        // Morphine recorded as missed: nothing of the alert's is overdue now.
        $this->at('2026-06-15 09:45');
        $this->record('Morphine', '08:00', 'missed');
        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->status);
        $this->assertSame('medication_workflow', $alert->resolution_code);
        $this->assertSame('dose_slot_projection', data_get($alert->context, 'resolution.source'));
        $this->assertNull($this->dashboardRow($this->aroha));
    }

    public function test_a_dose_overdue_again_after_its_record_is_deleted_raises_one_new_alert(): void
    {
        // 8:30: Metformin's 7:00 dose is overdue — one alert.
        $this->at('2026-06-15 08:30');
        $this->artisan('emar:send-alerts')->assertSuccessful();
        $first = $this->openAlerts()->sole();

        // Recorded: the alert resolves and the dose is released.
        $this->at('2026-06-15 08:35');
        $record = $this->record('Metformin', '07:00', 'given');
        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $first->fresh()->status);

        // The record is deleted: the dose is overdue again — a new spell,
        // exactly one new alert.
        $this->at('2026-06-15 08:40');
        $record->delete();
        $this->at('2026-06-15 08:45');
        $this->artisan('emar:send-alerts')->assertSuccessful();
        $second = $this->openAlerts()->sole();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $this->overdueAlertCount());

        // Each run after that raises nothing more.
        $this->at('2026-06-15 08:55');
        Cache::flush();
        $this->artisan('emar:send-alerts')->assertSuccessful();
        $this->assertSame(2, $this->overdueAlertCount());
        $this->assertSame(2, Signal::query()->where('signal_type_code', MedicationSignalService::TYPE_OVERDUE)->count());
        $this->assertTrue($second->fresh()->isActionable());
    }

    public function test_ceasing_the_order_resolves_its_overdue_alert(): void
    {
        // 8:30: only Metformin's 7:00 dose is overdue.
        $this->at('2026-06-15 08:30');
        $this->artisan('emar:send-alerts')->assertSuccessful();
        $alert = $this->openAlerts()->sole();

        // Ceased: its doses are no longer owed, and Meds today stops listing them.
        $this->at('2026-06-15 08:40');
        $this->orders['Metformin']->update([
            'state' => 'ceased',
            'active' => false,
            'ceased_at' => now(),
            'ceased_reason' => 'Stopped by the GP.',
            'ceased_by' => User::factory()->create(['approved_at' => now()])->id,
        ]);

        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
        $this->assertNull($this->dashboardRow($this->aroha));
    }

    public function test_suppressing_a_persons_alerts_resolves_their_overdue_alert(): void
    {
        $this->at('2026-06-15 09:30');
        $this->artisan('emar:send-alerts')->assertSuccessful();
        $alert = $this->openAlerts()->sole();

        $this->aroha->forceFill(['suppress_med_admin_alerts' => true, 'med_alerts_suppressed_reason' => 'Paper chart today.'])->save();
        app(MedicationAlertService::class)->generateClientAlerts($this->aroha->fresh());

        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
        $this->assertNull($this->dashboardRow($this->aroha));
    }

    public function test_the_round_assignee_is_told_once_per_dose(): void
    {
        $worker = $this->staff();
        MedicationRound::query()->create([
            'site_id' => $this->site->id,
            'name' => 'Morning round',
            'round_type' => 'morning',
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'round_date' => '2026-06-15',
            'status' => 'pending',
            'assigned_to' => $worker->id,
        ]);

        $this->at('2026-06-15 09:30');
        $this->artisan('emar:send-alerts')->assertSuccessful();
        // A deploy clears the cache; the stored notification still counts.
        Cache::flush();
        $this->at('2026-06-15 09:45');
        $this->artisan('emar:send-alerts')->assertSuccessful();

        $told = $worker->notifications()->where('type', MedicationOverdueNotification::class)->get()
            ->map(fn ($notification): string => $notification->data['medication'].' '.$notification->data['scheduled_time'])
            ->sort()->values()->all();
        $this->assertSame(['Metformin 07:00', 'Morphine 08:00'], $told);
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

    /** @return list<string> "Name HH:MM" of every overdue signal, sorted */
    private function overdueSignalDoses(): array
    {
        $names = collect($this->orders)->mapWithKeys(fn (ClientMedication $order, string $name): array => [$order->id => $name]);

        return Signal::query()
            ->where('signal_type_code', MedicationSignalService::TYPE_OVERDUE)
            ->get()
            ->map(fn (Signal $signal): string => $names[(int) data_get($signal->normalized_data, 'client_medication_id')]
                .' '.Carbon::parse(data_get($signal->normalized_data, 'due_at'))->timezone('Pacific/Auckland')->format('H:i'))
            ->sort()->values()->all();
    }

    /** @return \Illuminate\Support\Collection<int, ControlRoomAlert> */
    private function openAlerts()
    {
        return ControlRoomAlert::query()
            ->unresolved()
            ->where('source', 'medication')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.signal_type_code')) = ?", [MedicationSignalService::TYPE_OVERDUE])
            ->get();
    }

    private function overdueAlertCount(): int
    {
        return ControlRoomAlert::query()
            ->where('source', 'medication')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.signal_type_code')) = ?", [MedicationSignalService::TYPE_OVERDUE])
            ->count();
    }

    private function dashboardRow(Client $client): ?MedicationDashboardAlert
    {
        return MedicationDashboardAlert::query()
            ->where('client_id', $client->id)
            ->where('alert_type', 'overdue')
            ->where('status', 'active')
            ->first();
    }

    private function record(string $order, string $dueNz, string $status): ClientMedicationAdministration
    {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $this->orders[$order]->client_id,
            'client_medication_id' => $this->orders[$order]->id,
            'administered_by' => User::factory()->create(['approved_at' => now()])->id,
            'scheduled_for' => $this->nz("2026-06-15 {$dueNz}"),
            'administered_at' => now(),
            'status' => $status,
            'reason' => $status === 'given' ? null : 'Recorded for the test.',
        ]);
    }

    private function person(string $first, string $last): Client
    {
        return Client::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'status' => 'active',
            'suppress_med_admin_alerts' => false,
        ]);
    }

    /**
     * @param  list<string>  $doseTimes
     * @param  array<string, mixed>  $overrides
     */
    private function order(Client $client, string $name, array $doseTimes, array $overrides = [], ?string $enteredNz = null): ClientMedication
    {
        $now = Carbon::getTestNow();
        if ($enteredNz !== null) {
            $this->at($enteredNz);
        }
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

    /** A support worker clocked in with Aroha, whom they support. */
    private function workerWithAroha(): User
    {
        $worker = $this->staff();
        $this->aroha->supportWorkers()->attach($worker->id);
        Shift::factory()->create([
            'client_id' => $this->aroha->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'user_id' => $worker->id,
            'starts_at' => $this->nz('2026-06-15 07:00'),
            'ends_at' => $this->nz('2026-06-15 15:00'),
            'actual_starts_at' => $this->nz('2026-06-15 07:00'),
            'status' => 'in_progress',
        ]);

        return $worker->fresh();
    }

    private function staff(): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
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
