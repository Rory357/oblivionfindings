<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationDoseOrderPause;
use App\Models\MedicationDoseScheduleVersion;
use App\Models\MedicationDoseSlot;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\DoseSlots\DoseSlotGenerator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P01 foundation C3: dose slots are generated ahead (today + 2 NZ days) and
 * on every order change, idempotently, and the past is never rewritten.
 */
class DoseSlotGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeNz('2026-06-15 09:30');
        $this->client = Client::factory()->create([
            'site_id' => Site::factory()->create(['is_active' => true])->id,
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_new_order_gets_slots_for_today_and_the_next_two_nz_days(): void
    {
        $order = $this->order(['08:00', '20:00']);

        $this->assertSame([
            '2026-06-15 08:00 2026-06-14 20:00:00',
            '2026-06-15 20:00 2026-06-15 08:00:00',
            '2026-06-16 08:00 2026-06-15 20:00:00',
            '2026-06-16 20:00 2026-06-16 08:00:00',
            '2026-06-17 08:00 2026-06-16 20:00:00',
            '2026-06-17 20:00 2026-06-17 08:00:00',
        ], $this->slotLines($order));

        $slot = MedicationDoseSlot::query()->where('client_medication_id', $order->id)->firstOrFail();
        $version = MedicationDoseScheduleVersion::query()->where('client_medication_id', $order->id)->sole();
        $this->assertSame($version->id, (int) $slot->schedule_version_id);
        $this->assertSame($this->client->id, (int) $slot->client_id);
        $this->assertNull($slot->outcome);
        $this->assertNull($slot->superseded_at);
    }

    public function test_generation_is_idempotent(): void
    {
        $order = $this->order(['08:00', '14:00', '20:00']);
        $before = $this->snapshot($order);

        DB::transaction(fn () => app(DoseSlotGenerator::class)->generateAhead($order->fresh()));
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();

        $this->assertSame($before, $this->snapshot($order));
    }

    public function test_the_schedule_extends_the_horizon_as_days_roll_over(): void
    {
        $order = $this->order(['08:00']);

        $this->freezeNz('2026-06-16 00:05');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();

        $this->assertSame(
            ['2026-06-15', '2026-06-16', '2026-06-17', '2026-06-18'],
            array_map(fn (string $line) => substr($line, 0, 10), $this->slotLines($order)),
        );
    }

    public function test_as_needed_orders_get_no_slots(): void
    {
        $order = $this->order(['08:00'], ['is_prn' => true, 'frequency' => 'As needed']);
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();

        $this->assertSame(0, MedicationDoseSlot::query()->where('client_medication_id', $order->id)->count());
    }

    public function test_an_order_awaiting_verification_owes_nothing_until_verified_and_then_only_doses_due_after(): void
    {
        $order = $this->order(['09:00', '20:00'], ['created_by' => User::factory()->create()->id]);
        $this->assertSame('pending_verification', $order->approval_status);
        $this->assertSame([], $this->slotLines($order));

        $this->freezeNz('2026-06-15 10:00');
        $order->update(['approval_status' => 'verified', 'verified_at' => now(), 'verified_by' => User::factory()->create()->id]);

        // Verified at 10:00: this morning's 09:00 dose was never owed.
        $this->assertSame([
            '2026-06-15 20:00 2026-06-15 08:00:00',
            '2026-06-16 09:00 2026-06-15 21:00:00',
            '2026-06-16 20:00 2026-06-16 08:00:00',
            '2026-06-17 09:00 2026-06-16 21:00:00',
            '2026-06-17 20:00 2026-06-17 08:00:00',
        ], $this->slotLines($order));
    }

    public function test_an_unverified_edit_keeps_the_old_doses_flagged_and_verification_moves_only_future_ones(): void
    {
        $order = $this->order(['08:00', '20:00']);
        $past = $this->slot($order, '2026-06-15', '08:00');
        $pastBefore = $past->getRawOriginal();

        $this->freezeNz('2026-06-15 12:00');
        $order->update(['dose_times' => ['09:00', '21:00']]);
        $this->assertSame('pending_verification', $order->fresh()->approval_status);

        // Same times as before, now flagged; the past dose is untouched.
        $this->assertSame([
            '2026-06-15 08:00 2026-06-14 20:00:00',
            '2026-06-15 20:00 2026-06-15 08:00:00 changed',
            '2026-06-16 08:00 2026-06-15 20:00:00 changed',
            '2026-06-16 20:00 2026-06-16 08:00:00 changed',
            '2026-06-17 08:00 2026-06-16 20:00:00 changed',
            '2026-06-17 20:00 2026-06-17 08:00:00 changed',
        ], $this->slotLines($order));
        $this->assertSame($pastBefore, $past->fresh()->getRawOriginal());

        $this->freezeNz('2026-06-15 15:00');
        $order->update(['approval_status' => 'verified', 'verified_at' => now(), 'verified_by' => User::factory()->create()->id]);

        // Doses due from 15:00 follow the new times; the old future ones are superseded.
        $this->assertSame([
            '2026-06-15 08:00 2026-06-14 20:00:00',
            '2026-06-15 21:00 2026-06-15 09:00:00',
            '2026-06-16 09:00 2026-06-15 21:00:00',
            '2026-06-16 21:00 2026-06-16 09:00:00',
            '2026-06-17 09:00 2026-06-16 21:00:00',
            '2026-06-17 21:00 2026-06-17 09:00:00',
        ], $this->slotLines($order));
        $this->assertSame(5, MedicationDoseSlot::query()->where('client_medication_id', $order->id)->whereNotNull('superseded_at')->count());
        $this->assertSame($pastBefore, $past->fresh()->getRawOriginal());
        $this->assertSame(2, MedicationDoseScheduleVersion::query()->where('client_medication_id', $order->id)->count());
    }

    public function test_a_rejected_edit_never_takes_effect_and_stops_flagging(): void
    {
        $order = $this->order(['08:00', '20:00']);

        $this->freezeNz('2026-06-15 12:00');
        $order->update(['dose_times' => ['09:00']]);
        $this->freezeNz('2026-06-15 13:00');
        $order->update(['approval_status' => 'rejected', 'rejection_reason' => 'Wrong time']);

        $this->assertSame([
            '2026-06-15 08:00 2026-06-14 20:00:00',
            '2026-06-15 20:00 2026-06-15 08:00:00',
            '2026-06-16 08:00 2026-06-15 20:00:00',
            '2026-06-16 20:00 2026-06-16 08:00:00',
            '2026-06-17 08:00 2026-06-16 20:00:00',
            '2026-06-17 20:00 2026-06-17 08:00:00',
        ], $this->slotLines($order));
    }

    public function test_pausing_supersedes_future_doses_and_resuming_revives_them(): void
    {
        $order = $this->order(['08:00', '20:00']);

        $this->freezeNz('2026-06-15 12:00');
        $order->update(['state' => 'paused', 'active' => false, 'paused_at' => now()]);
        $this->assertSame(['2026-06-15 08:00 2026-06-14 20:00:00'], $this->slotLines($order));
        $this->assertSame(1, MedicationDoseOrderPause::query()->where('client_medication_id', $order->id)->whereNull('resumed_at')->count());

        $this->freezeNz('2026-06-16 07:00');
        $order->update(['state' => 'active', 'active' => true, 'paused_at' => null]);

        $this->assertSame([
            '2026-06-15 08:00 2026-06-14 20:00:00',
            '2026-06-16 08:00 2026-06-15 20:00:00',
            '2026-06-16 20:00 2026-06-16 08:00:00',
            '2026-06-17 08:00 2026-06-16 20:00:00',
            '2026-06-17 20:00 2026-06-17 08:00:00',
            '2026-06-18 08:00 2026-06-17 20:00:00',
            '2026-06-18 20:00 2026-06-18 08:00:00',
        ], $this->slotLines($order));
        // Last night's 20:00 dose fell in the pause and stays superseded.
        $this->assertNotNull($this->slot($order, '2026-06-15', '20:00')->superseded_at);
    }

    public function test_stopping_an_order_keeps_doses_already_due_and_supersedes_the_rest(): void
    {
        $order = $this->order(['08:00', '14:00', '20:00']);

        $this->freezeNz('2026-06-15 14:00');
        $order->update([
            'state' => 'ceased',
            'active' => false,
            'ceased_at' => now(),
            'ceased_reason' => 'Course finished',
            'ceased_by' => User::factory()->create()->id,
        ]);

        // 14:00 itself was due at the moment of stopping, so it stays owed.
        $this->assertSame([
            '2026-06-15 08:00 2026-06-14 20:00:00',
            '2026-06-15 14:00 2026-06-15 02:00:00',
        ], $this->slotLines($order));
    }

    public function test_a_change_back_revives_a_superseded_future_slot(): void
    {
        $order = $this->order(['08:00']);
        $verifier = User::factory()->create();

        $this->freezeNz('2026-06-15 12:00');
        $order->update(['dose_times' => ['09:00']]);
        $order->update(['approval_status' => 'verified', 'verified_at' => now(), 'verified_by' => $verifier->id]);
        $tomorrow = $this->slot($order, '2026-06-16', '08:00');
        $this->assertNotNull($tomorrow->superseded_at);

        $this->freezeNz('2026-06-15 13:00');
        $order->update(['dose_times' => ['08:00']]);
        $order->update(['approval_status' => 'verified', 'verified_at' => now(), 'verified_by' => $verifier->id]);

        $this->assertNull($tomorrow->fresh()->superseded_at);
        $this->assertSame($tomorrow->id, $this->slot($order, '2026-06-16', '08:00')->id);
    }

    public function test_a_replacement_entered_mid_day_owes_nothing_due_before_it_was_entered(): void
    {
        $original = $this->order(['09:00', '12:00']);

        // Replaced at 10:00 by an order with the same times.
        $this->freezeNz('2026-06-15 10:00');
        $replacement = $this->enter(['09:00', '12:00']);
        $original->forceFill(['superseded_by' => $replacement->id, 'superseded_at' => now()])->save();

        // The 09:00 dose is owed once — by the original, due before it was replaced.
        $this->assertSame(['2026-06-15 09:00 2026-06-14 21:00:00'], $this->slotLines($original));
        $this->assertSame([
            '2026-06-15 12:00 2026-06-15 00:00:00',
            '2026-06-16 09:00 2026-06-15 21:00:00',
            '2026-06-16 12:00 2026-06-16 00:00:00',
            '2026-06-17 09:00 2026-06-16 21:00:00',
            '2026-06-17 12:00 2026-06-17 00:00:00',
        ], $this->slotLines($replacement));

        // The hourly run and a later record keep it that way.
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        DB::transaction(fn () => app(DoseSlotGenerator::class)->ensureDay($replacement->fresh(), '2026-06-15', CarbonImmutable::now()));
        $this->assertSame('2026-06-15 12:00 2026-06-15 00:00:00', $this->slotLines($replacement)[0]);
    }

    public function test_an_order_from_before_c3_records_its_baseline_on_its_first_change(): void
    {
        $order = $this->order(['08:00', '20:00']);
        MedicationDoseScheduleVersion::query()->where('client_medication_id', $order->id)->delete();

        $this->freezeNz('2026-06-15 12:00');
        $order->fresh()->update(['dose_times' => ['09:00']]);

        $versions = MedicationDoseScheduleVersion::query()->where('client_medication_id', $order->id)->orderBy('id')->get();
        $this->assertSame([['08:00', '20:00'], ['09:00']], $versions->pluck('dose_times')->all());
        $this->assertNotNull($versions[0]->verified_at);
        $this->assertNull($versions[1]->verified_at);
        // The old times are still owed, flagged.
        $this->assertSame('2026-06-16 08:00 2026-06-15 20:00:00 changed', $this->slotLines($order)[2]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function freezeNz(string $local): void
    {
        Carbon::setTestNow(Carbon::parse($local, 'Pacific/Auckland')->utc());
    }

    /**
     * An order entered at the start of today (NZ): a dose due before an
     * order was entered is never owed, so the tests' morning doses need an
     * order entered before them.
     *
     * @param  list<string>  $doseTimes
     */
    private function order(array $doseTimes, array $overrides = [], string $enteredNz = '2026-06-15 00:00'): ClientMedication
    {
        $now = Carbon::getTestNow();
        $this->freezeNz($enteredNz);
        $order = $this->enter($doseTimes, $overrides);
        Carbon::setTestNow($now);

        return $order;
    }

    /**
     * @param  list<string>  $doseTimes
     */
    private function enter(array $doseTimes, array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Metformin',
            'dosage' => '500mg',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'start_date' => '2026-06-01',
        ], $overrides));
    }

    private function slot(ClientMedication $order, string $nzDate, string $time): MedicationDoseSlot
    {
        return MedicationDoseSlot::query()
            ->where('client_medication_id', $order->id)
            ->where('nz_date', $nzDate)
            ->where('ordered_time', $time)
            ->firstOrFail();
    }

    /**
     * Live (not superseded) slots as "NZ day, time, UTC due[, changed]".
     *
     * @return list<string>
     */
    private function slotLines(ClientMedication $order): array
    {
        return MedicationDoseSlot::query()
            ->where('client_medication_id', $order->id)
            ->whereNull('superseded_at')
            ->orderBy('due_at')
            ->get()
            ->map(fn (MedicationDoseSlot $slot): string => trim(sprintf(
                '%s %s %s %s',
                substr((string) $slot->getRawOriginal('nz_date'), 0, 10),
                $slot->ordered_time,
                $slot->getRawOriginal('due_at'),
                $slot->order_change_pending ? 'changed' : '',
            )))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshot(ClientMedication $order): array
    {
        return DB::table('medication_dose_slots')
            ->where('client_medication_id', $order->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }
}
