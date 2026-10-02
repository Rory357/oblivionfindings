<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\GuidedRoundService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C6(j): a round's doses are the dose-slot projection's — every scheduled
 * dose of the round's people due inside the round's own window (its time ±
 * window_minutes) — each with its record wherever it was made, else the
 * state Meds today shows. A dose waiting for the order check is shown but
 * doesn't hold the round open. The round's time is NZ wall-clock for its
 * overdue check, the reports' on-time count and My Calendar.
 *
 * Monday 15 June 2026 (NZST, UTC+12). Default dose window: 30 before, 60
 * after the dose time.
 */
class RoundDoseSlotsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $aroha;

    private Client $ben;

    private User $worker;

    private ClientMedication $metformin;

    private ClientMedication $iron;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->at('2026-06-15 00:00');
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $this->aroha = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $this->site->id, 'status' => 'active']);
        $this->ben = Client::factory()->create(['first_name' => 'Ben', 'last_name' => 'Parata', 'site_id' => $this->site->id, 'status' => 'active']);
        $this->worker = User::factory()->create(['approved_at' => now()]);
        $this->metformin = $this->order($this->aroha, 'Metformin', ['08:00', '20:00']);
        $this->iron = $this->order($this->ben, 'Iron', ['08:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_round_lists_its_windows_doses_with_their_records_wherever_made(): void
    {
        $round = $this->round('08:00', 60, ['status' => 'in_progress', 'started_by' => $this->worker->id]);

        // Iron given from Meds today, outside the round.
        $this->at('2026-06-15 08:10');
        $this->record($this->iron, '2026-06-15 08:00', 'given');

        $items = $this->service()->items($round->fresh(), true);
        $this->assertSame(['Metformin', 'Iron'], array_column($items, 'medication_name'));
        $this->assertSame(['due', 'given'], array_column($items, 'dose_state'));
        $this->assertNull($items[0]['administration']);
        $this->assertSame('given', $items[1]['administration']['status']);
        $this->assertFalse($this->service()->canCompleteCanonicalRound($round->fresh()));

        // Metformin recorded in the round: nothing left to do.
        $this->record($this->metformin, '2026-06-15 08:00', 'given', $round);
        $this->assertSame(
            ['total' => 2, 'completed' => 2, 'pending' => 0, 'given' => 2, 'refused' => 0, 'held' => 0, 'next_index' => null, 'percent' => 100],
            $this->service()->progress($round->fresh(), true),
        );
        $this->assertTrue($this->service()->canCompleteCanonicalRound($round->fresh()));

        // Completed, it still shows both: its own record and the dose given
        // outside it before completion.
        $this->at('2026-06-15 08:30');
        $round->forceFill(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $this->worker->id])->save();
        $this->assertSame(['Metformin', 'Iron'], array_column($this->service()->items($round->fresh(), true), 'medication_name'));
    }

    public function test_the_round_covers_the_doses_inside_its_own_window(): void
    {
        $this->order($this->aroha, 'Early', ['07:20']);
        $this->at('2026-06-15 07:00');

        $this->assertSame(
            ['Early', 'Metformin', 'Iron'],
            array_column($this->service()->items($this->round('08:00', 60), true), 'medication_name'),
        );
        $this->assertSame(
            ['Metformin', 'Iron'],
            array_column($this->service()->items($this->round('08:00', 30), true), 'medication_name'),
        );
        $this->assertSame(
            ['Metformin'],
            array_column($this->service()->items($this->round('20:00', 60), true), 'medication_name'),
        );
    }

    public function test_an_unrecorded_dose_reads_as_meds_today_does(): void
    {
        $round = $this->round('08:00', 60, ['status' => 'in_progress', 'started_by' => $this->worker->id]);

        $this->at('2026-06-15 08:30');
        $this->assertSame(['due', 'due'], array_column($this->service()->cells($round->fresh(), true), 'status'));

        // The dose's window (07:30–09:00) has ended with nothing recorded.
        $this->at('2026-06-15 09:30');
        $this->assertSame(['overdue', 'overdue'], array_column($this->service()->cells($round->fresh(), true), 'status'));
    }

    public function test_a_dose_waiting_for_the_order_check_is_shown_but_does_not_hold_the_round_open(): void
    {
        // Changed at 07:00; the change waits for its check.
        $this->at('2026-06-15 07:00');
        $this->metformin->update(['dosage' => '2 tablets']);
        $round = $this->round('08:00', 60, ['status' => 'in_progress', 'started_by' => $this->worker->id]);

        $this->at('2026-06-15 08:15');
        $this->assertSame(['pending_check', 'due'], array_column($this->service()->cells($round->fresh(), true), 'status'));

        $this->record($this->iron, '2026-06-15 08:00', 'given', $round);
        $progress = $this->service()->progress($round->fresh(), true);
        $this->assertSame([2, 1, 0, null], [$progress['total'], $progress['completed'], $progress['pending'], $progress['next_index']]);
        $this->assertTrue($this->service()->canCompleteCanonicalRound($round->fresh()));
    }

    public function test_a_round_is_overdue_once_its_nz_window_has_ended(): void
    {
        $round = $this->round('08:00', 60);

        $this->at('2026-06-15 08:59');
        $this->assertFalse($round->fresh()->isOverdue());
        $this->at('2026-06-15 09:01');
        $this->assertTrue($round->fresh()->isOverdue());
        $this->assertSame('2026-06-14T21:00:00+00:00', $round->fresh()->windowEndsAt()->utc()->toIso8601String());
    }

    public function test_reports_count_a_round_completed_after_its_nz_window_as_late(): void
    {
        $this->round('08:00', 60, ['status' => 'completed', 'completed_at' => Carbon::parse('2026-06-15 08:50', 'Pacific/Auckland')->utc()]);
        // Completed 13:30 NZST: 01:30 UTC, before 13:00 as UTC wall-clock.
        $this->round('12:00', 60, ['status' => 'completed', 'completed_at' => Carbon::parse('2026-06-15 13:30', 'Pacific/Auckland')->utc()]);
        $this->at('2026-06-15 18:00');

        $summary = $this->actingAs($this->reader())
            ->get(route('emar.reports', ['date_from' => '2026-06-15', 'date_to' => '2026-06-15']))
            ->assertOk()
            ->inertiaProps('roundCompletion.summary');

        $this->assertSame([2, 2, 50.0, 50.0], [$summary['total'], $summary['completed'], (float) $summary['on_time_pct'], (float) $summary['late_pct']]);
    }

    public function test_my_calendar_shows_a_round_at_its_nz_time(): void
    {
        $round = $this->round('08:00', 60, ['assigned_to' => $this->worker->id]);

        $events = $this->actingAs($this->worker)
            ->getJson(route('my-calendar.events', ['start' => '2026-06-15T00:00:00+12:00', 'end' => '2026-06-16T00:00:00+12:00']))
            ->assertOk()
            ->json();

        $event = collect($events)->firstWhere('id', 'med-round-'.$round->id);
        $this->assertNotNull($event);
        $this->assertSame('2026-06-14T20:00:00+00:00', Carbon::parse($event['start'])->utc()->toIso8601String());
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function service(): GuidedRoundService
    {
        return app(GuidedRoundService::class);
    }

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
    }

    /** @param array<string, mixed> $overrides */
    private function round(string $time, int $windowMinutes, array $overrides = []): MedicationRound
    {
        return MedicationRound::query()->create(array_merge([
            'site_id' => $this->site->id,
            'service_context_id' => null,
            'name' => 'Round '.$time,
            'round_type' => 'scheduled',
            'scheduled_time' => $time,
            'window_minutes' => $windowMinutes,
            'round_date' => '2026-06-15',
            'status' => 'pending',
            'total_medications' => 0,
            'administered_count' => 0,
            'refused_count' => 0,
            'withheld_count' => 0,
            'missed_count' => 0,
        ], $overrides));
    }

    /** @param list<string> $doseTimes */
    private function order(Client $client, string $name, array $doseTimes): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => '2026-06-01',
        ]);
    }

    private function record(ClientMedication $order, string $dueNz, string $status, ?MedicationRound $round = null): ClientMedicationAdministration
    {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $order->client_id,
            'client_medication_id' => $order->id,
            'medication_round_id' => $round?->id,
            'administered_by' => $this->worker->id,
            'scheduled_for' => Carbon::parse($dueNz, 'Pacific/Auckland')->utc(),
            'administered_at' => now(),
            'status' => $status,
        ]);
    }

    /** A reports reader across all Sites (admin role). */
    private function reader(): User
    {
        $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', ['medications.reports.export', 'medications.view'])->pluck('id')
                ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
                ->all(),
        );

        return $user->fresh();
    }
}
