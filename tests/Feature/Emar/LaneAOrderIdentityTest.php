<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationOrderRevision;
use App\Models\MedicationOrderVersion;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Downtime\PaperEntryService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Support\RecordsDosesLikeTheDialog;

/**
 * eMAR audit Lane A2 (EA-008, EA-059, EA-060): a save records the order the
 * dialog showed, the dialog says when an order changed, and a pack-tracked
 * dose's connected review can be refreshed inside an open dialog without
 * losing the dose time the worker actually gave it.
 */
class LaneAOrderIdentityTest extends TestCase
{
    use RefreshDatabase;
    use RecordsDosesLikeTheDialog;

    private Site $site;

    private Client $client;

    private User $worker;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-04-30 09:30');
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active',
            'first_name' => 'Tama', 'preferred_name' => 'Tama',
        ]);
        $this->worker = $this->staff();
        $this->lead = User::factory()->create(['name' => 'Jordan Tipene', 'approved_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** EA-008: the order is checked at a new dose while the dialog is open. */
    public function test_a_given_dose_for_an_order_that_changed_in_the_open_dialog_is_refused(): void
    {
        $order = $this->order(['name' => 'Synthetic quetiapine', 'dosage' => '50 mg', 'dose_amount' => 50, 'dose_unit' => 'mg']);
        $shown = $this->scheduledRequirements($order)->json('order_fingerprint');
        $this->assertIsString($shown);

        $order->fresh()->publishCheckedPrescription(['dosage' => '25 mg', 'dose_amount' => 25], 2, $this->lead->id);

        $this->record($order, ['order_fingerprint' => $shown])
            ->assertUnprocessable()
            ->assertJsonPath('error_field', 'order_changed')
            ->assertJsonPath('order_changed', true)
            ->assertJsonPath('error', 'Order changed — check the new instructions')
            ->assertJsonPath('sync.status', 'rejected');
        $this->assertDatabaseCount('client_medication_administrations', 0);

        // The reloaded dialog shows the new order and saves it as given.
        $current = $this->scheduledRequirements($order)->assertJsonPath('order.dosage', '25 mg')->json('order_fingerprint');
        $this->assertNotSame($shown, $current);
        $this->record($order, ['order_fingerprint' => $current])->assertOk()->assertJsonPath('sync.status', 'processed');
        $this->assertSame('25 mg', ClientMedicationAdministration::sole()->dose_given);
    }

    /** EA-008: refusals still document what happened; only "given" binds the dose shown. */
    public function test_a_refusal_still_saves_after_the_order_changed(): void
    {
        $order = $this->order(['name' => 'Synthetic quetiapine', 'dosage' => '50 mg', 'dose_amount' => 50, 'dose_unit' => 'mg']);
        $shown = $this->scheduledRequirements($order)->json('order_fingerprint');
        $order->fresh()->publishCheckedPrescription(['dosage' => '25 mg', 'dose_amount' => 25], 2, $this->lead->id);

        $this->record($order, ['order_fingerprint' => $shown, 'status' => 'refused', 'reason_code' => 'refused', 'amount_mode' => null,
            'follow_up_due_at' => '2026-04-30T10:30:00'])->assertOk();
        $this->assertSame('refused', ClientMedicationAdministration::sole()->status);
    }

    /**
     * Review item 3: a "given" on Meds today always says which order it was
     * given from. One without it (an old screen, a dose queued before this
     * rule) is told why and parked on the device; refusals and withheld
     * doses don't need it.
     */
    public function test_a_given_dose_without_the_order_it_showed_is_refused_with_a_clear_reason(): void
    {
        $order = $this->order(['name' => 'Synthetic quetiapine', 'dosage' => '50 mg', 'dose_amount' => 50, 'dose_unit' => 'mg']);
        $given = [
            'client_medication_id' => $order->id, 'client_request_uuid' => (string) Str::uuid(),
            'scheduled_for' => '2026-04-30T09:30:00+12:00', 'status' => 'given', 'administered_at' => '2026-04-30T09:30:00',
            'amount_mode' => 'as_ordered', 'order_fingerprint' => null,
        ];

        $this->actingAs($this->worker->fresh())->postJson('/meds/today/record', $given)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_fingerprint' => 'doesn’t say which order it was given from']);

        // A dose queued on the device without it is refused the same way (the device parks it).
        $this->postJson('/meds/today/record', [...$given, 'client_request_uuid' => (string) Str::uuid(), 'queued_offline' => true,
            'captured_offline_at' => CarbonImmutable::now('Pacific/Auckland')->toIso8601String(),
            'origin_device_id' => 'synthetic-house-laptop', 'captured_by_user_id' => $this->worker->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_fingerprint');
        $this->assertDatabaseCount('client_medication_administrations', 0);

        // A refusal documents what happened without it.
        $refused = [...$given, 'client_request_uuid' => (string) Str::uuid(), 'status' => 'refused', 'reason_code' => 'refused',
            'follow_up_due_at' => '2026-04-30T10:30:00'];
        unset($refused['amount_mode']);
        $this->postJson('/meds/today/record', $refused)->assertOk();
        $this->assertSame('refused', ClientMedicationAdministration::sole()->status);
    }

    /** Review item 3: an as-needed dose is always "given", so it always says which order. */
    public function test_an_as_needed_dose_without_the_order_it_showed_is_refused(): void
    {
        $prn = $this->order(['name' => 'Synthetic paracetamol', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet',
            'frequency' => 'As needed', 'dose_times' => [], 'is_prn' => true, 'prn_reason' => 'Pain', 'min_hours_between_doses' => 4]);
        $body = ['client_medication_id' => $prn->id, 'client_request_uuid' => (string) Str::uuid(), 'reason' => 'Pain',
            'administered_at' => '2026-04-30T09:20:00', 'amount_mode' => 'as_ordered'];

        $this->actingAs($this->worker->fresh())->postJson('/meds/today/prn', [...$body, 'order_fingerprint' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_fingerprint');
        $this->assertDatabaseCount('client_medication_administrations', 0);

        // The dialog sends the order it showed, and it saves.
        $this->postJson('/meds/today/prn', [...$body, 'order_fingerprint' => PaperEntryService::orderFingerprint($prn->fresh())])->assertOk();
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    /**
     * EA-008: retrying the same saved request after a later change never
     * records the new dose. (The server derives the dose words from the
     * current order, so the retry is a replay conflict, as on main; the
     * original record stands. A device then reports it and is told it's
     * already on the chart.)
     */
    public function test_an_exact_retry_after_the_order_changed_never_records_the_new_dose(): void
    {
        $order = $this->order(['name' => 'Synthetic quetiapine', 'dosage' => '50 mg', 'dose_amount' => 50, 'dose_unit' => 'mg']);
        $shown = $this->scheduledRequirements($order)->json('order_fingerprint');
        $body = ['order_fingerprint' => $shown, 'client_request_uuid' => (string) Str::uuid()];
        $this->record($order, $body)->assertOk()->assertJsonPath('sync.status', 'processed');

        $order->fresh()->publishCheckedPrescription(['dosage' => '25 mg', 'dose_amount' => 25], 2, $this->lead->id);

        $this->record($order, $body)->assertStatus(409);
        $this->assertSame('50 mg', ClientMedicationAdministration::sole()->dose_given);
    }

    /** EA-059: a dose whose order was checked after its last recorded dose says what changed. */
    public function test_requirements_say_what_changed_since_the_last_recorded_dose(): void
    {
        $order = $this->order(['name' => 'Synthetic levetiracetam', 'dosage' => '500 mg', 'dose_amount' => 500, 'dose_unit' => 'mg',
            'prescriber' => 'Dr Lena Chen', 'version' => 1]);
        // Yesterday evening's dose was given on version 1.
        ClientMedicationAdministration::create(['client_id' => $this->client->id, 'client_medication_id' => $order->id,
            'service_context_id' => $this->client->service_context_id, 'administered_by' => $this->worker->id,
            'administered_at' => CarbonImmutable::parse('2026-04-29 20:00', 'Pacific/Auckland')->utc(),
            'scheduled_for' => CarbonImmutable::parse('2026-04-29 20:00', 'Pacific/Auckland')->utc(),
            'status' => 'given', 'dose_given' => '500 mg']);
        $this->changeOrder($order, '2026-04-30 09:05', ['dosage' => '750 mg', 'dose_amount' => 750]);

        $change = $this->scheduledRequirements($order)->assertOk()->json('order_change');
        $this->assertIsArray($change);
        $this->assertSame('500 mg', $change['before']['dosage']);
        $this->assertSame('750 mg', $change['now']['dosage']);
        $this->assertSame('Jordan Tipene', $change['verified_by']);
        $this->assertSame('Dr Lena Chen', $change['prescriber']);
        $this->assertSame(CarbonImmutable::parse('2026-04-30 09:05', 'Pacific/Auckland')->toIso8601String(), CarbonImmutable::parse($change['verified_at'])->setTimezone('Pacific/Auckland')->toIso8601String());

        // Once a dose is recorded on the new version, the change is no longer news.
        $this->record($order, [])->assertOk();
        $this->assertNull($this->scheduledRequirements($order)->json('order_change'));
    }

    /** EA-059: an order with no checked change since its last dose carries no change notice. */
    public function test_requirements_carry_no_change_for_an_unchanged_order(): void
    {
        $order = $this->order(['name' => 'Synthetic losartan', 'dosage' => '50 mg', 'dose_amount' => 50, 'dose_unit' => 'mg']);
        $this->scheduledRequirements($order)->assertOk()->assertJsonPath('order_change', null);
    }

    /** EA-060: the review expires inside the open dialog; refreshing it keeps the dose time. */
    public function test_an_expired_pack_review_is_refreshed_without_losing_the_actual_dose_time(): void
    {
        $order = $this->order(['name' => 'Synthetic exact pack losartan', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $this->startPacks($order);
        $review = $this->scheduledRequirements($order)->json('live_recording_context');
        $this->assertIsString($review);

        // Given at 09:31; the witness/paperwork takes until 10:05.
        $this->clock('2026-04-30 10:05');
        $this->record($order, ['live_recording_context' => $review, 'administered_at' => '2026-04-30T09:31:00'])
            ->assertUnprocessable()->assertJsonPath('error_field', 'live_recording_context');
        $this->assertDatabaseCount('client_medication_administrations', 0);

        $renewed = $this->scheduledRequirements($order, $review)->json('live_recording_context');
        $this->assertIsString($renewed);
        $this->assertNotSame($review, $renewed);
        $this->record($order, ['live_recording_context' => $renewed, 'administered_at' => '2026-04-30T09:31:00'])
            ->assertOk()->assertJsonPath('sync.status', 'processed');
        $this->assertSame('2026-04-29 21:31:00', ClientMedicationAdministration::sole()->getRawOriginal('administered_at'));
    }

    /** EA-060: a refresh never borrows another worker's review or a forged one. */
    public function test_a_refresh_only_keeps_the_dose_time_of_this_workers_own_review(): void
    {
        $order = $this->order(['name' => 'Synthetic exact pack losartan', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $this->startPacks($order);
        $colleague = $this->staff();
        $theirs = $this->scheduledRequirements($order, null, $colleague)->json('live_recording_context');
        [$payload, $signature] = explode('.', $this->scheduledRequirements($order)->json('live_recording_context'));

        $this->clock('2026-04-30 10:05');
        foreach ([$theirs, $payload.'.'.strrev($signature)] as $borrowed) {
            $fresh = $this->scheduledRequirements($order, $borrowed)->json('live_recording_context');
            $this->record($order, ['live_recording_context' => $fresh, 'administered_at' => '2026-04-30T09:31:00'])
                ->assertUnprocessable()->assertJsonPath('error_field', 'administered_at');
        }
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    /** EA-060: a review left open for hours is not stretched to cover a dose time long past. */
    public function test_a_refresh_does_not_stretch_a_review_left_open_for_hours(): void
    {
        $order = $this->order(['name' => 'Synthetic exact pack losartan', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $this->startPacks($order);
        $review = $this->scheduledRequirements($order)->json('live_recording_context');

        $this->clock('2026-04-30 12:00');
        $renewed = $this->scheduledRequirements($order, $review)->json('live_recording_context');
        $this->record($order, ['live_recording_context' => $renewed, 'administered_at' => '2026-04-30T09:31:00', 'late_reason' => 'other'])
            ->assertUnprocessable()->assertJsonPath('error_field', 'administered_at');
    }

    private function clock(string $nz): void
    {
        $at = CarbonImmutable::parse($nz, 'Pacific/Auckland')->utc();
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    private function order(array $attributes): ClientMedication
    {
        $now = Carbon::getTestNow();
        $this->clock('2026-04-30 00:00');
        $order = ClientMedication::create([
            'client_id' => $this->client->id, 'frequency' => 'Daily', 'dose_times' => ['09:30'], 'is_prn' => false,
            'controlled_drug' => false, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
            'verified_by' => $this->lead->id, 'verified_at' => now(), 'version' => 1,
            ...$attributes,
        ]);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        return $order;
    }

    /** A checked P04 change: the version snapshots, its revision and the published order. */
    private function changeOrder(ClientMedication $order, string $checkedAtNz, array $changes): void
    {
        $now = Carbon::getTestNow();
        $this->clock($checkedAtNz);
        $order = $order->fresh();
        $fields = fn (ClientMedication $o): array => ['name' => $o->name, 'dosage' => $o->dosage, 'dose_amount' => $o->dose_amount,
            'dose_unit' => $o->dose_unit, 'frequency' => $o->frequency, 'dose_times' => $o->dose_times, 'prescriber' => $o->prescriber];
        $before = MedicationOrderVersion::create([...$fields($order), 'client_id' => $order->client_id, 'client_medication_id' => $order->id,
            'version_number' => 1, 'prescription_payload' => $fields($order), 'changed_by' => $this->lead->id, 'changed_at' => now()->subDay()]);
        MedicationOrderRevision::create(['client_id' => $order->client_id, 'client_medication_id' => $order->id,
            'medication_order_version_id' => $before->id, 'base_version' => 1, 'status' => 'checked',
            'checked_by' => $this->lead->id, 'checked_at' => now()->subDay()]);
        $payload = [...$fields($order), ...$changes];
        $after = MedicationOrderVersion::create([...$payload, 'client_id' => $order->client_id, 'client_medication_id' => $order->id,
            'version_number' => 2, 'prescription_payload' => $payload, 'changed_by' => $this->lead->id, 'changed_at' => now()]);
        MedicationOrderRevision::create(['client_id' => $order->client_id, 'client_medication_id' => $order->id,
            'medication_order_version_id' => $after->id, 'base_version' => 1, 'status' => 'checked', 'entered_by' => $this->lead->id,
            'checked_by' => $this->lead->id, 'checked_at' => now()]);
        $order->publishCheckedPrescription($changes, 2, $this->lead->id);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    private function startPacks(ClientMedication $order): void
    {
        $stock = ClientMedicationStock::create(['client_medication_id' => $order->id, 'on_hand' => '20.00', 'unit' => 'tablet']);
        $this->actingAs($this->worker->fresh())->postJson('/emar/stock/packs/commands', [
            'action' => 'initialise', 'client_medication_id' => $order->id, 'request_uuid' => (string) Str::uuid(), 'confirm_balance' => true,
        ])->assertOk();
        $this->assertNotNull($stock->fresh()->lots_started_at);
    }

    private function scheduledRequirements(ClientMedication $order, ?string $renew = null, ?User $as = null)
    {
        return $this->actingAs(($as ?? $this->worker)->fresh())
            ->withHeaders($renew !== null ? ['X-Live-Recording-Context' => $renew] : [])
            ->getJson('/meds/today/doses/requirements?'.http_build_query([
                'client_medication_id' => $order->id,
                'scheduled_for' => CarbonImmutable::parse('2026-04-30 09:30', 'Pacific/Auckland')->toIso8601String(),
            ]))
            ->assertOk();
    }

    private function record(ClientMedication $order, array $fields)
    {
        return $this->actingAs($this->worker->fresh())->postJson('/meds/today/record', array_filter([
            'client_medication_id' => $order->id,
            'client_request_uuid' => (string) Str::uuid(),
            'scheduled_for' => '2026-04-30T09:30:00+12:00',
            'status' => 'given',
            'administered_at' => '2026-04-30T09:30:00',
            'amount_mode' => 'as_ordered',
            ...$fields,
        ], fn ($value) => $value !== null));
    }

    private function staff(): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::where('name', 'support_worker')->sole()->id]);
        $keys = ['medications.view', 'medications.stock.update', 'medications.administer.record'];
        $permissions = Permission::whereIn('key', $keys)->get();
        $this->assertCount(count($keys), $permissions);
        $user->permissionOverrides()->syncWithoutDetaching($permissions->mapWithKeys(fn ($p) => [$p->id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subYear(), 'end_date' => null, 'is_active' => true]);
        MedicationCompetencyAssessment::create(['user_id' => $user->id, 'assessor_id' => User::factory()->create()->id, 'assessment_type' => 'annual', 'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true, 'can_witness_controlled' => true, 'controlled_drugs' => true, 'restricted' => false, 'not_seen_areas' => []]);
        Shift::factory()->create(['user_id' => $user->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id, 'starts_at' => now()->subHour()->utc(), 'ends_at' => now()->addHours(8)->utc(),
            'actual_starts_at' => now()->subMinutes(30)->utc(), 'actual_ends_at' => null, 'status' => 'in_progress']);
        Cache::flush();

        return $user->fresh();
    }
}
