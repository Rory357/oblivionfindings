<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetResidentTransport;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationOrderVersion;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\EnhancedMarService;
use App\Services\Fleet\ResidentTransportJourneyService;
use App\Services\Medication\DoseSlots\ScheduledDoseStates;
use App\Services\MedicationScanVerificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0 (transport double dose): a scheduled medicine given on a journey is
 * recorded against the dose it fills, so the house never sees that dose as
 * still due and a second "Administer" can't give it again.
 */
class TransportScheduledDoseSlotTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Pacific/Auckland';

    private Site $site;

    private User $actor;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        // The order was entered the day before, so every dose today is owed.
        $this->travelTo($this->nz('2026-06-30 08:00'));
        $this->site = Site::factory()->create();
        $this->actor = $this->siteUser($this->site, ['fleet.viewAny', 'medications.administer.record']);
        $this->recordCompetency($this->actor);
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_a_transport_dose_fills_the_owed_slot_and_the_house_sees_it_given(): void
    {
        $medication = $this->scheduledOrder();
        $this->travelTo($this->nz('2026-07-01 09:05'));
        $log = $this->packed($medication);
        $this->travelTo($this->nz('2026-07-01 09:10'));

        $this->administer($log, $medication)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', false);

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame('given', $administration->status);
        $this->assertSame(
            $this->nz('2026-07-01 09:00')->utc()->format('Y-m-d H:i:s'),
            $administration->getRawOriginal('scheduled_for'),
        );
        $this->assertNull($administration->reason);
        $this->assertSame($administration->id, (int) $log->fresh()->medication_administration_id);

        // Meds today and My Day read ScheduledDoseStates; the MAR builds on it.
        $doses = collect(app(ScheduledDoseStates::class)->dosesOn([$medication->fresh()], now(), now())[$medication->id]);
        $nine = $doses->first(fn (array $dose): bool => $dose['ordered_time'] === '09:00');
        $this->assertSame('given', $nine['outcome']);
        $this->assertNull($doses->first(fn (array $dose): bool => $dose['ordered_time'] === '13:00')['outcome']);

        $this->actingAs($this->actor);
        $mar = app(EnhancedMarService::class)->build($this->client->fresh(), now(), now());
        $row = collect($mar['scheduled'])->first(fn (array $row): bool => $row['scheduled_time'] === '09:00');
        $this->assertSame('completed', $row['schedule_state']);
        $this->assertSame($administration->id, $row['administration']['id']);
        $this->assertSame('given', $row['administration']['status']);
    }

    public function test_a_second_transport_administer_for_the_same_dose_is_a_duplicate_and_saves_nothing(): void
    {
        $medication = $this->scheduledOrder();
        $this->travelTo($this->nz('2026-07-01 09:05'));
        $first = $this->packed($medication);
        $second = $this->packed($medication, 'Second vehicle');
        $this->travelTo($this->nz('2026-07-01 09:10'));
        $uuid = (string) Str::uuid();

        $this->administer($first, $medication, $uuid)->assertOk();
        // The same request again is a replay of that one record.
        $this->administer($first, $medication, $uuid)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true);

        $this->travelTo($this->nz('2026-07-01 09:20'));
        $this->administer($second, $medication)
            ->assertUnprocessable()
            ->assertJsonPath('errors.medication.0', ResidentTransportJourneyService::TRANSPORT_ALREADY_RECORDED);

        $this->assertSame(1, ClientMedicationAdministration::query()->count());
        $this->assertNull($second->fresh()->administered_at);
        $this->assertNull($second->fresh()->medication_administration_id);

        // Between doses: 09:00 is given and 13:00 hasn't opened.
        $this->travelTo($this->nz('2026-07-01 11:30'));
        $this->administer($second, $medication)
            ->assertUnprocessable()
            ->assertJsonPath('errors.medication.0', ResidentTransportJourneyService::TRANSPORT_NOT_DUE);
        $this->assertSame(1, ClientMedicationAdministration::query()->count());
    }

    public function test_a_dose_recorded_at_the_house_first_is_not_given_again_on_the_journey(): void
    {
        $medication = $this->scheduledOrder();
        $this->travelTo($this->nz('2026-07-01 09:05'));
        $log = $this->packed($medication);
        $houseRecord = app(EnhancedMarService::class)->recordAdministration(
            $this->client,
            $medication,
            [
                'status' => 'given',
                'scheduled_for' => $this->nz('2026-07-01 09:00')->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
                'dose_given' => '1 tablet',
                'client_request_uuid' => (string) Str::uuid(),
            ],
            $this->actor->id,
            null,
            false,
        );
        $this->assertTrue($houseRecord['success'] ?? false, (string) ($houseRecord['error'] ?? ''));
        $this->travelTo($this->nz('2026-07-01 09:10'));

        $this->administer($log, $medication)
            ->assertUnprocessable()
            ->assertJsonPath('errors.medication.0', ResidentTransportJourneyService::TRANSPORT_ALREADY_RECORDED);

        $this->assertSame(1, ClientMedicationAdministration::query()->count());
        $this->assertNull($log->fresh()->administered_at);
    }

    public function test_an_overdue_dose_is_filled_with_the_transport_late_reason(): void
    {
        $medication = $this->scheduledOrder();
        $this->travelTo($this->nz('2026-07-01 10:40'));
        $log = $this->packed($medication);
        // 09:00's window ended at 10:00; 13:00's opens at 12:30.
        $this->travelTo($this->nz('2026-07-01 11:00'));

        $this->administer($log, $medication)->assertOk();

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame(
            $this->nz('2026-07-01 09:00')->utc()->format('Y-m-d H:i:s'),
            $administration->getRawOriginal('scheduled_for'),
        );
        $this->assertSame(ResidentTransportJourneyService::TRANSPORT_LATE_REASON, $administration->reason);
    }

    public function test_only_an_upcoming_dose_is_refused_and_nothing_is_saved(): void
    {
        $medication = $this->scheduledOrder();
        $this->travelTo($this->nz('2026-07-01 07:50'));
        $log = $this->packed($medication);
        // 09:00's window opens at 08:30.
        $this->travelTo($this->nz('2026-07-01 08:00'));

        $this->administer($log, $medication)
            ->assertUnprocessable()
            ->assertJsonPath('errors.medication.0', ResidentTransportJourneyService::TRANSPORT_NOT_DUE);

        $this->assertSame(0, ClientMedicationAdministration::query()->count());
        $this->assertNull($log->fresh()->administered_at);
        $this->assertSame(0, $log->transport->events()->where('action', 'medication_administered')->count());
    }

    public function test_an_as_needed_medicine_on_transport_is_recorded_as_before(): void
    {
        $medication = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Travel as-needed',
            'dosage' => '1 tablet',
            'frequency' => 'PRN',
            'dose_times' => ['12:00'],
            'is_prn' => true,
            'prn_reason' => 'During travel',
            'controlled_drug' => false,
            'witness_required' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'version' => 1,
        ]);
        $this->travelTo($this->nz('2026-07-01 08:00'));
        $log = $this->packed($medication);
        $this->travelTo($this->nz('2026-07-01 08:05'));

        $this->administer($log, $medication)->assertOk();

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertNull($administration->getRawOriginal('scheduled_for'));
        $this->assertNull($administration->reason);
        $this->assertSame($administration->id, (int) $log->fresh()->medication_administration_id);
    }

    private function administer(FleetMedicationTransitLog $log, ClientMedication $medication, ?string $uuid = null)
    {
        return $this->actingAs($this->actor)
            ->postJson("/fleet-assets/medication-transit/{$log->id}/administer", [
                'scan_code' => app(MedicationScanVerificationService::class)->internalCode($this->client, $medication),
                'scan_source' => 'manual',
                'scan_verified' => true,
                'scan_match_source' => 'internal_emar',
                'quantity_administered' => '1.00',
                'client_request_uuid' => $uuid ?? (string) Str::uuid(),
            ]);
    }

    private function nz(string $local): Carbon
    {
        return Carbon::parse($local, self::TZ)->utc();
    }

    private function scheduledOrder(): ClientMedication
    {
        $medication = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Twice-daily tablet',
            'dosage' => '1 tablet',
            'frequency' => 'Twice daily',
            'dose_times' => ['09:00', '13:00'],
            'is_prn' => false,
            'controlled_drug' => false,
            'witness_required' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'version' => 1,
        ]);
        $this->orderVersion($medication);

        return $medication;
    }

    private function packed(ClientMedication $medication, string $vehicleName = 'Journey vehicle'): FleetMedicationTransitLog
    {
        $vehicle = Asset::factory()->vehicle()->forSite($this->site)->create([
            'name' => $vehicleName,
            'status' => 'active',
        ]);
        $transport = FleetResidentTransport::query()->create([
            'journey_uuid' => (string) Str::uuid(),
            'asset_id' => $vehicle->id,
            'site_id' => $this->site->id,
            'driver_user_id' => $this->actor->id,
            'resident_id' => $this->client->id,
            'resident_name' => trim($this->client->first_name.' '.$this->client->last_name),
            'transport_type' => 'medical',
            'pickup_location' => 'House',
            'dropoff_location' => 'Clinic',
            'departed_at' => now(),
            'passengers_count' => 1,
            'status' => 'in_progress',
            'version' => 1,
        ]);
        $version = $this->orderVersion($medication);

        return FleetMedicationTransitLog::query()->create([
            'transport_id' => $transport->id,
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'shift_id' => null,
            'medication_id' => $medication->id,
            'medication_order_version' => $medication->version,
            'medication_order_version_id' => $version->id,
            'medication_name' => trim($medication->name.' '.$medication->dosage),
            'is_controlled_drug' => false,
            'witness_required' => false,
            'packed_by_user_id' => $this->actor->id,
            'packed_at' => now(),
        ]);
    }

    private function orderVersion(ClientMedication $medication): MedicationOrderVersion
    {
        return MedicationOrderVersion::query()->firstOrCreate([
            'client_medication_id' => $medication->id,
            'version_number' => $medication->version,
        ], [
            'client_id' => $medication->client_id,
            'name' => $medication->name,
            'dosage' => $medication->dosage,
            'frequency' => $medication->frequency,
            'dose_times' => $medication->dose_times,
            'is_prn' => $medication->is_prn,
            'controlled_drug' => $medication->controlled_drug,
            'witness_required' => $medication->witness_required,
            'active' => true,
            'state' => 'active',
            'changed_by' => $this->actor->id,
            'changed_at' => now(),
        ]);
    }

    private function recordCompetency(User $user): void
    {
        $assessor = User::factory()->create(['approved_at' => now()]);

        MedicationCompetencyAssessment::query()->create([
            'user_id' => $user->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subYear(),
            'staff_acknowledged_at' => now()->subYear()->addMinute(),
            'can_witness_controlled' => true,
        ]);
    }

    private function siteUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'manager',
        ]);

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $user->permissionOverrides()->syncWithoutDetaching(
            collect($permissionKeys)
                ->mapWithKeys(fn (string $key): array => [
                    Permission::query()->firstOrCreate(
                        ['key' => $key],
                        ['description' => $key, 'group' => str($key)->before('.')->value(), 'module' => str($key)->before('.')->value()],
                    )->id => ['allowed' => true],
                ])
                ->all(),
        );

        return $user;
    }
}
