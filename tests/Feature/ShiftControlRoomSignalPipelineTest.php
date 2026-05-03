<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Events\CoverageSupplyAdded;
use App\Jobs\ShiftAutoAlertJob;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\CoverageGapAcknowledgement;
use App\Models\FleetResidentTransport;
use App\Models\FleetVehicleBooking;
use App\Models\MedicationRound;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftSignal;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomNotificationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftControlRoomSignalPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The schema dump used by tests captures table structure only; data
        // inserts from `register_shift_control_room_signal_support` (signal
        // sources/types/rules) need to be re-applied so the dedupe logic
        // can match an existing rule.
        $this->seed(\Database\Seeders\ShiftControlRoomSignalRegistrationSeeder::class);

        $notifications = $this->mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifyAlert')->andReturnNull();
    }

    public function test_no_show_signal_emits_once_at_threshold_and_dedupes_on_rerun(): void
    {
        // The no-show detector emits the medium severity signal at the 30-minute
        // threshold; setting `now` 35 minutes after the planned start crosses it.
        $this->travelTo(Carbon::parse('2026-04-06 10:35:00'));

        $shift = $this->makeShift([
            'starts_at' => now()->subMinutes(35),
            'ends_at' => now()->addHours(4),
            'status' => 'scheduled',
        ]);

        $this->runJob();
        $this->runJob();

        $this->assertDatabaseCount('shift_signals', 1);
        $this->assertDatabaseHas('shift_signals', [
            'shift_id' => $shift->id,
            'signal_type' => 'shift_no_show',
            'severity_hint' => 'medium',
        ]);
        $this->assertDatabaseCount('control_room_signals', 1);
        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'shift_operations',
            'alert_type' => 'Shift No Show',
            'severity' => 'medium',
        ]);
    }

    public function test_no_show_escalation_updates_existing_alert_without_noisy_duplicates(): void
    {
        $shift = $this->makeShift([
            'starts_at' => Carbon::parse('2026-04-06 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 14:00:00'),
            'status' => 'scheduled',
        ]);

        // First run crosses the 30-minute medium threshold; second crosses the
        // 60-minute high threshold so the alert escalates without duplicates.
        $this->travelTo(Carbon::parse('2026-04-06 10:35:00'));
        $this->runJob();

        $this->travelTo(Carbon::parse('2026-04-06 11:05:00'));
        $this->runJob();

        $this->assertSame(2, ShiftSignal::query()->where('shift_id', $shift->id)->where('signal_type', 'shift_no_show')->count());
        $this->assertSame(2, Signal::query()->where('signal_type_code', 'shift_no_show')->count());
        $this->assertSame(1, ControlRoomAlert::query()->where('alert_type', 'Shift No Show')->count());

        $alert = ControlRoomAlert::query()->where('alert_type', 'Shift No Show')->firstOrFail();

        $this->assertSame('high', $alert->severity);
        $this->assertCount(1, $alert->context['correlated_signals'] ?? []);
    }

    public function test_no_show_transitions_to_late_start_when_start_evidence_appears(): void
    {
        $shift = $this->makeShift([
            'starts_at' => Carbon::parse('2026-04-06 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 14:00:00'),
            'status' => 'scheduled',
        ]);

        // First job run is 35 minutes past the planned start so the no-show
        // medium threshold (30 min) is crossed.
        $this->travelTo(Carbon::parse('2026-04-06 10:35:00'));
        $this->runJob();

        // Actual start is 31 minutes late so the late-start medium threshold
        // (30 min) fires on the next pass and the alert transitions type.
        $shift->forceFill([
            'actual_starts_at' => Carbon::parse('2026-04-06 10:31:00'),
            'status' => 'in_progress',
        ])->save();

        $this->travelTo(Carbon::parse('2026-04-06 10:40:00'));
        $this->runJob();

        $this->assertSame(1, ControlRoomAlert::query()->count());
        $this->assertSame(1, ControlRoomAlert::query()->where('alert_type', 'Shift Late Start')->count());
        $this->assertSame(0, ControlRoomAlert::query()->unresolved()->where('alert_type', 'Shift No Show')->count());

        $alert = ControlRoomAlert::query()->firstOrFail();

        $this->assertSame('Shift Late Start', $alert->alert_type);
        $this->assertSame('medium', $alert->severity);
        $this->assertNotEmpty($alert->context['state_transitions'] ?? []);
        $this->assertSame('Shift No Show', $alert->context['state_transitions'][0]['from_alert_type'] ?? null);
        $this->assertSame('Shift Late Start', $alert->context['state_transitions'][0]['to_alert_type'] ?? null);
        $this->assertSame('shift_late_start', $alert->context['signal_type_code'] ?? null);
        $this->assertSame(2, Signal::query()->whereIn('signal_type_code', ['shift_no_show', 'shift_late_start'])->count());
    }

    public function test_no_show_does_not_remain_stale_after_shift_actually_starts(): void
    {
        $shift = $this->makeShift([
            'starts_at' => Carbon::parse('2026-04-06 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 14:00:00'),
            'status' => 'scheduled',
        ]);

        // First run: 35 min late triggers the medium no-show threshold.
        $this->travelTo(Carbon::parse('2026-04-06 10:35:00'));
        $this->runJob();

        // Actual start is 31 min late so the late-start signal fires; the
        // second run is far enough past actualStart + 15 minutes that the
        // start-anomaly resolver clears the alert with attendance evidence.
        $shift->forceFill([
            'actual_starts_at' => Carbon::parse('2026-04-06 10:31:00'),
            'status' => 'in_progress',
        ])->save();

        $this->travelTo(Carbon::parse('2026-04-06 10:55:00'));
        $this->runJob();

        $alert = ControlRoomAlert::query()->firstOrFail();

        $this->assertSame('resolved', $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame('attendance_evidence', $alert->context['resolution']['source'] ?? null);
        $this->assertSame('Shift Late Start', $alert->alert_type);
        $this->assertSame(0, ControlRoomAlert::query()->unresolved()->where('alert_type', 'Shift No Show')->count());
    }

    public function test_late_start_signal_creates_enriched_control_room_context(): void
    {
        $this->travelTo(Carbon::parse('2026-04-06 10:45:00'));

        $shift = $this->makeShift([
            'starts_at' => Carbon::parse('2026-04-06 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 14:00:00'),
            // Actual start 31 minutes late triggers the late-start medium
            // threshold (30 min) when the job runs.
            'actual_starts_at' => Carbon::parse('2026-04-06 10:31:00'),
            'status' => 'in_progress',
        ]);

        $medication = ClientMedication::factory()->create([
            'client_id' => $shift->client_id,
            'name' => 'Paracetamol',
        ]);

        $round = MedicationRound::create([
            'service_context_id' => $shift->service_context_id,
            'site_id' => $shift->site_id,
            'name' => 'Morning Round',
            'scheduled_time' => '10:45:00',
            'round_date' => now()->toDateString(),
            'status' => 'pending',
        ]);

        ClientMedicationAdministration::create([
            'client_id' => $shift->client_id,
            'client_medication_id' => $medication->id,
            'shift_id' => $shift->id,
            'medication_round_id' => $round->id,
            'administered_by' => $shift->user_id,
            'scheduled_for' => now()->addMinutes(10),
            'status' => 'pending',
        ]);

        ClientIncident::factory()->create([
            'client_id' => $shift->client_id,
            'reported_by' => $shift->user_id,
            'shift_id' => $shift->id,
            'title' => 'Resident fall',
            'status' => 'submitted',
            'severity' => 'high',
            'occurred_at' => now()->subMinutes(5),
        ]);

        $booking = FleetVehicleBooking::factory()->create([
            'user_id' => $shift->user_id,
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
            'status' => 'approved',
        ]);

        FleetResidentTransport::create([
            'asset_id' => $booking->asset_id,
            'booking_id' => $booking->id,
            'shift_id' => $shift->id,
            'site_id' => $shift->site_id,
            'service_context_id' => $shift->service_context_id,
            'driver_user_id' => $shift->user_id,
            'resident_id' => $shift->client_id,
            'resident_name' => $shift->client->first_name.' '.$shift->client->last_name,
            'transport_type' => 'medical',
            'pickup_location' => 'House',
            'dropoff_location' => 'Clinic',
            'departed_at' => now()->subMinutes(5),
            'status' => 'scheduled',
        ]);

        $this->runJob();

        $this->assertDatabaseHas('shift_signals', [
            'shift_id' => $shift->id,
            'signal_type' => 'shift_late_start',
            'severity_hint' => 'medium',
        ]);

        $alert = ControlRoomAlert::query()->where('alert_type', 'Shift Late Start')->firstOrFail();
        $context = $alert->context['signal_payload']['shift_context'] ?? [];

        // Structural triage data is present
        $this->assertSame($shift->id, $context['shift']['id'] ?? null);
        $this->assertSame($shift->site->name, $context['site']['name'] ?? null);

        // References use IDs only — no names embedded
        $this->assertSame($shift->staff->id, $context['staff']['id'] ?? null);
        $this->assertArrayNotHasKey('name', $context['staff'] ?? []);
        $this->assertSame($shift->client->id, $context['client']['id'] ?? null);
        $this->assertArrayNotHasKey('name', $context['client'] ?? []);

        // Medications: summary only, no medication names
        $this->assertTrue($context['medications_due_soon']['has_due'] ?? false);
        $this->assertGreaterThan(0, $context['medications_due_soon']['count'] ?? 0);

        // Incidents: summary only, no titles or narrative
        $this->assertTrue($context['active_incidents']['has_active'] ?? false);
        $this->assertGreaterThan(0, $context['active_incidents']['count'] ?? 0);

        // Transport: summary only, no resident names or locations
        $this->assertTrue($context['transport']['has_active'] ?? false);
        $this->assertGreaterThan(0, $context['transport']['count'] ?? 0);
    }

    public function test_not_completed_signal_emits_when_shift_runs_past_end_without_completion(): void
    {
        $this->travelTo(Carbon::parse('2026-04-06 10:35:00'));

        $shift = $this->makeShift([
            'starts_at' => Carbon::parse('2026-04-06 06:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 10:00:00'),
            'actual_starts_at' => Carbon::parse('2026-04-06 06:01:00'),
            'status' => 'in_progress',
        ]);

        $this->runJob();

        $this->assertDatabaseHas('shift_signals', [
            'shift_id' => $shift->id,
            'signal_type' => 'shift_not_completed',
            'severity_hint' => 'medium',
        ]);
        $this->assertDatabaseHas('control_room_alerts', [
            'alert_type' => 'Shift Not Completed',
            'severity' => 'medium',
        ]);
    }

    public function test_uncovered_shift_signal_emits_for_requirement_deficit_without_unassigned_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-04-06 10:05:00'));

        $shift = $this->makeShift([
            'starts_at' => Carbon::parse('2026-04-06 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 14:00:00'),
            'status' => 'scheduled',
        ]);

        $this->createCoverageRequirement($shift->site, $shift->serviceContext, [
            'minimum_staff' => 2,
            'day_of_week' => 'mon',
            'starts_time' => '10:00',
            'ends_time' => '14:00',
            'role_requirements' => [
                ['key' => 'caregiver', 'minimum' => 2],
            ],
        ]);

        $this->runJob();

        $signal = ShiftSignal::query()->where('signal_type', 'shift_uncovered')->firstOrFail();

        $alert = ControlRoomAlert::query()->where('alert_type', 'Shift Uncovered')->firstOrFail();
        $coverageContext = $alert->context['signal_payload']['shift_context']['coverage_window'] ?? [];

        $this->assertSame(
            'This coverage window has an active staffing deficit and requires review.',
            $alert->context['signal_payload']['reason'] ?? null
        );
        $this->assertNull($signal->shift_id);
        $this->assertSame($shift->site_id, $signal->site_id);
        $this->assertSame(2, $coverageContext['required_staff'] ?? null);
        $this->assertSame(1, $coverageContext['assigned_staff'] ?? null);
        $this->assertSame(1, $coverageContext['deficit'] ?? null);
        $this->assertSame($shift->site->name, $coverageContext['site_name'] ?? null);
    }

    public function test_cancelled_shifts_do_not_emit_alerts(): void
    {
        $this->travelTo(Carbon::parse('2026-04-06 10:40:00'));

        $this->makeShift([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'status' => 'cancelled',
        ]);

        $this->runJob();

        $this->assertDatabaseCount('shift_signals', 0);
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_completed_or_attendance_resolved_shifts_do_not_emit_alerts(): void
    {
        $this->travelTo(Carbon::parse('2026-04-06 10:40:00'));

        $completedShift = $this->makeShift([
            'starts_at' => now()->subHours(5),
            'ends_at' => now()->subHour(),
            'actual_starts_at' => now()->subHours(5),
            'actual_ends_at' => now()->subHour(),
            'status' => 'completed',
        ]);

        $attendanceResolvedShift = $this->makeShift([
            'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->addHours(3),
            'status' => 'scheduled',
        ]);

        HrAttendanceSession::create([
            'user_id' => $attendanceResolvedShift->user_id,
            'shift_id' => $attendanceResolvedShift->id,
            'site_id' => $attendanceResolvedShift->site_id,
            'clock_in_at' => now()->subMinutes(5),
            'status' => 'open',
            'source' => 'test',
            'created_by' => $attendanceResolvedShift->created_by,
        ]);

        $this->runJob();

        $this->assertSame(0, ShiftSignal::query()->whereIn('shift_id', [$completedShift->id, $attendanceResolvedShift->id])->count());
    }

    public function test_not_completed_alert_resolves_after_actual_completion(): void
    {
        $shift = $this->makeShift([
            'starts_at' => Carbon::parse('2026-04-06 06:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 10:00:00'),
            'actual_starts_at' => Carbon::parse('2026-04-06 06:01:00'),
            'status' => 'in_progress',
        ]);

        $this->travelTo(Carbon::parse('2026-04-06 10:35:00'));
        $this->runJob();

        $shift->forceFill([
            'actual_ends_at' => Carbon::parse('2026-04-06 10:38:00'),
            'status' => 'completed',
        ])->save();

        $this->travelTo(Carbon::parse('2026-04-06 10:40:00'));
        $this->runJob();

        $alert = ControlRoomAlert::query()->where('alert_type', 'Shift Not Completed')->firstOrFail();

        $this->assertSame('resolved', $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame('shift_completion', $alert->context['resolution']['source'] ?? null);
        $this->assertTrue(
            Carbon::parse($alert->context['resolution']['actual_end'] ?? '')->equalTo(Carbon::parse('2026-04-06 10:38:00'))
        );
    }

    public function test_uncovered_alert_resolves_after_coverage_is_restored(): void
    {
        $this->travelTo(Carbon::parse('2026-04-06 10:05:00'));

        $primaryShift = $this->makeShift([
            'starts_at' => Carbon::parse('2026-04-06 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 14:00:00'),
            'status' => 'scheduled',
        ]);

        $this->createCoverageRequirement($primaryShift->site, $primaryShift->serviceContext, [
            'minimum_staff' => 2,
            'day_of_week' => 'mon',
            'starts_time' => '10:00',
            'ends_time' => '14:00',
        ]);

        $this->runJob();

        $alert = ControlRoomAlert::query()->where('alert_type', 'Shift Uncovered')->firstOrFail();
        $coverageWindowKey = $alert->context['normalized_data']['coverage_window_key'] ?? null;
        $this->assertNotNull($coverageWindowKey);
        $this->assertSame('open', $alert->status);

        $this->makeShift([
            'site' => $primaryShift->site,
            'client' => $primaryShift->client,
            'service_context' => $primaryShift->serviceContext,
            'starts_at' => Carbon::parse('2026-04-06 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 14:00:00'),
            'status' => 'scheduled',
        ]);

        $this->travelTo(Carbon::parse('2026-04-06 10:10:00'));
        $this->runJob();

        $alert->refresh();

        $this->assertSame('resolved', $alert->status);
        $this->assertSame('coverage_restored', $alert->context['resolution']['source'] ?? null);
        $this->assertSame($coverageWindowKey, $alert->context['resolution']['coverage_window_key'] ?? null);
    }

    public function test_future_uncovered_alert_stays_open_until_stored_window_elapses(): void
    {
        $this->travelTo(Carbon::parse('2026-04-06 10:05:00'));

        $site = Site::factory()->create();
        $serviceContext = ServiceContext::factory()->create();
        $rule = $this->createCoverageRequirement($site, $serviceContext, [
            'minimum_staff' => 1,
            'day_of_week' => 'mon',
            'starts_time' => '11:00',
            'ends_time' => '12:00',
        ]);
        $alert = $this->createCoverageAlertForWindow(
            $site,
            $rule,
            Carbon::parse('2026-04-06 11:00:00'),
            Carbon::parse('2026-04-06 12:00:00'),
        );

        $this->runJob();
        $alert->refresh();

        $this->assertSame('open', $alert->status);
        $this->assertNull($alert->resolved_at);

        $this->travelTo(Carbon::parse('2026-04-06 12:01:00'));
        $this->runJob();
        $alert->refresh();

        $this->assertSame('resolved', $alert->status);
        $this->assertSame('window_elapsed', $alert->context['resolution']['source'] ?? null);
    }

    public function test_partial_window_uncovered_alert_waits_for_remaining_slice_to_be_filled(): void
    {
        $this->travelTo(Carbon::parse('2026-04-06 10:30:00'));

        $primaryShift = $this->makeShift([
            'starts_at' => Carbon::parse('2026-04-06 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 11:00:00'),
            'status' => 'scheduled',
        ]);

        $rule = $this->createCoverageRequirement($primaryShift->site, $primaryShift->serviceContext, [
            'minimum_staff' => 1,
            'day_of_week' => 'mon',
            'starts_time' => '10:00',
            'ends_time' => '12:00',
        ]);
        $alert = $this->createCoverageAlertForWindow(
            $primaryShift->site,
            $rule,
            Carbon::parse('2026-04-06 10:00:00'),
            Carbon::parse('2026-04-06 12:00:00'),
        );

        $this->runJob();
        $alert->refresh();

        $this->assertSame('open', $alert->status);
        $this->assertNull($alert->resolved_at);

        $this->makeShift([
            'site' => $primaryShift->site,
            'client' => $primaryShift->client,
            'service_context' => $primaryShift->serviceContext,
            'starts_at' => Carbon::parse('2026-04-06 11:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 12:00:00'),
            'status' => 'scheduled',
        ]);

        $this->travelTo(Carbon::parse('2026-04-06 10:40:00'));
        $this->runJob();
        $alert->refresh();

        $this->assertSame('resolved', $alert->status);
        $this->assertSame('coverage_restored', $alert->context['resolution']['source'] ?? null);
    }

    public function test_supply_added_event_resolves_coverage_alert_with_action_metadata(): void
    {
        $this->travelTo(Carbon::parse('2026-04-06 10:05:00'));

        $site = Site::factory()->create();
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $serviceContext = ServiceContext::factory()->create();
        $actor = User::factory()->create();
        $rule = $this->createCoverageRequirement($site, $serviceContext, [
            'minimum_staff' => 1,
            'day_of_week' => 'mon',
            'starts_time' => '10:00',
            'ends_time' => '11:00',
        ]);
        $windowStart = Carbon::parse('2026-04-06 10:05:00');
        $windowEnd = Carbon::parse('2026-04-06 10:35:00');
        $alert = $this->createCoverageAlertForWindow($site, $rule, $windowStart, $windowEnd);
        $coverageWindowKey = $alert->context['normalized_data']['coverage_window_key'];

        CoverageGapAcknowledgement::query()->create([
            'site_id' => $site->id,
            'coverage_requirement_id' => $rule->id,
            'coverage_window_key' => $coverageWindowKey,
            'window_starts_at' => $windowStart,
            'window_ends_at' => $windowEnd,
            'state' => CoverageGapAcknowledgement::STATE_ACKED,
            'reason' => 'Calling staff',
            'actor_user_id' => $actor->id,
            'created_at' => now(),
        ]);

        $shift = $this->makeShift([
            'site' => $site,
            'client' => $client,
            'service_context' => $serviceContext,
            'starts_at' => Carbon::parse('2026-04-06 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 11:00:00'),
            'status' => 'scheduled',
        ]);

        CoverageSupplyAdded::dispatch(
            $coverageWindowKey,
            $site->id,
            $rule->id,
            $windowStart->toIso8601String(),
            $windowEnd->toIso8601String(),
            $shift->id,
            null,
            $actor->id,
            'create_cover_shift',
        );

        $alert->refresh();

        $this->assertSame('resolved', $alert->status);
        $this->assertSame('coverage_restored', $alert->context['resolution']['source'] ?? null);
        $this->assertSame($coverageWindowKey, $alert->context['resolution']['coverage_window_key'] ?? null);
        $this->assertSame($actor->id, $alert->context['resolution']['actor_user_id'] ?? null);
        $this->assertSame($shift->id, $alert->context['resolution']['shift_id'] ?? null);
        $this->assertSame('create_cover_shift', $alert->context['resolution']['action'] ?? null);
        $this->assertSame(0, CoverageGapAcknowledgement::query()
            ->where('coverage_window_key', $coverageWindowKey)
            ->whereNull('cleared_at')
            ->count());
    }

    public function test_scheduler_reruns_do_not_create_noisy_duplicates_for_same_coverage_deficit_window(): void
    {
        $this->travelTo(Carbon::parse('2026-04-06 10:05:00'));

        $shift = $this->makeShift([
            'starts_at' => Carbon::parse('2026-04-06 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 14:00:00'),
            'status' => 'scheduled',
        ]);

        $this->createCoverageRequirement($shift->site, $shift->serviceContext, [
            'minimum_staff' => 2,
            'day_of_week' => 'mon',
            'starts_time' => '10:00',
            'ends_time' => '14:00',
        ]);

        $this->runJob();
        $this->runJob();

        $this->assertSame(1, ShiftSignal::query()->where('signal_type', 'shift_uncovered')->count());
        $this->assertSame(1, Signal::query()->where('signal_type_code', 'shift_uncovered')->count());
        $this->assertSame(1, ControlRoomAlert::query()->where('alert_type', 'Shift Uncovered')->count());
    }

    public function test_legacy_uncovered_cleanup_is_bounded_to_unresolved_shift_alerts(): void
    {
        $this->travelTo(Carbon::parse('2026-04-06 10:05:00'));

        foreach (range(1, 501) as $shiftId) {
            ControlRoomAlert::factory()->create([
                'source' => 'shift_operations',
                'alert_type' => 'Shift Uncovered',
                'severity' => 'medium',
                'status' => 'open',
                'triggered_at' => now(),
                'context' => [
                    'normalized_data' => [
                        'shift_id' => $shiftId,
                    ],
                ],
            ]);
        }

        $this->runJob();

        $this->assertSame(500, ControlRoomAlert::query()
            ->where('alert_type', 'Shift Uncovered')
            ->where('status', 'resolved')
            ->count());
        $this->assertSame(1, ControlRoomAlert::query()
            ->where('alert_type', 'Shift Uncovered')
            ->where('status', 'open')
            ->count());
    }

    public function test_resolution_metadata_is_persisted_and_queryable(): void
    {
        $shift = $this->makeShift([
            'starts_at' => Carbon::parse('2026-04-06 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 14:00:00'),
            'status' => 'scheduled',
        ]);

        // First run is past the 30-minute medium threshold so the no-show
        // alert exists.
        $this->travelTo(Carbon::parse('2026-04-06 10:35:00'));
        $this->runJob();

        // Actual start at 31 minutes late triggers the late-start medium
        // threshold; the second run is past actualStart + 15 minutes so the
        // resolver clears the alert with attendance evidence.
        $shift->forceFill([
            'actual_starts_at' => Carbon::parse('2026-04-06 10:31:00'),
            'status' => 'in_progress',
        ])->save();

        $this->travelTo(Carbon::parse('2026-04-06 10:55:00'));
        $this->runJob();

        $alert = ControlRoomAlert::query()->firstOrFail();

        $this->assertNotNull($alert->resolved_at);
        $this->assertSame('attendance_evidence', $alert->context['resolution']['source'] ?? null);
        $this->assertSame(
            'Late-start alert resolved because the shift is now clearly underway with attendance evidence.',
            $alert->context['resolution']['reason'] ?? null
        );
        $this->assertSame($shift->id, $alert->context['resolution']['shift_id'] ?? null);
    }

    public function test_shift_auto_alert_job_is_registered_on_the_scheduler(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn ($event) => $event->description === ShiftAutoAlertJob::class);

        $this->assertTrue($scheduled);
    }

    protected function makeShift(array $attributes = []): Shift
    {
        $site = $attributes['site'] ?? Site::factory()->create();
        $client = $attributes['client'] ?? Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $serviceContext = $attributes['service_context'] ?? ServiceContext::factory()->create();
        $staff = array_key_exists('user_id', $attributes)
            ? null
            : ($attributes['staff'] ?? User::factory()->create());
        $creator = $attributes['creator'] ?? User::factory()->create();

        unset($attributes['site'], $attributes['client'], $attributes['service_context'], $attributes['staff'], $attributes['creator']);

        return Shift::factory()->create(array_merge([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => array_key_exists('user_id', $attributes) ? $attributes['user_id'] : $staff?->id,
            'created_by' => $creator->id,
            'starts_at' => now()->subMinutes(20),
            'ends_at' => now()->addHours(4),
            'status' => 'scheduled',
        ], $attributes));
    }

    protected function createCoverageRequirement(Site $site, ServiceContext $serviceContext, array $overrides = []): SiteCoverageRequirement
    {
        return SiteCoverageRequirement::create(array_merge([
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'name' => 'Morning Coverage',
            'coverage_type' => 'custom',
            'day_of_week' => strtolower(now()->format('D')),
            'starts_time' => '10:00',
            'ends_time' => '14:00',
            'minimum_staff' => 1,
            'role_requirements' => [],
            'allow_overstaffing' => true,
            'is_active' => true,
        ], $overrides));
    }

    protected function createCoverageAlertForWindow(
        Site $site,
        SiteCoverageRequirement $rule,
        Carbon $windowStart,
        Carbon $windowEnd,
    ): ControlRoomAlert {
        $coverageWindow = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'starts_at' => $windowStart->toIso8601String(),
            'ends_at' => $windowEnd->toIso8601String(),
        ];
        $coverageWindowKey = app(\App\Services\ShiftSignalService::class)->buildCoverageWindowKey($coverageWindow);

        return ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift Uncovered',
            'severity' => 'high',
            'status' => 'open',
            'site_id' => $site->id,
            'triggered_at' => now(),
            'context' => [
                'normalized_data' => [
                    'coverage_window_key' => $coverageWindowKey,
                    'site_id' => $site->id,
                    'rule_id' => $rule->id,
                ],
                'signal_payload' => [
                    'shift_context' => [
                        'coverage_window' => $coverageWindow,
                    ],
                ],
            ],
        ]);
    }

    protected function runJob(): void
    {
        $job = new ShiftAutoAlertJob;
        $job->handle(
            app(\App\Services\ShiftSignalService::class),
            app(\App\Services\ShiftCoverageService::class),
            app(\App\Services\ControlRoom\SignalProcessingService::class),
        );
    }
}
