<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleComplianceVersion;
use App\Models\FleetVehicleOdometerObservation;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\Data\VehicleReadinessAssessment;
use App\Services\Fleet\Data\VehicleReadinessContext;
use App\Services\Fleet\VehicleComplianceService;
use App\Services\Fleet\VehicleOdometerService;
use App\Services\Fleet\VehicleReadinessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * PKG-02B I1: versioned compliance evidence, observed odometer readings and
 * the single readiness assessment behind every vehicle use decision.
 */
class Pkg02bVehicleReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreignSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Notification::fake();
        // NZST (+12:00): Pacific/Auckland is still on standard time on 22 Sept.
        // The frozen clock is held in UTC like the application; a zoned test
        // clock would make Carbon read stored UTC datetimes in that zone.
        $this->travelTo(Carbon::parse('2026-09-22 09:30:00', 'Pacific/Auckland')->utc());
        $this->site = Site::factory()->create(['name' => 'Kōwhai House']);
        $this->foreignSite = Site::factory()->create(['name' => 'Rimu House']);
    }

    public function test_compliance_evidence_needs_manage_permission_and_conceals_foreign_or_missing_vehicles(): void
    {
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $foreign = $this->vehicle($this->foreignSite);
        $payload = $this->wofPassed() + ['request_key' => 'wof-1'];

        $this->actingAs($viewer)->postJson("/fleet-assets/vehicles/{$vehicle->id}/compliance/wof", $payload)->assertForbidden();
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$foreign->id}/compliance/wof", $payload)->assertNotFound();
        $this->actingAs($manager)->postJson('/fleet-assets/vehicles/987654321/compliance/wof', $payload)->assertNotFound();
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/compliance/insurance", $payload)->assertNotFound();

        $this->assertSame(0, FleetVehicleComplianceVersion::query()->count());
    }

    public function test_compliance_versions_are_retained_and_stale_or_conflicting_retries_are_refused(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/compliance/wof";

        $first = $this->actingAs($manager)->postJson($url, $this->wofPassed() + ['request_key' => 'wof-a'])
            ->assertOk()->json('version');
        $this->assertSame(1, $first['version']);

        // An identical retry returns the same version; a changed payload under the same key conflicts.
        $this->actingAs($manager)->postJson($url, $this->wofPassed() + ['request_key' => 'wof-a'])
            ->assertOk()->assertJsonPath('version.id', $first['id']);
        $this->actingAs($manager)->postJson($url, ['evidence_reference' => 'WOF-OTHER'] + $this->wofPassed() + ['request_key' => 'wof-a'])
            ->assertConflict();

        // A new command must name the version it replaces.
        $this->actingAs($manager)->postJson($url, $this->wofNeedsAssessment() + ['request_key' => 'wof-b'])
            ->assertConflict();
        $second = $this->actingAs($manager)->postJson($url, $this->wofNeedsAssessment() + [
            'request_key' => 'wof-b', 'expected_current_version_id' => $first['id'],
        ])->assertOk()->json('version');

        $this->assertSame(2, $second['version']);
        $retained = FleetVehicleComplianceVersion::query()->findOrFail($first['id']);
        $this->assertSame('passed', $retained->outcome);
        $this->assertSame($first['id'], (int) FleetVehicleComplianceVersion::query()->findOrFail($second['id'])->supersedes_version_id);
        $this->expectException(\LogicException::class);
        $retained->update(['outcome' => 'failed']);
    }

    public function test_compliance_assessments_validate_their_evidence_without_inventing_any(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $post = fn (string $kind, array $data, string $key) => $this->actingAs($manager)
            ->postJson("/fleet-assets/vehicles/{$vehicle->id}/compliance/{$kind}", $data + ['request_key' => $key]);

        $post('wof', ['applicability' => 'unknown', 'outcome' => 'passed'], 'v1')->assertUnprocessable()->assertJsonValidationErrors('outcome');
        $post('cof', ['applicability' => 'not_applicable', 'outcome' => 'needs_assessment'], 'v2')->assertUnprocessable()->assertJsonValidationErrors('applicability_basis');
        $post('wof', ['applicability' => 'applicable', 'outcome' => 'passed', 'expires_on' => '2027-04-09'], 'v3')->assertUnprocessable()->assertJsonValidationErrors('evidence_reference');
        $post('registration', ['applicability' => 'applicable', 'outcome' => 'recorded', 'evidence_reference' => 'REG-1'], 'v4')->assertUnprocessable()->assertJsonValidationErrors('expires_on');
        $post('ruc', ['applicability' => 'applicable', 'outcome' => 'recorded', 'evidence_reference' => 'RUC-1', 'ruc_start_km' => 90000, 'ruc_end_km' => 90000], 'v5')->assertUnprocessable()->assertJsonValidationErrors('ruc_end_km');
        $post('wof', ['applicability' => 'applicable', 'outcome' => 'passed', 'evidence_reference' => 'WOF-1', 'effective_on' => '2026-10-01', 'expires_on' => '2026-09-30'], 'v6')->assertUnprocessable()->assertJsonValidationErrors('expires_on');

        // An unresolved observation can be saved without a fabricated reference or date.
        $post('wof', ['applicability' => 'applicable', 'outcome' => 'needs_assessment'], 'v7')->assertOk();
        $post('cof', ['applicability' => 'not_applicable', 'applicability_basis' => 'Light van; a WoF applies instead.', 'outcome' => 'needs_assessment'], 'v8')->assertOk();
    }

    public function test_readiness_names_every_unassessed_requirement_and_becomes_ready_with_valid_evidence(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);

        $blocked = $this->assess($vehicle);
        $this->assertFalse($blocked->canProceed);
        $this->assertSame([
            'Registration: assess applicability.',
            'WoF: assess applicability.',
            'CoF: assess applicability.',
            'RUC: assess applicability.',
        ], array_map(fn ($reason) => $reason->message, $blocked->reasons));

        $this->recordReadyEvidence($vehicle, $manager);

        $ready = $this->assess($vehicle);
        $this->assertTrue($ready->canProceed, json_encode($ready->toArray()));
        $this->assertSame('ready', $ready->status);
        $this->assertSame(50000.0, $ready->odometerKm);
    }

    public function test_b01_an_unresolved_applicable_assessment_blocks_confirmation_approval_and_checkout(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'fleet.bookings.approve'], driverEligible: true);
        $approver = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.bookings.approve']);
        $vehicle = $this->vehicle($this->site);
        $this->recordReadyEvidence($vehicle, $manager);
        $pending = $this->booking($vehicle, $manager, 'Requested before the change', 'pending');
        $approved = $this->booking($vehicle, $manager, 'Approved before the change', 'approved', 5);

        // Keep the old reference and a future date but record the outcome as unresolved.
        $current = $this->currentVersionId($vehicle, 'wof');
        app(VehicleComplianceService::class)->record($manager, $vehicle->id, 'wof', [
            'applicability' => 'applicable', 'outcome' => 'needs_assessment',
            'evidence_reference' => 'WOF-DEMO-14', 'expires_on' => '2027-04-09',
        ], 'wof-unresolved', $current);

        $projection = $this->assess($vehicle);
        $this->assertFalse($projection->canProceed);
        $this->assertSame('WoF: assessment is unresolved.', $projection->reasons[0]->message);

        // Approval not required never confirms while readiness is blocked; the request stays pending.
        $this->actingAs($manager)->post('/fleet-assets/bookings', $this->storePayload($vehicle, 10) + [
            'approval_route' => 'not_required',
            'approval_not_required_reason' => 'Coordinator-run training trip.',
            'readiness_acknowledged' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $created = FleetVehicleBooking::query()->where('purpose', 'Training trip')->sole();
        $this->assertSame('pending', $created->status);
        $this->assertSame('not_required', $created->approval_route);

        $this->actingAs($approver)->post("/fleet-assets/bookings/{$pending->id}/approve")
            ->assertRedirect()->assertSessionHasErrors(['asset_id' => 'WoF: assessment is unresolved.']);
        $this->actingAs($manager)->post("/fleet-assets/bookings/{$approved->id}/checkout", ['odometer_out' => 50010])
            ->assertRedirect()->assertSessionHasErrors(['asset_id' => 'WoF: assessment is unresolved.']);

        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('approved', $approved->fresh()->status);
    }

    public function test_b02_recorded_ruc_coverage_applies_to_observed_and_checkout_readings_alike(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'fleet.bookings.approve'], driverEligible: true);
        $vehicle = $this->vehicle($this->site);
        $this->recordReadyEvidence($vehicle, $manager);
        $ruc = $this->currentVersionId($vehicle, 'ruc');
        app(VehicleComplianceService::class)->record($manager, $vehicle->id, 'ruc', [
            'applicability' => 'applicable', 'outcome' => 'recorded', 'evidence_reference' => 'RUC-LIC-1',
            'ruc_start_km' => 40000, 'ruc_end_km' => 50000,
        ], 'ruc-range', $ruc);

        // Equality keeps the existing strict upper-bound comparison.
        $this->assertTrue($this->assess($vehicle)->canProceed);

        $approved = $this->booking($vehicle, $manager, 'Checkout over the licence end', 'approved');
        $this->actingAs($manager)->post("/fleet-assets/bookings/{$approved->id}/checkout", ['odometer_out' => 50001])
            ->assertRedirect()->assertSessionHasErrors(['asset_id' => 'RUC: checkout reading 50,001 km exceeds the recorded licence end 50,000 km. Review coverage before vehicle use.']);
        $this->assertSame('approved', $approved->fresh()->status);

        $this->recordReading($vehicle, $manager, 50460, 'over-end');
        $blocked = $this->assess($vehicle);
        $this->assertSame('compliance.ruc.coverage_exhausted', $blocked->reasons[0]->code);
        $this->assertSame('RUC: recorded odometer 50,460 km exceeds the recorded licence end 50,000 km. Review coverage before vehicle use.', $blocked->reasons[0]->message);
        $pending = $this->booking($vehicle, $manager, 'Approval over the licence end', 'pending', 3);
        $this->actingAs($this->siteUser([$this->site], ['fleet.viewAny', 'fleet.bookings.approve']))
            ->post("/fleet-assets/bookings/{$pending->id}/approve")->assertSessionHasErrors('asset_id');

        // A sourced Not applicable decision no longer depends on the odometer.
        app(VehicleComplianceService::class)->record($manager, $vehicle->id, 'ruc', [
            'applicability' => 'not_applicable', 'applicability_basis' => 'Recorded as outside RUC by the fleet owner.',
            'outcome' => 'needs_assessment',
        ], 'ruc-na', $this->currentVersionId($vehicle, 'ruc'));
        $this->assertTrue($this->assess($vehicle)->canProceed);
    }

    public function test_expiry_uses_the_auckland_local_date_and_the_end_of_the_use(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $this->recordReadyEvidence($vehicle, $manager);
        app(VehicleComplianceService::class)->record($manager, $vehicle->id, 'wof', [
            'applicability' => 'applicable', 'outcome' => 'passed', 'evidence_reference' => 'WOF-SHORT',
            'expires_on' => '2026-09-22',
        ], 'wof-short', $this->currentVersionId($vehicle, 'wof'));

        $this->assertTrue($this->assess($vehicle)->canProceed);
        $tomorrow = $this->assess($vehicle, new VehicleReadinessContext(
            purpose: 'booking_request', startsAt: now()->addDay(), endsAt: now()->addDay()->addHour(),
        ));
        $this->assertContains('compliance.wof.expired', array_map(fn ($reason) => $reason->code, $tomorrow->reasons));

        // 00:30 on 23 Sept in Auckland is still 22 Sept in UTC; the Auckland date decides.
        $this->travelTo(Carbon::parse('2026-09-23 00:30:00', 'Pacific/Auckland')->utc());
        $this->assertSame('2026-09-22', now()->utc()->toDateString());
        $expired = $this->assess($vehicle);
        $this->assertFalse($expired->canProceed);
        $this->assertSame('WoF: recorded evidence has expired.', $expired->reasons[0]->message);
    }

    public function test_odometer_readings_are_retained_corrected_in_place_and_never_future_dated(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/odometer-observations";

        $older = $this->actingAs($manager)->postJson($url, [
            'value_km' => 48000, 'observed_local' => '2026-09-01T08:00', 'request_key' => 'odo-older',
        ])->assertOk()->json('observation');
        $latest = $this->actingAs($manager)->postJson($url, [
            'value_km' => 50000, 'observed_local' => '2026-09-21T16:00', 'request_key' => 'odo-latest',
        ])->assertOk()->json('observation');
        $this->assertSame('2026-09-21T04:00:00.000000Z', $latest['observed_at']);

        $this->actingAs($manager)->postJson($url, [
            'value_km' => 50100, 'observed_local' => '2026-09-22T10:30', 'request_key' => 'odo-future',
        ])->assertUnprocessable()->assertJsonValidationErrors('observed_at');
        $this->actingAs($manager)->postJson($url, [
            'value_km' => 50100, 'source_kind' => 'booking_checkout', 'request_key' => 'odo-claimed-source',
        ])->assertUnprocessable();

        // Correcting the older reading keeps its time, so the latest reading stays current.
        $correction = $this->actingAs($manager)->postJson($url, [
            'value_km' => 48100, 'corrects_observation_id' => $older['id'],
            'correction_reason' => 'Typed the wrong figure.', 'request_key' => 'odo-correct',
        ])->assertOk()->json('observation');
        $this->assertSame($older['observed_at'], $correction['observed_at']);
        $this->actingAs($manager)->postJson($url, [
            'value_km' => 48200, 'corrects_observation_id' => $older['id'],
            'correction_reason' => 'Second attempt.', 'request_key' => 'odo-correct-again',
        ])->assertUnprocessable()->assertJsonValidationErrors('corrects_observation_id');

        $this->assertSame(3, FleetVehicleOdometerObservation::query()->where('asset_id', $vehicle->id)->count());
        $this->assertSame('48000.0', (string) FleetVehicleOdometerObservation::query()->findOrFail($older['id'])->value_km);
        $current = app(VehicleOdometerService::class)->currentObserved($vehicle->id);
        $this->assertSame($latest['id'], $current->id);
        $this->assertSame('50000.0', (string) $vehicle->fresh()->odometer_km);

        // A tracker estimate is reported separately and never replaces the observed reading.
        DB::table('fleet_telemetry_events')->insert([
            'asset_id' => $vehicle->id, 'vendor' => 'queclink', 'event_type' => 'position',
            'occurred_at' => now()->subMinutes(5), 'received_at' => now()->subMinutes(4),
            'odometer_km' => 51234.5, 'consent_blocked' => false, 'idempotency_key' => 'pkg02b-tracker-estimate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $assessment = $this->assess($vehicle);
        $this->assertSame(50000.0, $assessment->odometerKm);
        $this->assertSame(51234.5, $assessment->trackerEstimate['value_km']);
    }

    public function test_approval_not_required_confirms_only_with_authority_readiness_and_acknowledgement(): void
    {
        $coordinator = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.bookings.approve'], driverEligible: true);
        $driver = $this->siteUser([$this->site], ['fleet.viewAny'], driverEligible: true);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $this->recordReadyEvidence($vehicle, $manager);
        $route = ['approval_route' => 'not_required', 'approval_not_required_reason' => 'Standing training authority.'];

        $this->actingAs($coordinator)->post('/fleet-assets/bookings', $this->storePayload($vehicle, 30) + ['approval_route' => 'not_required'])
            ->assertSessionHasErrors('approval_not_required_reason');
        $this->actingAs($coordinator)->post('/fleet-assets/bookings', $this->storePayload($vehicle, 30) + $route)
            ->assertSessionHasErrors('readiness_acknowledged');

        // Without booking authority the request waits for a coordinator.
        $this->actingAs($driver)->post('/fleet-assets/bookings', $this->storePayload($vehicle, 30) + $route)
            ->assertSessionHasNoErrors();
        $waiting = FleetVehicleBooking::query()->where('user_id', $driver->id)->sole();
        $this->assertSame('pending', $waiting->status);
        $this->assertNull($waiting->approval_authority_recorded_by);

        $this->actingAs($coordinator)->post('/fleet-assets/bookings', $this->storePayload($vehicle, 50) + $route + ['readiness_acknowledged' => true])
            ->assertSessionHasNoErrors();
        $confirmed = FleetVehicleBooking::query()->where('user_id', $coordinator->id)->sole();
        $this->assertSame('approved', $confirmed->status);
        $this->assertSame($coordinator->id, (int) $confirmed->approval_authority_recorded_by);
        $this->assertNull($confirmed->approved_by_user_id);
        $this->assertNotNull($confirmed->approved_at);

        // A coordinator confirms the waiting request; this is not an independent approval.
        $this->actingAs($coordinator)->post("/fleet-assets/bookings/{$waiting->id}/approve")->assertSessionHasNoErrors();
        $waiting->refresh();
        $this->assertSame('approved', $waiting->status);
        $this->assertSame($coordinator->id, (int) $waiting->approval_authority_recorded_by);
        $this->assertNull($waiting->approved_by_user_id);
    }

    public function test_self_approval_is_refused_only_on_the_independent_approval_route(): void
    {
        $coordinator = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.bookings.approve'], driverEligible: true);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $this->recordReadyEvidence($vehicle, $manager);
        $required = $this->booking($vehicle, $coordinator, 'Needs someone else', 'pending');
        $notRequired = $this->booking($vehicle, $coordinator, 'Own authority', 'pending', 4, [
            'approval_route' => 'not_required', 'approval_not_required_reason' => 'Standing authority.',
        ]);

        $this->actingAs($coordinator)->post("/fleet-assets/bookings/{$required->id}/approve")->assertForbidden();
        $this->actingAs($coordinator)->post("/fleet-assets/bookings/{$notRequired->id}/approve")->assertSessionHasNoErrors();

        $this->assertSame('pending', $required->fresh()->status);
        $this->assertSame('approved', $notRequired->fresh()->status);
    }

    public function test_overlapping_requests_are_refused_while_readiness_problems_leave_the_request_pending(): void
    {
        $driver = $this->siteUser([$this->site], ['fleet.viewAny'], driverEligible: true);
        $unlicensed = $this->siteUser([$this->site], ['fleet.viewAny']);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $this->recordReadyEvidence($vehicle, $manager);

        $this->actingAs($driver)->post('/fleet-assets/bookings', $this->storePayload($vehicle, 30))->assertSessionHasNoErrors();
        $this->actingAs($driver)->post('/fleet-assets/bookings', $this->storePayload($vehicle, 30))
            ->assertSessionHasErrors(['asset_id' => 'This vehicle is already booked for the selected time period.']);

        $this->actingAs($unlicensed)->post('/fleet-assets/bookings', $this->storePayload($vehicle, 60))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'licence'));
        $this->assertSame('pending', FleetVehicleBooking::query()->where('user_id', $unlicensed->id)->sole()->status);
    }

    public function test_custody_under_another_booking_blocks_only_overlapping_use(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'fleet.bookings.approve'], driverEligible: true);
        $vehicle = $this->vehicle($this->site);
        $this->recordReadyEvidence($vehicle, $manager);
        $out = $this->booking($vehicle, $manager, 'Currently out', 'checked_out', -1);
        $out->update(['checked_out_at' => now()->subHour(), 'odometer_out' => 50000]);

        $nextWeek = $this->assess($vehicle, new VehicleReadinessContext(
            purpose: 'booking_approval', driverUserId: $manager->id,
            startsAt: now()->addWeek(), endsAt: now()->addWeek()->addHour(),
        ));
        $this->assertTrue($nextWeek->canProceed, json_encode($nextWeek->toArray()));

        $overlapping = $this->assess($vehicle, new VehicleReadinessContext(
            purpose: 'booking_approval', driverUserId: $manager->id,
            startsAt: now()->addMinutes(30), endsAt: now()->addHours(3),
        ));
        $this->assertContains('booking.checked_out_custody', array_map(fn ($reason) => $reason->code, $overlapping->reasons));
    }

    public function test_return_reading_cannot_be_lower_than_the_checkout_reading(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage'], driverEligible: true);
        $vehicle = $this->vehicle($this->site);
        $this->recordReadyEvidence($vehicle, $manager);
        $booking = $this->booking($vehicle, $manager, 'Out and back', 'approved');
        $this->actingAs($manager)->post("/fleet-assets/bookings/{$booking->id}/checkout", ['odometer_out' => 50020])
            ->assertSessionHasNoErrors();

        $this->actingAs($manager)->post("/fleet-assets/bookings/{$booking->id}/return", ['odometer_in' => 50010])
            ->assertSessionHasErrors('odometer_in');
        $this->actingAs($manager)->post("/fleet-assets/bookings/{$booking->id}/return", ['odometer_in' => 50045])
            ->assertSessionHasNoErrors();

        $this->assertSame('returned', $booking->fresh()->status);
        $this->assertSame('50045.0', (string) app(VehicleOdometerService::class)->currentObserved($vehicle->id)->value_km);
    }

    public function test_legacy_vehicle_fields_are_refused_only_when_they_would_change_the_evidence(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.update', 'assets.create', 'assets.viewAny']);
        $vehicle = $this->vehicle($this->site, ['wof_expires_at' => '2027-01-31', 'odometer_km' => 42000]);

        // Unchanged values sent by an existing edit form are accepted.
        $this->actingAs($manager)->put("/fleet-assets/vehicles/{$vehicle->id}", [
            'seating_capacity' => 7, 'wof_expires_at' => '2027-01-31', 'odometer_km' => '42000', 'cof_expires_at' => null,
        ])->assertSessionHasNoErrors();
        $this->assertSame(7, (int) $vehicle->fresh()->seating_capacity);

        $this->actingAs($manager)->put("/fleet-assets/vehicles/{$vehicle->id}", ['wof_expires_at' => '2027-06-30'])
            ->assertSessionHasErrors('vehicle_evidence');
        $this->actingAs($manager)->put("/fleet-assets/vehicles/{$vehicle->id}", ['odometer_km' => 43000])
            ->assertSessionHasErrors('vehicle_evidence');
        $this->assertSame('2027-01-31', $vehicle->fresh()->wof_expires_at->toDateString());
    }

    private function recordReadyEvidence(Asset $vehicle, User $actor): void
    {
        $service = app(VehicleComplianceService::class);
        $service->record($actor, $vehicle->id, 'registration', [
            'applicability' => 'applicable', 'outcome' => 'recorded',
            'evidence_reference' => 'REG-DEMO-14', 'expires_on' => '2027-03-31',
        ], "ready-registration-{$vehicle->id}", $this->currentVersionId($vehicle, 'registration'));
        $service->record($actor, $vehicle->id, 'wof', $this->wofPassed(), "ready-wof-{$vehicle->id}", $this->currentVersionId($vehicle, 'wof'));
        $service->record($actor, $vehicle->id, 'cof', [
            'applicability' => 'not_applicable', 'applicability_basis' => 'Light van; a WoF applies instead.',
            'outcome' => 'needs_assessment',
        ], "ready-cof-{$vehicle->id}", $this->currentVersionId($vehicle, 'cof'));
        $service->record($actor, $vehicle->id, 'ruc', [
            'applicability' => 'not_applicable', 'applicability_basis' => 'Recorded by the fleet owner.',
            'outcome' => 'needs_assessment',
        ], "ready-ruc-{$vehicle->id}", $this->currentVersionId($vehicle, 'ruc'));
        $this->recordReading($vehicle, $actor, 50000, 'ready');
    }

    private function recordReading(Asset $vehicle, User $actor, float $km, string $key): void
    {
        app(VehicleOdometerService::class)->recordManual($actor, $vehicle->id, [
            'value_km' => $km, 'observed_at' => now()->subMinutes(10)->toIso8601String(),
        ], "reading-{$key}-{$vehicle->id}");
    }

    private function currentVersionId(Asset $vehicle, string $kind): ?int
    {
        $id = DB::table('fleet_vehicle_compliance_records')->where('asset_id', $vehicle->id)
            ->where('kind', $kind)->value('current_version_id');

        return $id === null ? null : (int) $id;
    }

    private function assess(Asset $vehicle, ?VehicleReadinessContext $context = null): VehicleReadinessAssessment
    {
        return app(VehicleReadinessService::class)->assess($vehicle->fresh(), $context);
    }

    /** @return array<string,mixed> */
    private function wofPassed(): array
    {
        return ['applicability' => 'applicable', 'outcome' => 'passed', 'evidence_reference' => 'WOF-DEMO-14', 'expires_on' => '2027-04-09'];
    }

    /** @return array<string,mixed> */
    private function wofNeedsAssessment(): array
    {
        return ['applicability' => 'applicable', 'outcome' => 'needs_assessment'];
    }

    /**
     * @param  list<Site>  $sites
     * @param  list<string>  $permissions
     */
    private function siteUser(array $sites, array $permissions, bool $driverEligible = false): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $sites[0]->id,
            'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->values()->all(),
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $user->permissionOverrides()->syncWithoutDetaching(collect($permissions)->mapWithKeys(fn (string $key): array => [
            Permission::query()->firstOrCreate(['key' => $key], [
                'description' => $key, 'group' => str($key)->before('.')->value(), 'module' => str($key)->before('.')->value(),
            ])->id => ['allowed' => true],
        ])->all());
        $user->unsetRelation('permissionOverrides');

        if ($driverEligible) {
            HrDriverEligibility::query()->create([
                'tenant_id' => 1, 'user_id' => $user->id, 'licence_number' => 'DL-'.$user->id,
                'licence_class' => '1', 'licence_expires_at' => today()->addYear(), 'status' => 'eligible',
                'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
        }

        return $user;
    }

    /** @param array<string,mixed> $attributes */
    private function vehicle(Site $site, array $attributes = []): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id, 'home_site_id' => $site->id, 'name' => 'Kōwhai van', 'status' => 'active',
            ...$attributes,
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function booking(Asset $vehicle, User $owner, string $purpose, string $status, int $startsInHours = 1, array $attributes = []): FleetVehicleBooking
    {
        return FleetVehicleBooking::query()->create([
            'asset_id' => $vehicle->id, 'user_id' => $owner->id, 'purpose' => $purpose,
            'starts_at' => now()->addHours($startsInHours), 'ends_at' => now()->addHours($startsInHours + 2),
            'pickup_site_id' => $vehicle->site_id, 'return_site_id' => $vehicle->site_id, 'status' => $status,
            ...$attributes,
        ]);
    }

    /** @return array<string,mixed> */
    private function storePayload(Asset $vehicle, int $startsInHours): array
    {
        return [
            'asset_id' => $vehicle->id, 'purpose' => 'Training trip',
            'starts_at' => now()->addHours($startsInHours)->toISOString(),
            'ends_at' => now()->addHours($startsInHours + 1)->toISOString(),
            'pickup_site_id' => $vehicle->site_id, 'return_site_id' => $vehicle->site_id,
        ];
    }
}
