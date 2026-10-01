<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDoseScheduleVersion;
use App\Models\MedicationDoseSlot;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * P01 foundation C3 invariants: every scheduled administration writes exactly
 * one dose-slot outcome, in the same transaction, on every recording path; a
 * correction or delete updates it; PRN and unscheduled doses never write one.
 */
class DoseSlotOutcomeWriterTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Client $client;

    private Site $site;

    private ServiceContext $serviceContext;

    private Carbon $slotTime;

    protected function setUp(): void
    {
        parent::setUp();

        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', $timezone)->utc());
        $this->slotTime = Carbon::parse('2026-04-30 09:30:00', $timezone);
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->worker->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->firstOrFail()->id]);
        $this->site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id,
            'assessor_id' => User::factory()->create(['role' => 'manager', 'approved_at' => now()])->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);
        $this->serviceContext = ServiceContext::factory()->create(['name' => 'Dose slots', 'type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $this->client->supportWorkers()->attach($this->worker->id);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_meds_today_writes_exactly_one_slot_outcome(): void
    {
        $order = $this->scheduledOrder();

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $order->id,
                'scheduled_for' => $this->slotTime->toIso8601String(),
                'status' => 'given',
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertSessionHasNoErrors();

        $administration = ClientMedicationAdministration::query()->sole();
        $slot = $this->assertOneOutcome($order, 'given', $administration);
        $this->assertSame(
            Carbon::parse((string) $administration->getRawOriginal('administered_at'), 'UTC')->format('Y-m-d H:i:s'),
            $slot->getRawOriginal('outcome_at'),
        );
    }

    public function test_the_mobile_and_offline_api_writes_exactly_one_slot_outcome(): void
    {
        $order = $this->scheduledOrder();
        $payload = [
            'status' => 'given',
            'dose_given' => '1 tablet',
            'scheduled_for' => $this->slotTime->toIso8601String(),
            'administered_at' => now()->toIso8601String(),
            'client_request_uuid' => '7d0b8a1e-4c1f-4a39-9a0e-2c6f8f1b9a11',
            'captured_offline_at' => now()->toIso8601String(),
            'origin_device_id' => 'slot-device',
            'queued_offline' => true,
        ];
        $url = "/api/medications/clients/{$this->client->id}/medications/{$order->id}/administrations";

        $this->actingAs($this->worker, 'sanctum')->postJson($url, $payload)->assertOk();
        // The offline queue replays the same dose: nothing new is written.
        Cache::forget('emar:idempotency:administration:'.$payload['client_request_uuid']);
        $this->actingAs($this->worker, 'sanctum')->postJson($url, $payload)->assertOk();

        $this->assertOneOutcome($order, 'given', ClientMedicationAdministration::query()->sole());
    }

    public function test_my_day_writes_given_and_refused_outcomes(): void
    {
        $given = $this->scheduledOrder(['name' => 'Morning tablets']);
        $refused = $this->scheduledOrder(['name' => 'Iron']);

        $this->actingAs($this->worker)
            ->post("/my-day/medications/{$given->id}/administer", ['scheduled_for' => $this->slotTime->toIso8601String()])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->worker)
            ->post("/my-day/medications/{$refused->id}/refuse", ['scheduled_for' => $this->slotTime->toIso8601String(), 'reason_code' => 'refused'])
            ->assertSessionHasNoErrors();

        $this->assertOneOutcome($given, 'given', ClientMedicationAdministration::query()->where('client_medication_id', $given->id)->sole());
        $this->assertOneOutcome($refused, 'refused', ClientMedicationAdministration::query()->where('client_medication_id', $refused->id)->sole());
    }

    public function test_the_shared_service_used_by_rounds_transport_and_the_profile_writes_one_outcome_and_reads_away(): void
    {
        $order = $this->scheduledOrder();

        $result = app(EnhancedMarService::class)->recordAdministration(
            $this->client,
            $order,
            [
                'status' => 'withheld',
                'reason_code' => 'hospitalised',
                'reason' => 'In hospital',
                'scheduled_for' => $this->slotTime->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ],
            $this->worker->id,
        );
        $this->assertTrue($result['success'] ?? false, (string) ($result['error'] ?? ''));

        $this->assertOneOutcome($order, 'away', ClientMedicationAdministration::query()->sole());
    }

    public function test_as_needed_doses_never_write_a_slot_outcome(): void
    {
        $prn = $this->scheduledOrder(['name' => 'Paracetamol', 'is_prn' => true, 'frequency' => 'As needed', 'dose_times' => []]);

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn', [
                'client_medication_id' => $prn->id,
                'reason' => 'Headache',
                'dose_given' => '1g',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ClientMedicationAdministration::query()->where('client_medication_id', $prn->id)->count());
        $this->assertSame(0, MedicationDoseSlot::query()->where('client_medication_id', $prn->id)->count());
        $this->assertSame(0, MedicationDoseSlot::query()->whereNotNull('outcome')->count());
    }

    public function test_an_unscheduled_dose_on_a_scheduled_order_maps_to_no_slot(): void
    {
        $order = $this->scheduledOrder();

        $this->record($order, ['scheduled_for' => null]);
        $this->record($order, ['scheduled_for' => Carbon::parse('2026-04-30 11:17:00', 'Pacific/Auckland')->utc()]);

        $this->assertSame(0, MedicationDoseSlot::query()->whereNotNull('outcome')->count());
    }

    public function test_a_record_naming_another_person_never_writes_this_orders_slot(): void
    {
        $order = $this->scheduledOrder();
        $someoneElse = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);

        // A mismatched (forged or corrupt) record: another person, this order.
        $this->record($order, ['client_id' => $someoneElse->id, 'status' => 'refused']);
        $this->assertSame(0, MedicationDoseSlot::query()->whereNotNull('outcome')->count());

        // The person's own record still writes the outcome.
        $own = $this->record($order);
        $this->assertOneOutcome($order, 'given', $own);
    }

    public function test_corrections_update_the_outcome_only_when_approved(): void
    {
        $order = $this->scheduledOrder();
        $original = $this->record($order);
        $this->assertOneOutcome($order, 'given', $original);

        // Raised, not yet approved: the given dose stands.
        $pending = $this->correction($original, 'refused');
        $this->assertOneOutcome($order, 'given', $original);

        // Approved: the correction is now the evidence.
        $pending->update(['correction_status' => 'approved', 'correction_approved_at' => now(), 'correction_approved_by' => User::factory()->create()->id]);
        $this->assertOneOutcome($order, 'refused', $pending);

        // A later approved correction wins; a rejected one changes nothing.
        $this->travel(5)->minutes();
        $later = $this->correction($original, 'withheld', ['reason_code' => 'absent']);
        $rejected = $this->correction($original, 'missed');
        $later->update(['correction_status' => 'approved', 'correction_approved_at' => now(), 'correction_approved_by' => User::factory()->create()->id]);
        $rejected->update(['correction_status' => 'rejected', 'correction_rejection_reason' => 'No']);
        $this->assertOneOutcome($order, 'away', $later);
    }

    public function test_deleting_and_restoring_a_record_updates_the_outcome(): void
    {
        $order = $this->scheduledOrder();
        $administration = $this->record($order);

        $administration->delete();
        $slot = MedicationDoseSlot::query()->where('client_medication_id', $order->id)->where('nz_date', '2026-04-30')->sole();
        $this->assertNull($slot->outcome);
        $this->assertNull($slot->outcome_administration_id);
        $this->assertNull($slot->outcome_at);

        $administration->restore();
        $this->assertOneOutcome($order, 'given', $administration);
    }

    public function test_the_outcome_is_written_in_the_administrations_transaction(): void
    {
        $order = $this->scheduledOrder();

        try {
            DB::transaction(function () use ($order): void {
                $this->record($order);
                $this->assertSame(1, MedicationDoseSlot::query()->whereNotNull('outcome')->count());

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, ClientMedicationAdministration::query()->count());
        $this->assertSame(0, MedicationDoseSlot::query()->whereNotNull('outcome')->count());
    }

    public function test_unreadable_order_history_never_blocks_recording_a_dose(): void
    {
        Exceptions::fake();
        $order = $this->scheduledOrder();
        // A history row that can't be read (verified and rejected at once).
        MedicationDoseScheduleVersion::query()->where('client_medication_id', $order->id)->update([
            'rejected_at' => now()->format('Y-m-d H:i:s'),
        ]);
        MedicationDoseSlot::query()->where('client_medication_id', $order->id)->delete();

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $order->id,
                'scheduled_for' => $this->slotTime->toIso8601String(),
                'status' => 'given',
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        // The dose is recorded; no slot outcome; the problem is reported.
        $this->assertSame(1, ClientMedicationAdministration::query()->count());
        $this->assertSame(0, MedicationDoseSlot::query()->whereNotNull('outcome')->count());
        Exceptions::assertReported(InvalidArgumentException::class);

        // Editing the order still saves too.
        $order->fresh()->update(['dose_times' => ['10:00']]);
        $this->assertSame(['10:00'], $order->fresh()->dose_times);
    }

    public function test_each_scheduled_administration_has_exactly_one_slot_outcome_across_days(): void
    {
        $order = $this->scheduledOrder(['dose_times' => ['08:00', '09:30', '20:00']]);
        $records = collect([
            ['2026-04-28 08:00', 'given'],
            ['2026-04-28 20:00', 'refused'],
            ['2026-04-29 09:30', 'missed'],
            ['2026-04-30 08:00', 'given'],
            ['2026-04-30 09:30', 'withheld'],
        ])->map(fn (array $row) => $this->record($order, [
            'scheduled_for' => Carbon::parse($row[0], 'Pacific/Auckland')->utc(),
            'status' => $row[1],
        ]));

        $withOutcome = MedicationDoseSlot::query()->where('client_medication_id', $order->id)->whereNotNull('outcome')->get();
        $this->assertCount(5, $withOutcome);
        $this->assertEqualsCanonicalizing(
            $records->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $withOutcome->pluck('outcome_administration_id')->map(fn ($id) => (int) $id)->all(),
        );
        // One slot per order, NZ day and ordered time, however often it is synced.
        $this->assertSame(
            MedicationDoseSlot::query()->where('client_medication_id', $order->id)->count(),
            MedicationDoseSlot::query()->where('client_medication_id', $order->id)->distinct()->count(DB::raw("CONCAT(nz_date, ' ', ordered_time)")),
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function scheduledOrder(array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Morning tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => ['09:30'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'start_date' => '2026-04-01',
        ], $overrides));
    }

    /** A record written straight to the model, as the recording service does. */
    private function record(ClientMedication $order, array $overrides = []): ClientMedicationAdministration
    {
        return ClientMedicationAdministration::query()->create(array_merge([
            'client_id' => $this->client->id,
            'client_medication_id' => $order->id,
            'administered_by' => $this->worker->id,
            'scheduled_for' => $this->slotTime->copy()->utc(),
            'administered_at' => now(),
            'status' => 'given',
        ], $overrides));
    }

    /** A correction raised as MedicationAdministrationCorrectionController::store raises it. */
    private function correction(ClientMedicationAdministration $original, string $status, array $overrides = []): ClientMedicationAdministration
    {
        $correction = $original->replicate(['id', 'client_request_uuid', 'deleted_at', 'created_at', 'updated_at']);
        $correction->forceFill(array_merge([
            'is_correction' => true,
            'corrected_of_id' => $original->id,
            'status' => $status,
            'correction_requested_by' => $this->worker->id,
            'correction_status' => 'pending',
            'correction_approved_at' => null,
        ], $overrides));
        $correction->save();

        return $correction;
    }

    private function assertOneOutcome(ClientMedication $order, string $outcome, ClientMedicationAdministration $evidence): MedicationDoseSlot
    {
        $slots = MedicationDoseSlot::query()->where('client_medication_id', $order->id)->whereNotNull('outcome')->get();
        $this->assertCount(1, $slots, 'Exactly one slot carries the outcome.');
        $slot = $slots->sole();
        $this->assertSame($outcome, $slot->outcome);
        $this->assertSame((int) $evidence->id, (int) $slot->outcome_administration_id);

        return $slot;
    }
}
