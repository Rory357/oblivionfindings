<?php

namespace Tests\Feature\Emar;

use App\Console\Commands\BackfillMedicationDoseSlots;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDoseOrderPause;
use App\Models\MedicationDoseScheduleVersion;
use App\Models\MedicationDoseSlot;
use App\Models\MedicationDoseSlotBackfill;
use App\Models\User;
use App\Services\Medication\DoseSlots\DoseSlotBackfill;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P01 foundation C5: the backfill rebuilds past dose slots and outcomes from
 * the orders' history (audit log, recorded versions and pauses) and the doses
 * recorded. Idempotent, resumable, never rewrites a live outcome.
 *
 * Orders are built by editing them over time, as staff did; "forgetting the
 * live history" then removes what C3 recorded, leaving the audit log and the
 * records — an order from before dose slots existed. NZ winter (UTC+12):
 * 08:00 NZ is 20:00 UTC the day before.
 */
class DoseSlotBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at('2026-06-01 07:00');
        $this->client = Client::factory()->create(['first_name' => 'Aroha', 'status' => 'active']);
        $this->recorder = User::factory()->create(['approved_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_slots_and_outcomes_are_rebuilt_from_the_order_and_its_records(): void
    {
        $this->at('2026-06-01 09:00');
        $order = $this->order(['08:00', '20:00']);
        $given = $this->record($order, '2026-06-02 08:00', 'given', '2026-06-02 08:10');
        $this->record($order, '2026-06-02 20:00', 'refused', '2026-06-02 20:05');
        $this->record($order, '2026-06-03 08:00', 'withheld', '2026-06-03 08:00', 'hospitalised');
        $this->forgetLiveHistory();

        $this->at('2026-06-04 12:00');
        $this->backfill(['--to' => '2026-06-03', '--months' => 1]);

        // Not before the order was entered (09:00 on the 1st).
        $this->assertSame([
            '2026-06-01 20:00 -',
            '2026-06-02 08:00 given',
            '2026-06-02 20:00 refused',
            '2026-06-03 08:00 away',
            '2026-06-03 20:00 -',
        ], $this->slots($order));

        $slot = MedicationDoseSlot::query()->where('nz_date', '2026-06-02')->where('ordered_time', '08:00')->sole();
        $this->assertTrue($slot->reconstructed);
        $this->assertNull($slot->schedule_version_id);
        $this->assertSame((int) $given->id, (int) $slot->outcome_administration_id);
        $this->assertSame('2026-06-01 20:00:00', $slot->getRawOriginal('due_at'));
        $this->assertSame('2026-06-01 20:10:00', $slot->getRawOriginal('outcome_at'));

        $run = MedicationDoseSlotBackfill::query()->sole();
        $this->assertSame(MedicationDoseSlotBackfill::STATUS_COMPLETED, $run->status);
        $this->assertSame(['2026-05-04', '2026-06-03'], [$run->from_date->toDateString(), $run->to_date->toDateString()]);
        $this->assertSame([1, 5, 3, 0], [$run->orders_done, $run->slots_created, $run->outcomes_written, $run->records_without_slot]);

        // The projection reads them, marked as rebuilt.
        $rows = app(DoseSlotProjection::class)->rows(DoseSlotReaderScope::internal(), '2026-06-01', '2026-06-03', CarbonImmutable::now());
        $this->assertCount(5, $rows);
        $this->assertTrue($rows->every(fn (array $row): bool => $row['reconstructed'] === true));
        $this->assertSame(['not_recorded', 'given', 'refused', 'away', 'not_recorded'], $rows->pluck('state')->all());
    }

    public function test_running_it_again_writes_nothing(): void
    {
        $this->at('2026-06-01 07:00');
        $order = $this->order(['08:00', '20:00']);
        $this->record($order, '2026-06-02 08:00', 'given', '2026-06-02 08:10');
        $this->forgetLiveHistory();

        $this->at('2026-06-04 12:00');
        $this->backfill(['--to' => '2026-06-03', '--months' => 1]);
        $before = $this->snapshot();
        $this->assertCount(6, $before);

        $this->at('2026-06-05 12:00');
        $this->backfill(['--to' => '2026-06-03', '--months' => 1]);
        $this->backfill(['--to' => '2026-06-03', '--months' => 1, '--fresh' => true]);

        $this->assertSame($before, $this->snapshot());
        $runs = MedicationDoseSlotBackfill::query()->orderBy('id')->get();
        $this->assertCount(3, $runs);
        foreach ($runs->slice(1) as $run) {
            $this->assertSame(MedicationDoseSlotBackfill::STATUS_COMPLETED, $run->status);
            $this->assertSame([0, 0], [$run->slots_created, $run->outcomes_written]);
        }
    }

    public function test_an_interrupted_run_resumes_where_it_stopped(): void
    {
        $this->at('2026-05-01 07:00');
        $orders = [
            $this->order(['08:00']),
            $this->order(['08:00', '20:00']),
            $this->order(['12:00']),
        ];
        foreach ($orders as $order) {
            $this->record($order, '2026-05-10 '.$order->dose_times[0], 'given', '2026-05-10 '.$order->dose_times[0]);
        }
        $this->forgetLiveHistory();
        $this->at('2026-06-01 12:00');

        // Stopped after the first order…
        $this->backfill(['--to' => '2026-05-31', '--months' => 1, '--limit-orders' => 1, '--chunk-days' => 7]);
        $run = MedicationDoseSlotBackfill::query()->sole();
        $this->assertSame(MedicationDoseSlotBackfill::STATUS_RUNNING, $run->status);
        $this->assertSame([$orders[0]->id, 1], [(int) $run->last_order_id, $run->orders_done]);
        $this->assertSame(0, MedicationDoseSlot::query()->where('client_medication_id', $orders[1]->id)->count());

        // …then died part-way through the second: its first batches committed
        // (with their counts, in the same transactions), the cursor didn't move.
        app(DoseSlotBackfill::class)->order($orders[1], '2026-05-01', '2026-05-14', CarbonImmutable::now(), afterBatch: fn (array $batch) => MedicationDoseSlotBackfill::query()->whereKey($run->id)->incrementEach([
            'slots_created' => $batch['slots'],
            'outcomes_written' => $batch['outcomes'],
        ]));
        $this->assertSame(28, MedicationDoseSlot::query()->where('client_medication_id', $orders[1]->id)->count());

        // Run again: same run, from the cursor, to the end.
        $this->backfill(['--to' => '2026-05-31', '--months' => 1, '--chunk-days' => 7]);
        $run->refresh();
        $this->assertSame(1, MedicationDoseSlotBackfill::query()->count());
        $this->assertSame(MedicationDoseSlotBackfill::STATUS_COMPLETED, $run->status);
        $this->assertSame([$orders[2]->id, 3], [(int) $run->last_order_id, $run->orders_done]);
        $this->assertSame(
            [31, 62, 31],
            array_map(fn (ClientMedication $order): int => MedicationDoseSlot::query()->where('client_medication_id', $order->id)->count(), $orders),
        );
        $this->assertSame(124, $run->slots_created);
        $this->assertSame(3, $run->outcomes_written);
        $this->assertSame(3, MedicationDoseSlot::query()->whereNotNull('outcome')->count());
    }

    public function test_a_correction_approved_after_the_original_is_the_outcome(): void
    {
        $this->at('2026-06-01 07:00');
        $order = $this->order(['08:00']);
        $original = $this->record($order, '2026-06-02 08:00', 'given', '2026-06-02 08:10');
        $this->at('2026-06-02 15:00');
        $approved = $this->correction($original, 'refused');
        $approved->update(['correction_status' => 'approved', 'correction_approved_at' => now(), 'correction_approved_by' => $this->recorder->id]);
        $kept = $this->record($order, '2026-06-03 08:00', 'given', '2026-06-03 08:05');
        $this->at('2026-06-03 15:00');
        $pending = $this->correction($kept, 'missed');
        $this->forgetLiveHistory();

        $this->at('2026-06-05 12:00');
        $this->backfill(['--to' => '2026-06-04', '--months' => 1]);

        $this->assertSame(['2026-06-01 08:00 -', '2026-06-02 08:00 refused', '2026-06-03 08:00 given', '2026-06-04 08:00 -'], $this->slots($order));
        $this->assertSame((int) $approved->id, $this->outcomeRecord($order, '2026-06-02'));
        $this->assertSame((int) $kept->id, $this->outcomeRecord($order, '2026-06-03'));

        // Approved after the backfill: the live writer updates the rebuilt slot,
        // and running the backfill again doesn't undo it.
        $this->at('2026-06-05 13:00');
        $pending->update(['correction_status' => 'approved', 'correction_approved_at' => now(), 'correction_approved_by' => $this->recorder->id]);
        $this->assertSame('2026-06-03 08:00 missed', $this->slots($order)[2]);
        $this->backfill(['--to' => '2026-06-04', '--months' => 1, '--fresh' => true]);
        $this->assertSame('2026-06-03 08:00 missed', $this->slots($order)[2]);
        $this->assertSame((int) $pending->id, $this->outcomeRecord($order, '2026-06-03'));
    }

    public function test_a_paused_order_owes_nothing_while_paused(): void
    {
        $this->at('2026-06-01 07:00');
        $order = $this->order(['08:00']);
        $this->at('2026-06-05 10:00');
        $order->update(['state' => 'paused', 'active' => false, 'paused_at' => now()]);
        $this->at('2026-06-08 10:00');
        $order->update(['state' => 'active', 'active' => true, 'paused_at' => null]);
        $this->forgetLiveHistory();

        $this->at('2026-06-11 12:00');
        $this->backfill(['--to' => '2026-06-10', '--months' => 1]);

        // The 5th's dose was due before the pause; the 8th's while still paused.
        $this->assertSame([
            '2026-06-01 08:00 -', '2026-06-02 08:00 -', '2026-06-03 08:00 -', '2026-06-04 08:00 -',
            '2026-06-05 08:00 -', '2026-06-09 08:00 -', '2026-06-10 08:00 -',
        ], $this->slots($order));
    }

    public function test_a_ceased_order_owes_nothing_after_it_was_ceased(): void
    {
        $this->at('2026-06-01 07:00');
        $order = $this->order(['08:00', '20:00']);
        $this->record($order, '2026-06-03 08:00', 'given', '2026-06-03 08:00');
        $this->at('2026-06-03 12:00');
        $order->update([
            'state' => 'ceased',
            'active' => false,
            'ceased_at' => now(),
            'ceased_reason' => 'Course finished',
            'ceased_by' => $this->recorder->id,
        ]);
        $this->forgetLiveHistory();

        $this->at('2026-06-06 12:00');
        $this->backfill(['--to' => '2026-06-05', '--months' => 1]);

        $this->assertSame([
            '2026-06-01 08:00 -', '2026-06-01 20:00 -',
            '2026-06-02 08:00 -', '2026-06-02 20:00 -',
            '2026-06-03 08:00 given',
        ], $this->slots($order));
    }

    public function test_an_edited_order_follows_the_version_in_effect(): void
    {
        $this->at('2026-06-01 07:00');
        $order = $this->order(['08:00']);
        // Edited on the 3rd (back for verification), verified on the 4th at 10:00.
        $this->at('2026-06-03 10:00');
        $order->update(['dose_times' => ['09:00']]);
        $this->at('2026-06-04 10:00');
        $order->update(['approval_status' => 'verified', 'verified_at' => now(), 'verified_by' => $this->recorder->id]);
        // A later edit that was rejected never takes effect.
        $this->at('2026-06-05 10:00');
        $order->update(['dose_times' => ['21:00']]);
        $this->at('2026-06-05 11:00');
        $order->update(['approval_status' => 'rejected', 'rejection_reason' => 'Wrong time']);
        $this->forgetLiveHistory();

        $this->at('2026-06-07 12:00');
        $this->backfill(['--to' => '2026-06-06', '--months' => 1]);

        $this->assertSame([
            '2026-06-01 08:00 -', '2026-06-02 08:00 -', '2026-06-03 08:00 -', '2026-06-04 08:00 -',
            '2026-06-05 09:00 -', '2026-06-06 09:00 -',
        ], $this->slots($order));
        // Due while the change waited for verification.
        $this->assertTrue(MedicationDoseSlot::query()->where('nz_date', '2026-06-04')->sole()->order_change_pending);
        $this->assertFalse(MedicationDoseSlot::query()->where('nz_date', '2026-06-03')->sole()->order_change_pending);
    }

    public function test_a_live_outcome_is_never_rewritten(): void
    {
        $this->at('2026-06-01 07:00');
        $order = $this->order(['08:00', '20:00']);
        // Live: recorded through the model, the live writer gave the slot its outcome.
        $live = $this->record($order, '2026-06-01 08:00', 'given', '2026-06-01 08:05');
        // Records the live writer never saw (written around the model).
        $this->rawRecord($order, '2026-06-01 08:00', 'refused');
        $evening = $this->rawRecord($order, '2026-06-01 20:00', 'given');
        // A gap in the live slots (the 2nd): rebuilt from the recorded version.
        MedicationDoseSlot::query()->where('nz_date', '2026-06-02')->delete();
        $liveSlots = $this->snapshot();

        $this->at('2026-06-03 12:00');
        $this->backfill(['--to' => '2026-06-02', '--months' => 1]);

        $morning = MedicationDoseSlot::query()->where('nz_date', '2026-06-01')->where('ordered_time', '08:00')->sole();
        $this->assertSame(['given', (int) $live->id, false], [$morning->outcome, (int) $morning->outcome_administration_id, $morning->reconstructed]);
        // A live slot without an outcome gets the record for its minute.
        $night = MedicationDoseSlot::query()->where('nz_date', '2026-06-01')->where('ordered_time', '20:00')->sole();
        $this->assertSame(['given', $evening, false], [$night->outcome, (int) $night->outcome_administration_id, $night->reconstructed]);
        // Live slots are otherwise untouched; the 2nd follows the recorded version.
        $this->assertSame([
            '2026-06-01 08:00 given', '2026-06-01 20:00 given', '2026-06-02 08:00 -', '2026-06-02 20:00 -',
            '2026-06-03 08:00 -', '2026-06-03 20:00 -',
        ], $this->slots($order));
        $rebuilt = MedicationDoseSlot::query()->where('nz_date', '2026-06-02')->get();
        $this->assertCount(2, $rebuilt);
        $this->assertTrue($rebuilt->every(fn (MedicationDoseSlot $slot): bool => $slot->reconstructed && $slot->schedule_version_id !== null));
        $this->assertCount(count($liveSlots), MedicationDoseSlot::query()->where('reconstructed', false)->get());
    }

    public function test_a_day_whose_records_the_history_does_not_explain_follows_the_records(): void
    {
        $this->at('2026-06-01 07:00');
        $order = $this->order(['09:00']);
        // Changed with no audit trail: the order now says 08:00.
        DB::table('client_medications')->where('id', $order->id)->update(['dose_times' => json_encode(['08:00'])]);
        $this->record($order, '2026-06-02 09:00', 'given', '2026-06-02 09:00');
        // An extra record on a day the history explains is still owed.
        $this->record($order, '2026-06-03 08:00', 'given', '2026-06-03 08:00');
        $this->record($order, '2026-06-03 14:00', 'refused', '2026-06-03 14:00');
        $prn = $this->order([], ['is_prn' => true, 'frequency' => 'PRN']);
        $this->record($prn, '2026-06-02 10:00', 'given', '2026-06-02 10:00');
        $this->forgetLiveHistory();

        $this->at('2026-06-05 12:00');
        $this->backfill(['--to' => '2026-06-04', '--months' => 1]);

        $this->assertSame([
            '2026-06-01 08:00 -',
            '2026-06-02 09:00 given',
            '2026-06-03 08:00 given',
            '2026-06-03 14:00 refused',
            '2026-06-04 08:00 -',
        ], $this->slots($order));
        $this->assertSame([], $this->slots($prn));
        $run = MedicationDoseSlotBackfill::query()->sole();
        $this->assertSame([1, 1], [$run->days_from_records, $run->records_without_slot]);
    }

    public function test_a_dry_run_counts_and_writes_nothing(): void
    {
        $this->at('2026-06-01 07:00');
        $order = $this->order(['08:00', '20:00']);
        $this->record($order, '2026-06-02 08:00', 'given', '2026-06-02 08:10');
        $this->forgetLiveHistory();

        $this->at('2026-06-04 12:00');
        $this->artisan('emar:backfill-dose-slots', ['--to' => '2026-06-03', '--months' => 1, '--dry-run' => true])
            ->expectsOutputToContain('Dry run — nothing is written. Rebuilding dose slots for 2026-05-04 to 2026-06-03 (NZ).')
            ->expectsTable(['', 'Would write'], [
                ['Orders processed', 1],
                ['Orders skipped (unreadable history)', 0],
                ['Dose slots created', 6],
                ['Outcomes written', 1],
                ['Recorded doses without a slot', 0],
                ['Days rebuilt from records', 0],
            ])
            ->assertSuccessful();

        $this->assertSame(0, MedicationDoseSlot::query()->count());
        $this->assertSame(0, MedicationDoseSlotBackfill::query()->count());
    }

    public function test_reads_before_the_rebuilt_period_say_not_available(): void
    {
        $this->at('2026-06-01 07:00');
        $order = $this->order(['08:00']);
        $this->forgetLiveHistory();

        // Live generation begins on the 20th.
        $this->at('2026-06-20 06:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $projection = app(DoseSlotProjection::class);
        $this->assertSame(
            ['available_from' => '2026-06-20', 'complete' => false, 'notice' => 'Not available before 20 June 2026'],
            $projection->coverage('2026-06-01'),
        );
        $this->assertTrue($projection->coverage('2026-06-20')['complete']);

        // The backfill defaults to the months before live generation began.
        $this->at('2026-06-25 12:00');
        $this->artisan('emar:backfill-dose-slots', ['--months' => 1])
            ->expectsOutputToContain('Rebuilding dose slots for 2026-05-20 to 2026-06-19 (NZ).')
            ->expectsOutputToContain('Dose slots are available from 2026-05-20.')
            ->assertSuccessful();

        $this->assertSame(
            ['available_from' => '2026-05-20', 'complete' => false, 'notice' => 'Not available before 20 May 2026'],
            $projection->coverage('2026-05-01'),
        );
        $this->assertSame(['available_from' => '2026-05-20', 'complete' => true, 'notice' => null], $projection->coverage('2026-05-20'));
        // Rebuilt from the order's entry (1 June) to the day before live began.
        $this->assertSame(19, MedicationDoseSlot::query()->where('client_medication_id', $order->id)->where('reconstructed', true)->count());
    }

    public function test_an_unfinished_run_does_not_extend_what_is_available(): void
    {
        $this->at('2026-06-01 07:00');
        $this->order(['08:00']);
        $this->order(['20:00']);
        $this->forgetLiveHistory();
        $this->at('2026-06-20 06:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();

        $this->at('2026-06-25 12:00');
        $this->backfill(['--months' => 1, '--limit-orders' => 1]);

        $this->assertSame('2026-06-20', app(DoseSlotProjection::class)->coverage('2026-05-20')['available_from']);
    }

    public function test_only_one_run_writes_at_a_time(): void
    {
        $this->at('2026-06-01 07:00');
        $this->order(['08:00']);
        $this->forgetLiveHistory();
        $this->at('2026-06-04 12:00');

        // Another run holds the lock: this one says so and writes nothing.
        $other = Cache::lock(BackfillMedicationDoseSlots::LOCK, 60);
        $this->assertTrue($other->get());
        $this->artisan('emar:backfill-dose-slots', ['--to' => '2026-06-03', '--months' => 1])
            ->expectsOutputToContain('Another dose-slot backfill is running, so this one has not started.')
            ->assertFailed();
        $this->assertSame(0, MedicationDoseSlot::query()->count());
        $this->assertSame(0, MedicationDoseSlotBackfill::query()->count());

        // A dry run writes nothing, so it takes no lock.
        $this->artisan('emar:backfill-dose-slots', ['--to' => '2026-06-03', '--months' => 1, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, MedicationDoseSlot::query()->count());

        $other->release();
        // A run that stops early (here: refused input) releases the lock…
        $this->artisan('emar:backfill-dose-slots', ['--to' => '2026-13-01'])->assertExitCode(Command::INVALID);
        // …and so does one that finishes.
        $this->backfill(['--to' => '2026-06-03', '--months' => 1]);
        $this->assertSame(3, MedicationDoseSlot::query()->count());
        $this->assertTrue(Cache::lock(BackfillMedicationDoseSlots::LOCK, 60)->get());
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
    }

    private function nz(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'Pacific/Auckland')->utc();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function backfill(array $parameters): void
    {
        $this->artisan('emar:backfill-dose-slots', $parameters)->assertSuccessful();
    }

    /**
     * @param  list<string>  $doseTimes
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $doseTimes, array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Metformin',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'start_date' => '2026-05-01',
        ], $overrides))->refresh();
    }

    /** Recorded at the time it was given, as staff record it. */
    private function record(ClientMedication $order, string $due, string $status, string $at, ?string $reasonCode = null): ClientMedicationAdministration
    {
        $now = Carbon::getTestNow();
        $this->at($at);
        $record = ClientMedicationAdministration::query()->create([
            'client_id' => $order->client_id,
            'client_medication_id' => $order->id,
            'administered_by' => $this->recorder->id,
            'scheduled_for' => $this->nz($due),
            'administered_at' => $this->nz($at),
            'status' => $status,
            'reason_code' => $reasonCode,
        ]);
        Carbon::setTestNow($now);

        return $record;
    }

    /** A record written around the model: the live writer never sees it. */
    private function rawRecord(ClientMedication $order, string $due, string $status): int
    {
        return (int) DB::table('client_medication_administrations')->insertGetId([
            'client_id' => $order->client_id,
            'client_medication_id' => $order->id,
            'administered_by' => $this->recorder->id,
            'scheduled_for' => $this->nz($due)->format('Y-m-d H:i:s'),
            'administered_at' => $this->nz($due)->format('Y-m-d H:i:s'),
            'status' => $status,
            'created_at' => now()->utc()->format('Y-m-d H:i:s'),
            'updated_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]);
    }

    /** A correction raised as MedicationAdministrationCorrectionController::store raises it. */
    private function correction(ClientMedicationAdministration $original, string $status): ClientMedicationAdministration
    {
        $correction = $original->replicate(['id', 'client_request_uuid', 'deleted_at', 'created_at', 'updated_at']);
        $correction->forceFill([
            'is_correction' => true,
            'corrected_of_id' => $original->id,
            'status' => $status,
            'correction_requested_by' => $this->recorder->id,
            'correction_status' => 'pending',
            'correction_approved_at' => null,
        ]);
        $correction->save();

        return $correction;
    }

    /** An order from before dose slots existed: only the audit log and the records know its past. */
    private function forgetLiveHistory(): void
    {
        MedicationDoseSlot::query()->delete();
        MedicationDoseOrderPause::query()->delete();
        MedicationDoseScheduleVersion::query()->delete();
    }

    /**
     * @return list<string>
     */
    private function slots(ClientMedication $order): array
    {
        return MedicationDoseSlot::query()
            ->where('client_medication_id', $order->id)
            ->whereNull('superseded_at')
            ->orderBy('nz_date')
            ->orderBy('ordered_time')
            ->get()
            ->map(fn (MedicationDoseSlot $slot): string => $slot->getRawOriginal('nz_date').' '.$slot->ordered_time.' '.($slot->outcome ?? '-'))
            ->all();
    }

    private function outcomeRecord(ClientMedication $order, string $nzDate): int
    {
        return (int) MedicationDoseSlot::query()->where('client_medication_id', $order->id)->where('nz_date', $nzDate)->sole()->outcome_administration_id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshot(): array
    {
        return DB::table('medication_dose_slots')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }
}
