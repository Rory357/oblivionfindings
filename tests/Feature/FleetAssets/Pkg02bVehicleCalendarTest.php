<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetServiceSchedule;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleReminder;
use App\Models\FleetVehicleUnavailablePeriod;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PKG-02B vehicle calendar: the feed and summary behind the calendar, unavailable
 * periods (and undoing a cancellation), booking changes and returns, service
 * appointments (including ones planned from a due item), restriction records,
 * custody record lookups and reminder snoozing.
 */
class Pkg02bVehicleCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreignSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-22 09:30:00', 'Pacific/Auckland')->utc());
        $this->site = Site::factory()->create(['name' => 'Kōwhai House']);
        $this->foreignSite = Site::factory()->create(['name' => 'Rimu House']);
    }

    public function test_the_feed_shows_permitted_bookings_and_only_busy_time_for_others(): void
    {
        $manager = $this->siteUser([$this->site, $this->foreignSite], ['fleet.viewAny', 'fleet.manage']);
        $reader = $this->siteUser([$this->site], ['fleet.viewAny']);
        $outsider = $this->siteUser([$this->foreignSite], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $local = $this->booking($vehicle, $manager, '2026-09-24 10:00', '2026-09-24 12:00', ['purpose' => 'Library outing']);
        $private = $this->booking($vehicle, $manager, '2026-09-25 10:00', '2026-09-25 12:00', [
            'purpose' => 'Private appointment transport', 'pickup_site_id' => $this->foreignSite->id,
        ]);
        $window = '?start=2026-09-20T00:00:00Z&end=2026-10-05T00:00:00Z';
        $url = "/fleet-assets/vehicles/{$vehicle->id}/calendar";

        $items = collect($this->actingAs($reader)->getJson("{$url}/events{$window}")->assertOk()->json('events'));
        $this->assertSame('Library outing · Pending approval', $items->firstWhere('id', 'booking:'.$local->id)['title']);
        $busy = $items->firstWhere('id', 'busy:'.$private->id);
        $this->assertSame('Busy', $busy['title']);
        $this->assertNull($busy['ref']);
        $this->assertNull($busy['recordId']);
        $this->assertStringNotContainsString('Private', json_encode($items->all()));
        $this->assertSame([$local->id], collect($this->actingAs($reader)->getJson("{$url}/summary")->assertOk()->json('bookings'))
            ->where('kind', 'booking')->pluck('id')->all());

        // Someone who can open both sees both bookings in full.
        $managerItems = collect($this->actingAs($manager)->getJson("{$url}/events{$window}")->assertOk()->json('events'));
        $this->assertNotNull($managerItems->firstWhere('id', 'booking:'.$private->id));

        // The feed follows vehicle access, and a range is required and bounded.
        $this->actingAs($outsider)->getJson("{$url}/events{$window}")->assertNotFound();
        $this->actingAs($reader)->getJson("{$url}/events?start=2026-01-01T00:00:00Z&end=2027-12-31T00:00:00Z")->assertStatus(422);
        $this->actingAs($reader)->getJson("{$url}/events")->assertUnprocessable();
    }

    public function test_unavailable_periods_are_idempotent_versioned_and_block_bookings(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $reader = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/unavailable-periods";
        $body = ['starts_local' => '2026-09-24T08:00', 'ends_local' => '2026-09-24T17:00', 'reason' => 'Panel repair at the garage'];

        $this->actingAs($reader)->postJson($url, $body + ['request_key' => 'block-reader'])->assertForbidden();
        $this->actingAs($manager)->postJson($url, ['ends_local' => '2026-09-24T07:00'] + $body + ['request_key' => 'block-bad'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_local');
        $period = $this->actingAs($manager)->postJson($url, $body + ['request_key' => 'block-1'])->assertOk()->json('period');
        $this->assertSame('2026-09-23T20:00:00+00:00', FleetVehicleUnavailablePeriod::query()->findOrFail($period['id'])->starts_at->toIso8601String());
        // A retry returns the same period; reusing the key for something else is refused.
        $this->actingAs($manager)->postJson($url, $body + ['request_key' => 'block-1'])->assertOk()->assertJsonPath('period.id', $period['id']);
        $this->actingAs($manager)->postJson($url, ['reason' => 'Something else'] + $body + ['request_key' => 'block-1'])->assertStatus(409);
        $this->actingAs($manager)->postJson($url, ['starts_local' => '2026-09-24T12:00', 'ends_local' => '2026-09-24T19:00'] + $body + ['request_key' => 'block-2'])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_local');

        // Bookings can't be requested into the period, from any route in.
        $this->actingAs($manager)->postJson('/fleet-assets/bookings', [
            'asset_id' => $vehicle->id, 'purpose' => 'Shopping trip', 'starts_local' => '2026-09-24T09:00',
            'ends_local' => '2026-09-24T10:00', 'request_key' => 'booking-into-block',
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_local');

        $this->actingAs($manager)->putJson("{$url}/{$period['id']}", ['starts_local' => '2026-09-24T09:00',
            'ends_local' => '2026-09-24T16:00', 'reason' => 'Panel repair at the garage', 'expected_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('change_reason');
        $this->actingAs($manager)->putJson("{$url}/{$period['id']}", ['starts_local' => '2026-09-24T09:00',
            'ends_local' => '2026-09-24T16:00', 'reason' => 'Panel repair at the garage', 'change_reason' => 'Garage confirmed times.',
            'expected_version' => 1])->assertOk()->assertJsonPath('period.lock_version', 2);
        $this->actingAs($manager)->putJson("{$url}/{$period['id']}", ['starts_local' => '2026-09-24T10:00',
            'ends_local' => '2026-09-24T16:00', 'reason' => 'Panel repair at the garage', 'change_reason' => 'Stale edit.',
            'expected_version' => 1])->assertStatus(409);

        $this->actingAs($manager)->postJson("{$url}/{$period['id']}/cancel", ['reason' => 'Repair postponed.', 'expected_version' => 2])
            ->assertOk()->assertJsonPath('period.state', 'cancelled');
        $events = $this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/calendar/events?start=2026-09-20T00:00:00Z&end=2026-10-01T00:00:00Z")
            ->assertOk()->json('events');
        $this->assertNull(collect($events)->firstWhere('id', 'unavailable:'.$period['id']));
        $this->assertSame(3, DB::table('audit_logs')->where('auditable_type', (new FleetVehicleUnavailablePeriod)->getMorphClass())
            ->where('auditable_id', $period['id'])->count());
    }

    public function test_a_booking_change_rechecks_conflicts_and_versions(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $requester = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $created = $this->actingAs($requester)->postJson('/fleet-assets/bookings', [
            'asset_id' => $vehicle->id, 'purpose' => 'Library outing', 'starts_local' => '2026-09-24T09:00',
            'ends_local' => '2026-09-24T10:00', 'pickup_arrangement' => 'Office key box', 'request_key' => 'booking-1',
        ])->assertOk()->json('booking');
        $booking = FleetVehicleBooking::query()->findOrFail($created['id']);
        $this->assertSame('2026-09-23 21:00', $booking->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('Office key box', $booking->pickup_arrangement);
        $other = $this->booking($vehicle, $manager, '2026-09-25 09:00', '2026-09-25 11:00');

        $change = ['purpose' => 'Library outing', 'starts_local' => '2026-09-25T10:00', 'ends_local' => '2026-09-25T12:00',
            'reason' => 'Library changed the session.', 'expected_version' => 1];
        $this->actingAs($requester)->putJson("/fleet-assets/bookings/{$booking->id}", $change)
            ->assertUnprocessable();
        $this->actingAs($requester)->putJson("/fleet-assets/bookings/{$booking->id}", ['starts_local' => '2026-09-26T10:00',
            'ends_local' => '2026-09-26T12:00'] + $change)->assertOk();
        $this->assertSame(2, $booking->fresh()->lock_version);
        $this->actingAs($requester)->putJson("/fleet-assets/bookings/{$booking->id}", ['starts_local' => '2026-09-27T10:00',
            'ends_local' => '2026-09-27T12:00'] + $change)->assertStatus(409);
        $this->assertNotNull($other->fresh());

        // The summary shows the requester their booking with its history.
        $row = collect($this->actingAs($requester)->getJson("/fleet-assets/vehicles/{$vehicle->id}/calendar/summary")->json('bookings'))
            ->firstWhere('id', $booking->id);
        $this->assertSame(['Requested', 'Changed'], array_column($row['history'], 'label'));
        $this->assertTrue($row['can']['edit']);
        $this->assertFalse($row['can']['approve']);
    }

    public function test_service_appointments_plan_reschedule_overrun_and_cancel_with_one_vehicle_hold(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $reader = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $order = FleetWorkOrder::query()->create([
            'asset_id' => $vehicle->id, 'reported_by_user_id' => $manager->id, 'assigned_to_user_id' => $manager->id,
            'title' => 'Routine service', 'category' => 'vehicle', 'priority' => 'medium', 'status' => 'open',
        ]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/appointments";
        $plan = ['work_order_id' => $order->id, 'provider_name' => 'Hutt Valley Motors', 'unavailable' => true,
            'starts_local' => '2026-09-24T09:00', 'ends_local' => '2026-09-24T11:00', 'notes' => 'Annual service.'];

        $this->actingAs($reader)->postJson($url, $plan + ['request_key' => 'appt-reader'])->assertForbidden();
        $this->actingAs($manager)->postJson($url, ['starts_local' => '2026-09-21T09:00', 'ends_local' => '2026-09-21T10:00'] + $plan
            + ['request_key' => 'appt-past'])->assertUnprocessable()->assertJsonValidationErrors('starts_local');
        $this->actingAs($manager)->postJson($url, $plan + ['request_key' => 'appointment-1'])->assertOk();
        $held = FleetVehicleUnavailablePeriod::query()->where('work_order_id', $order->id)->sole();
        $events = collect($this->feed($manager, $vehicle));
        $appointment = $events->firstWhere('id', 'appointment:'.$order->id);
        $this->assertSame('Routine service · Planned appointment', $appointment['title']);
        $this->assertSame(['provider' => 'Hutt Valley Motors', 'unavailable' => true, 'open' => true], $appointment['meta']);
        $this->assertNotNull($events->firstWhere('id', 'unavailable:'.$held->id));

        // Rescheduling moves the same hold.
        $reschedule = ['operation' => 'plan', 'starts_local' => '2026-09-25T10:00',
            'ends_local' => '2026-09-25T12:00', 'change_reason' => 'Garage moved us.'] + $plan + ['request_key' => 'appointment-2'];
        $this->actingAs($manager)->postJson($url, $reschedule)->assertOk();
        $this->assertSame('2026-09-24 22:00', $held->fresh()->starts_at->utc()->format('Y-m-d H:i'));
        // A retried reschedule changes nothing more (no second history entry or version).
        $moved = $held->fresh()->lock_version;
        $this->actingAs($manager)->postJson($url, $reschedule)->assertOk();
        $this->assertSame($moved, $held->fresh()->lock_version);

        // An overrun keeps the start and needs a later end and a reason.
        $overrun = ['operation' => 'overrun', 'starts_local' => '2026-09-25T10:00', 'ends_local' => '2026-09-25T11:00',
            'change_reason' => 'Parts arrived late.'] + $plan;
        $this->actingAs($manager)->postJson($url, $overrun + ['request_key' => 'appointment-3'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_local');
        $this->actingAs($manager)->postJson($url, ['ends_local' => '2026-09-25T14:00', 'change_reason' => ''] + $overrun + ['request_key' => 'appointment-4'])
            ->assertUnprocessable()->assertJsonValidationErrors('change_reason');
        $this->actingAs($manager)->postJson($url, ['ends_local' => '2026-09-25T14:00'] + $overrun + ['request_key' => 'appointment-5'])->assertOk();
        $this->assertSame('2026-09-25 02:00', $held->fresh()->ends_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-09-24 22:00', $held->fresh()->starts_at->utc()->format('Y-m-d H:i'));
        $extended = $held->fresh()->lock_version;
        $this->actingAs($manager)->postJson($url, ['ends_local' => '2026-09-25T14:00'] + $overrun + ['request_key' => 'appointment-5'])->assertOk();
        $this->assertSame($extended, $held->fresh()->lock_version);

        // Cancelling records the provider cancellation and releases the hold; the work stays open.
        $this->actingAs($manager)->postJson($url, ['operation' => 'cancel', 'work_order_id' => $order->id, 'request_key' => 'appointment-6'])
            ->assertUnprocessable()->assertJsonValidationErrors('change_reason');
        $cancel = ['operation' => 'cancel', 'work_order_id' => $order->id,
            'change_reason' => 'Provider closed for the day.', 'request_key' => 'appointment-7'];
        $this->actingAs($manager)->postJson($url, $cancel)->assertOk();
        // A retried cancel is the same cancel, not "no appointment to cancel".
        $this->actingAs($manager)->postJson($url, $cancel)->assertOk();
        $this->assertSame('cancelled', $held->fresh()->state);
        $this->assertSame('open', $order->fresh()->status);
        $this->assertSame('record_provider_cancellation', DB::table('fleet_maintenance_actions')->where('work_order_id', $order->id)
            ->orderByDesc('id')->whereIn('action_type', ['plan_provider', 'record_provider_cancellation'])->value('action_type'));
        $events = collect($this->feed($manager, $vehicle));
        $this->assertNull($events->firstWhere('id', 'appointment:'.$order->id));
        $this->assertNull($events->firstWhere('id', 'unavailable:'.$held->id));
        $this->actingAs($manager)->postJson($url, ['operation' => 'cancel', 'work_order_id' => $order->id,
            'change_reason' => 'Again.', 'request_key' => 'appointment-8'])->assertUnprocessable()->assertJsonValidationErrors('work_order_id');
    }

    public function test_snoozing_a_reminder_only_moves_it_later(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $reminder = FleetVehicleReminder::query()->create([
            'asset_id' => $vehicle->id, 'title' => 'Book the WoF', 'action_text' => 'Call the testing station.',
            'source_type' => 'vehicle', 'due_at' => Carbon::parse('2026-09-23 09:00', 'Pacific/Auckland')->utc(),
            'owner_user_id' => $manager->id, 'state' => 'scheduled', 'lock_version' => 1,
        ]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/reminders/{$reminder->id}/snooze";

        $this->actingAs($manager)->postJson($url, ['note' => 'Waiting on a quote.', 'remind_local' => '2026-09-23T08:00',
            'expected_version' => 1, 'request_key' => 'snooze-early'])->assertUnprocessable()->assertJsonValidationErrors('remind_local');
        $this->actingAs($manager)->postJson($url, ['note' => '', 'remind_local' => '2026-09-25T09:00',
            'expected_version' => 1, 'request_key' => 'snooze-blank'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->actingAs($manager)->postJson($url, ['note' => 'Waiting on a quote.', 'remind_local' => '2026-09-25T09:00',
            'expected_version' => 1, 'request_key' => 'snooze-1'])->assertOk()->assertJsonPath('reminder.state', 'scheduled');
        $this->assertSame('2026-09-25 09:00', $reminder->fresh()->due_at->setTimezone('Pacific/Auckland')->format('Y-m-d H:i'));
        $item = collect($this->feed($manager, $vehicle))->firstWhere('id', 'reminder:'.$reminder->id);
        $this->assertSame('Book the WoF · reminder', $item['title']);
    }

    public function test_evidence_can_be_kept_with_a_booking_or_unavailable_period_of_this_vehicle_only(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.viewAny', 'assets.documents.manage']);
        $vehicle = $this->vehicle($this->site);
        $otherVehicle = $this->vehicle($this->site);
        $period = FleetVehicleUnavailablePeriod::query()->create([
            'asset_id' => $vehicle->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'reason' => 'Garage',
            'state' => 'active', 'lock_version' => 1, 'created_by_user_id' => $manager->id,
        ]);
        $foreignPeriod = FleetVehicleUnavailablePeriod::query()->create([
            'asset_id' => $otherVehicle->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'reason' => 'Garage',
            'state' => 'active', 'lock_version' => 1, 'created_by_user_id' => $manager->id,
        ]);
        Storage::fake('private');
        // No scanning binary runs in tests; treat uploads as clean.
        $this->app->instance(MalwareScanner::class, new class extends MalwareScanner
        {
            public function scanPath(string $path, array $settings): MalwareScanResult
            {
                return new MalwareScanResult(MalwareScanDisposition::Clean, 'test-scanner', null);
            }
        });
        $file = UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $url = "/fleet-assets/vehicles/{$vehicle->id}/documents";
        $meta = ['category' => 'Unavailable period evidence', 'document_date' => '2026-09-22', 'reason' => 'Garage quote'];

        $this->actingAs($manager)->post($url, $meta + ['source_type' => 'unavailable_period', 'source_id' => $foreignPeriod->id,
            'files' => [$file], 'request_key' => 'evidence-foreign'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_id');
        $this->actingAs($manager)->post($url, $meta + ['source_type' => 'unavailable_period', 'source_id' => $period->id,
            'files' => [$file], 'request_key' => 'evidence-1'], ['Accept' => 'application/json'])->assertOk();
        $row = collect($this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/calendar/summary")->json('bookings'))
            ->first(fn (array $row): bool => $row['kind'] === 'unavailable' && $row['id'] === $period->id);
        $this->assertSame(['quote.pdf'], array_column($row['files'], 'name'));
        $this->assertTrue($row['can']['upload']);
    }

    public function test_the_summary_describes_the_restriction_its_linked_work_and_release_authority(): void
    {
        $coordinator = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $reviewer = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.release']);
        $vehicle = $this->vehicle($this->site);
        $this->readyCompliance($vehicle);
        $schedule = FleetServiceSchedule::query()->create(['asset_id' => $vehicle->id, 'name' => 'Routine service',
            'interval_months' => 6, 'next_due_at' => '2026-10-10', 'is_active' => true]);
        $run = $this->checkRun($vehicle, $coordinator, 'failed');
        $order = $this->workOrder($vehicle, $coordinator, 'Brake noise');
        $this->report($order, $coordinator, ['source_type' => 'service_schedule', 'source_id' => $schedule->id]);
        $restriction = $this->restriction($order, $coordinator, $run, '2026-09-21 10:00');
        DB::table('fleet_maintenance_reviewer_grants')->insert([
            'site_id' => $this->site->id, 'user_id' => $reviewer->id, 'asset_category' => $vehicle->category,
            'review_kind' => 'maintenance_release', 'version' => 1, 'decision' => 'grant',
            'recorded_by_user_id' => $coordinator->id, 'recorded_at' => now(), 'created_at' => now(),
        ]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/calendar/summary";

        $summary = $this->actingAs($coordinator)->getJson($url)->assertOk()->json();
        $this->assertSame($restriction, $summary['restriction']['id']);
        $this->assertSame('open', $summary['restriction']['work_status']);
        $this->assertSame($coordinator->name, $summary['restriction']['owner']);
        $this->assertSame(['id' => $run, 'label' => 'Pre-drive check · CHK-'.$run, 'outcome' => 'failed'],
            $summary['restriction']['source_check']);
        $this->assertSame('maintenance.active_restriction', $summary['use_problem_code']);
        $this->assertSame($restriction, $summary['use_problem_source_id']);
        $this->assertSame(['type' => 'service_schedule', 'id' => $schedule->id], $summary['open_work'][0]['source']);
        $this->assertFalse($summary['can']['review_release']);
        $this->actingAs($reviewer)->getJson($url)->assertOk()->assertJsonPath('can.review_release', true);

        // Once the hold is released, the failed check is the problem, and it
        // links to that check run.
        DB::table('fleet_maintenance_restrictions')->where('id', $restriction)
            ->update(['state' => 'released', 'released_at' => now(), 'released_by_user_id' => $coordinator->id]);
        $this->actingAs($coordinator)->getJson($url)->assertOk()->assertJsonPath('restriction', null)
            ->assertJsonPath('use_problem_code', 'maintenance.unresolved_check')
            ->assertJsonPath('use_problem_source_id', $run);
    }

    public function test_active_restrictions_span_the_window_and_estimates_carry_their_appointment(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $vehicle = $this->vehicle($this->site);
        $run = $this->checkRun($vehicle, $manager, 'failed');
        $order = $this->workOrder($vehicle, $manager, 'Brake noise');
        $active = $this->restriction($order, $manager, $run, '2026-09-18 10:00');
        $released = $this->restriction($order, $manager, null, '2026-09-10 10:00', '2026-09-21 15:00');
        $planned = $this->workOrder($vehicle, $manager, 'Routine service');
        $plannedEstimate = $this->report($planned, $manager, ['estimated_start_date' => '2026-09-24', 'estimated_end_date' => '2026-09-25']);
        $unplanned = $this->workOrder($vehicle, $manager, 'Tyre check');
        $unplannedEstimate = $this->report($unplanned, $manager, ['estimated_start_date' => '2026-09-28', 'estimated_end_date' => '2026-09-28']);
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/appointments", [
            'work_order_id' => $planned->id, 'provider_name' => 'Hutt Valley Motors', 'unavailable' => true,
            'starts_local' => '2026-09-24T09:00', 'ends_local' => '2026-09-24T11:00', 'notes' => 'Annual service.',
            'request_key' => 'appointment-estimate',
        ])->assertOk();
        $manual = FleetVehicleUnavailablePeriod::query()->create([
            'asset_id' => $vehicle->id, 'starts_at' => Carbon::parse('2026-09-30 08:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-09-30 17:00', 'Pacific/Auckland')->utc(), 'reason' => 'Signwriting',
            'state' => 'active', 'lock_version' => 1, 'created_by_user_id' => $manager->id,
        ]);
        $held = FleetVehicleUnavailablePeriod::query()->where('work_order_id', $planned->id)->sole();
        $events = collect($this->feed($manager, $vehicle));

        // An active restriction has no end yet: it covers every day browsed.
        $item = $events->firstWhere('id', 'restriction:'.$active);
        $this->assertSame('2026-09-18T00:00:00+12:00', $item['start']);
        $this->assertTrue(CarbonImmutable::parse($item['end'])->equalTo(CarbonImmutable::parse('2026-10-05T00:00:00Z')));
        $this->assertSame(['overdue', 'Active restriction'], [$item['status'], $item['statusLabel']]);
        $this->assertSame([
            'owner' => $manager->name,
            'source_check' => ['id' => $run, 'label' => 'Pre-drive check · CHK-'.$run, 'outcome' => 'failed'],
            'work_status' => 'open',
            'work_reference' => $order->fresh()->reference_number,
        ], $item['meta']);
        // A released one keeps its real end.
        $item = $events->firstWhere('id', 'restriction:'.$released);
        $this->assertSame('2026-09-22T00:00:00+12:00', $item['end']);
        $this->assertNull($item['meta']['source_check']);

        $this->assertSame(['appointment' => ['start' => '2026-09-24T09:00:00+12:00', 'end' => '2026-09-24T11:00:00+12:00',
            'provider' => 'Hutt Valley Motors', 'unavailable' => true]],
            $events->firstWhere('id', 'estimate:'.$plannedEstimate)['meta']);
        $this->assertNull($events->firstWhere('id', 'estimate:'.$unplannedEstimate)['meta']);
        $this->assertSame(['held_by_appointment' => true], $events->firstWhere('id', 'unavailable:'.$held->id)['meta']);
        $this->assertNull($events->firstWhere('id', 'unavailable:'.$manual->id)['meta']);

        // A cancelled appointment is no longer carried by its estimate.
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/appointments", [
            'operation' => 'cancel', 'work_order_id' => $planned->id, 'change_reason' => 'Provider closed.',
            'request_key' => 'appointment-estimate-cancel',
        ])->assertOk();
        $this->assertNull(collect($this->feed($manager, $vehicle))->firstWhere('id', 'estimate:'.$plannedEstimate)['meta']);
    }

    public function test_a_booking_or_period_outside_the_summary_lists_opens_as_its_record(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $outsider = $this->siteUser([$this->foreignSite], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $other = $this->vehicle($this->site);
        // Eleven returned bookings: the summary keeps the ten most recent.
        $returned = collect(range(1, 11))->map(fn (int $day): FleetVehicleBooking => $this->booking($vehicle, $manager,
            sprintf('2026-08-%02d 09:00', $day), sprintf('2026-08-%02d 10:00', $day), ['status' => 'returned']));
        $oldest = $returned->first();
        $live = $this->booking($vehicle, $manager, '2026-09-24 10:00', '2026-09-24 12:00');
        $hidden = $this->booking($vehicle, $manager, '2026-09-25 10:00', '2026-09-25 12:00', ['pickup_site_id' => $this->foreignSite->id]);
        $foreignBooking = $this->booking($other, $manager, '2026-09-26 10:00', '2026-09-26 12:00');
        $oldPeriod = FleetVehicleUnavailablePeriod::query()->create([
            'asset_id' => $vehicle->id, 'starts_at' => now()->subDays(60), 'ends_at' => now()->subDays(59), 'reason' => 'Garage',
            'state' => 'cancelled', 'cancelled_at' => now()->subDays(61), 'cancellation_reason' => 'Not needed',
            'lock_version' => 2, 'created_by_user_id' => $manager->id,
        ]);
        $otherPeriod = FleetVehicleUnavailablePeriod::query()->create([
            'asset_id' => $other->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'reason' => 'Garage',
            'state' => 'active', 'lock_version' => 1, 'created_by_user_id' => $manager->id,
        ]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/calendar";

        $summary = collect($this->actingAs($manager)->getJson("{$url}/summary")->assertOk()->json('bookings'));
        $listed = fn (string $kind, int $id): ?array => $summary->first(fn (array $row): bool => $row['kind'] === $kind && $row['id'] === $id);
        $this->assertNull($listed('booking', $oldest->id));
        $this->assertNull($listed('unavailable', $oldPeriod->id));

        $row = $this->actingAs($manager)->getJson("{$url}/records/booking/{$oldest->id}")->assertOk()->json('row');
        $this->assertSame(['booking', 'returned', 'Returned'], [$row['kind'], $row['status'], $row['status_label']]);
        $this->assertFalse($row['can']['cancel']);
        // One row builder: a listed booking reads exactly as the summary lists it.
        $this->assertSame($listed('booking', $live->id), $this->actingAs($manager)->getJson("{$url}/records/booking/{$live->id}")
            ->assertOk()->json('row'));
        $period = $this->actingAs($manager)->getJson("{$url}/records/unavailable/{$oldPeriod->id}")->assertOk()->json('row');
        $this->assertSame(['unavailable', 'cancelled', 'Not needed'], [$period['kind'], $period['status'], $period['cancellation_reason']]);
        // It ended long ago, so there's nothing to restore.
        $this->assertSame([false, false, false], [$period['can']['edit'], $period['can']['cancel'], $period['can']['restore']]);

        // Bookings keep their own Site rule; foreign, missing and unknown records answer 404.
        $this->actingAs($manager)->getJson("{$url}/records/booking/{$hidden->id}")->assertNotFound();
        $this->actingAs($manager)->getJson("{$url}/records/booking/{$foreignBooking->id}")->assertNotFound();
        $this->actingAs($manager)->getJson("{$url}/records/unavailable/{$otherPeriod->id}")->assertNotFound();
        $this->actingAs($manager)->getJson("{$url}/records/booking/999999")->assertNotFound();
        $this->actingAs($manager)->getJson("{$url}/records/busy/{$live->id}")->assertNotFound();
        $this->actingAs($outsider)->getJson("{$url}/records/booking/{$live->id}")->assertNotFound();
        $this->assertStringNotContainsString('Private', json_encode($row));
    }

    public function test_a_linked_appointment_reuses_open_work_from_the_same_due_item(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $backup = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $reader = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $other = $this->vehicle($this->site);
        $this->approveRouting($manager, $backup);
        $schedule = FleetServiceSchedule::query()->create(['asset_id' => $vehicle->id, 'name' => 'Routine service',
            'interval_months' => 6, 'next_due_at' => '2026-09-25', 'is_active' => true]);
        $foreignSchedule = FleetServiceSchedule::query()->create(['asset_id' => $other->id, 'name' => 'Routine service',
            'interval_months' => 6, 'next_due_at' => '2026-09-25', 'is_active' => true]);
        $wof = $this->complianceRecord($vehicle, 'wof');
        $url = "/fleet-assets/vehicles/{$vehicle->id}/appointments";
        $plan = ['title' => 'Routine service', 'provider_name' => 'Hutt Valley Motors', 'unavailable' => true,
            'starts_local' => '2026-09-24T09:00', 'ends_local' => '2026-09-24T11:00', 'notes' => 'Six-monthly service.',
            'source_type' => 'service_schedule', 'source_id' => $schedule->id];

        // Only a maintenance manager plans; another vehicle's due item or an
        // unsupported kind is refused before anything is written.
        $this->actingAs($reader)->postJson($url, $plan + ['request_key' => 'linked-reader'])->assertForbidden();
        $this->actingAs($manager)->postJson($url, ['source_id' => $foreignSchedule->id, 'request_key' => 'linked-foreign'] + $plan)
            ->assertUnprocessable()->assertJsonValidationErrors('source_id');
        $this->actingAs($manager)->postJson($url, ['source_type' => 'fleet_vehicle_booking', 'request_key' => 'linked-kind'] + $plan)
            ->assertUnprocessable()->assertJsonValidationErrors('source_type');
        $this->assertSame(0, FleetWorkOrder::query()->count());

        $first = $this->actingAs($manager)->postJson($url, $plan + ['request_key' => 'linked-1'])->assertOk()->json();
        $work = FleetWorkOrder::query()->sole();
        $this->assertSame([$work->id, $work->id], [$first['work_order_id'], $first['work_order']['id']]);
        $report = DB::table('fleet_maintenance_reports')->where('work_order_id', $work->id)->sole();
        $this->assertSame(['service_schedule', $schedule->id], [$report->source_type, (int) $report->source_id]);
        // A retry is the same plan: no second record, report or hold.
        $this->actingAs($manager)->postJson($url, $plan + ['request_key' => 'linked-1'])->assertOk()
            ->assertJsonPath('work_order_id', $work->id);
        // The same key asking for a different plan is refused, not silently accepted.
        $this->actingAs($manager)->postJson($url, ['provider_name' => 'Another garage', 'request_key' => 'linked-1'] + $plan)
            ->assertStatus(409);
        // Planning the same due item again plans on its open work and moves the hold.
        $this->actingAs($manager)->postJson($url, ['starts_local' => '2026-09-25T09:00', 'ends_local' => '2026-09-25T11:00',
            'request_key' => 'linked-2'] + $plan)->assertOk()->assertJsonPath('work_order_id', $work->id);
        $this->assertSame(1, FleetWorkOrder::query()->count());
        $this->assertSame(1, DB::table('fleet_maintenance_reports')->count());
        $hold = FleetVehicleUnavailablePeriod::query()->where('work_order_id', $work->id)->sole();
        $this->assertSame('2026-09-24 21:00', $hold->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame(2, DB::table('fleet_maintenance_actions')->where('work_order_id', $work->id)
            ->where('action_type', 'plan_provider')->count());
        $this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/calendar/summary")->assertOk()
            ->assertJsonPath('open_work.0.id', $work->id)
            ->assertJsonPath('open_work.0.source', ['type' => 'service_schedule', 'id' => $schedule->id]);

        // A chosen record must be the due item's linked work.
        $manual = $this->workOrder($vehicle, $manager, 'Brake noise');
        $this->actingAs($manager)->postJson($url, ['work_order_id' => $manual->id, 'request_key' => 'linked-3'] + $plan)
            ->assertUnprocessable()->assertJsonValidationErrors('work_order_id');
        $this->actingAs($manager)->postJson($url, ['work_order_id' => $work->id, 'starts_local' => '2026-09-26T09:00',
            'ends_local' => '2026-09-26T11:00', 'request_key' => 'linked-4'] + $plan)->assertOk()
            ->assertJsonPath('work_order_id', $work->id);

        // Another due item gets its own work.
        $inspection = $this->actingAs($manager)->postJson($url, ['title' => 'WoF inspection', 'source_type' => 'compliance_record',
            'source_id' => $wof, 'unavailable' => false, 'starts_local' => '2026-09-29T09:00', 'ends_local' => '2026-09-29T10:00',
            'request_key' => 'linked-5'] + $plan)->assertOk()->json('work_order_id');
        $this->assertNotSame($work->id, $inspection);
        $this->assertSame(['compliance_record', $wof], [
            DB::table('fleet_maintenance_reports')->where('work_order_id', $inspection)->value('source_type'),
            (int) DB::table('fleet_maintenance_reports')->where('work_order_id', $inspection)->value('source_id'),
        ]);
        // A due item is linked only when planning.
        $this->actingAs($manager)->postJson($url, ['operation' => 'cancel', 'work_order_id' => $work->id,
            'change_reason' => 'Provider closed.', 'source_type' => 'service_schedule', 'source_id' => $schedule->id,
            'request_key' => 'linked-6'])->assertUnprocessable()->assertJsonValidationErrors('source_type');
    }

    public function test_a_period_an_appointment_holds_is_managed_through_the_appointment(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $order = $this->workOrder($vehicle, $manager, 'Routine service');
        $url = "/fleet-assets/vehicles/{$vehicle->id}";
        $plan = ['work_order_id' => $order->id, 'provider_name' => 'Hutt Valley Motors', 'unavailable' => true,
            'starts_local' => '2026-09-24T09:00', 'ends_local' => '2026-09-24T11:00', 'notes' => 'Annual service.'];
        $this->actingAs($manager)->postJson("{$url}/appointments", $plan + ['request_key' => 'held-plan'])->assertOk();
        $held = FleetVehicleUnavailablePeriod::query()->where('work_order_id', $order->id)->sole();
        $message = 'This period is held by a service appointment. Manage the appointment instead.';

        $this->actingAs($manager)->putJson("{$url}/unavailable-periods/{$held->id}", ['starts_local' => '2026-09-24T10:00',
            'ends_local' => '2026-09-24T12:00', 'reason' => $held->reason, 'change_reason' => 'Moved on the calendar.',
            'expected_version' => $held->lock_version])->assertStatus(409)->assertJsonPath('message', $message);
        $this->actingAs($manager)->postJson("{$url}/unavailable-periods/{$held->id}/cancel", ['reason' => 'Not needed.',
            'expected_version' => $held->lock_version])->assertStatus(409)->assertJsonPath('message', $message);
        $this->assertSame(['active', 1], [$held->fresh()->state, $held->fresh()->lock_version]);
        $row = collect($this->actingAs($manager)->getJson("{$url}/calendar/summary")->assertOk()->json('bookings'))
            ->first(fn (array $row): bool => $row['kind'] === 'unavailable' && $row['id'] === $held->id);
        $this->assertSame([$order->id, false, false, false],
            [$row['work_order_id'], $row['can']['edit'], $row['can']['cancel'], $row['can']['restore']]);

        // The appointment still moves and releases its own hold.
        $this->actingAs($manager)->postJson("{$url}/appointments", ['starts_local' => '2026-09-25T10:00', 'ends_local' => '2026-09-25T12:00',
            'change_reason' => 'Garage moved us.', 'request_key' => 'held-move'] + $plan)->assertOk();
        $this->assertSame('2026-09-24 22:00', $held->fresh()->starts_at->utc()->format('Y-m-d H:i'));
        $this->actingAs($manager)->postJson("{$url}/appointments", ['operation' => 'cancel', 'work_order_id' => $order->id,
            'change_reason' => 'Provider closed.', 'request_key' => 'held-cancel'])->assertOk();
        $this->assertSame('cancelled', $held->fresh()->state);
        // A released hold isn't restored from the calendar either.
        $this->actingAs($manager)->postJson("{$url}/unavailable-periods/{$held->id}/restore", [
            'expected_version' => $held->fresh()->lock_version, 'request_key' => 'held-restore',
        ])->assertStatus(409)->assertJsonPath('message', $message);
        $this->assertSame('cancelled', $held->fresh()->state);
    }

    public function test_a_cancelled_period_can_be_restored_while_its_time_is_still_free(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $reader = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $other = $this->vehicle($this->site);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/unavailable-periods";
        $period = $this->actingAs($manager)->postJson($url, ['starts_local' => '2026-09-24T08:00', 'ends_local' => '2026-09-24T17:00',
            'reason' => 'Panel repair at the garage', 'request_key' => 'restore-block'])->assertOk()->json('period');
        $this->actingAs($manager)->postJson("{$url}/{$period['id']}/cancel", ['reason' => 'Repair postponed.', 'expected_version' => 1])
            ->assertOk()->assertJsonPath('period.lock_version', 2);
        $restore = "{$url}/{$period['id']}/restore";

        $this->actingAs($reader)->postJson($restore, ['expected_version' => 2, 'request_key' => 'restore-reader'])->assertForbidden();
        $this->actingAs($manager)->postJson($restore, ['expected_version' => 1, 'request_key' => 'restore-stale'])->assertStatus(409);
        $this->actingAs($manager)->postJson($restore, ['expected_version' => 2, 'request_key' => 'restore-1'])->assertOk()
            ->assertJsonPath('period.id', $period['id'])->assertJsonPath('period.state', 'active')
            ->assertJsonPath('period.lock_version', 3)->assertJsonPath('message', 'Unavailable period restored.');
        // A retry is the same restore; the key can't be reused for another change.
        $this->actingAs($manager)->postJson($restore, ['expected_version' => 2, 'request_key' => 'restore-1'])->assertOk()
            ->assertJsonPath('period.lock_version', 3);
        $this->actingAs($manager)->postJson($restore, ['expected_version' => 3, 'request_key' => 'restore-1'])->assertStatus(409);
        $this->actingAs($manager)->postJson($restore, ['expected_version' => 3, 'request_key' => 'restore-active'])->assertStatus(409);
        $restored = FleetVehicleUnavailablePeriod::query()->findOrFail($period['id']);
        $this->assertSame(['active', 3, null], [$restored->state, $restored->lock_version, $restored->cancellation_reason]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'fleet.vehicle.unavailable.restored')->count());
        $row = collect($this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/calendar/summary")->json('bookings'))
            ->first(fn (array $row): bool => $row['kind'] === 'unavailable' && $row['id'] === $period['id']);
        $this->assertSame(['Recorded', 'Cancelled', 'Restored'], array_column($row['history'], 'label'));
        // Bookings are refused again once it's back.
        $this->actingAs($manager)->postJson('/fleet-assets/bookings', [
            'asset_id' => $vehicle->id, 'purpose' => 'Shopping trip', 'starts_local' => '2026-09-24T09:00',
            'ends_local' => '2026-09-24T10:00', 'request_key' => 'booking-into-restored',
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_local');

        // Once something else uses the time, the cancellation stands.
        $this->actingAs($manager)->postJson("{$url}/{$period['id']}/cancel", ['reason' => 'Repair postponed again.', 'expected_version' => 3])
            ->assertOk();
        $booking = $this->booking($vehicle, $manager, '2026-09-24 12:00', '2026-09-24 13:00', ['status' => 'approved']);
        $this->actingAs($manager)->postJson($restore, ['expected_version' => 4, 'request_key' => 'restore-2'])->assertStatus(409)
            ->assertJsonPath('message', 'This period can\'t be restored. This time overlaps booking '
                .($booking->fresh()->reference_number ?: '#'.$booking->id).'. Change or cancel that booking first.');
        $this->assertSame('cancelled', $restored->fresh()->state);

        // A period that has already ended stays cancelled; foreign or missing ones answer 404.
        $ended = FleetVehicleUnavailablePeriod::query()->create([
            'asset_id' => $vehicle->id, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(2), 'reason' => 'Garage',
            'state' => 'cancelled', 'cancelled_at' => now()->subDays(4), 'cancellation_reason' => 'Not needed',
            'lock_version' => 2, 'created_by_user_id' => $manager->id,
        ]);
        $this->actingAs($manager)->postJson("{$url}/{$ended->id}/restore", ['expected_version' => 2, 'request_key' => 'restore-ended'])
            ->assertStatus(409);
        $foreign = FleetVehicleUnavailablePeriod::query()->create([
            'asset_id' => $other->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(4), 'reason' => 'Garage',
            'state' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'Not needed',
            'lock_version' => 2, 'created_by_user_id' => $manager->id,
        ]);
        $this->actingAs($manager)->postJson("{$url}/{$foreign->id}/restore", ['expected_version' => 2, 'request_key' => 'restore-foreign'])
            ->assertNotFound();
        $this->actingAs($manager)->postJson("{$url}/999999/restore", ['expected_version' => 2, 'request_key' => 'restore-missing'])
            ->assertNotFound();
        $this->assertSame('cancelled', $foreign->fresh()->state);
    }

    public function test_reversible_changes_can_be_put_back_through_their_own_endpoints(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $reminder = FleetVehicleReminder::query()->create([
            'asset_id' => $vehicle->id, 'title' => 'Book the WoF', 'action_text' => 'Call the testing station.',
            'source_type' => 'vehicle', 'due_at' => Carbon::parse('2026-09-23 09:00', 'Pacific/Auckland')->utc(),
            'owner_user_id' => $manager->id, 'state' => 'scheduled', 'lock_version' => 1,
        ]);
        $reminderUrl = "/fleet-assets/vehicles/{$vehicle->id}/reminders/{$reminder->id}";
        $this->actingAs($manager)->postJson("{$reminderUrl}/snooze", ['note' => 'Waiting on a quote.', 'remind_local' => '2026-09-25T09:00',
            'expected_version' => 1, 'request_key' => 'undo-snooze-1'])->assertOk();
        $edit = ['title' => 'Book the WoF', 'action_text' => 'Call the testing station.', 'owner_user_id' => $manager->id,
            'reason' => 'Undo snooze.', 'expected_version' => 2];

        // Undoing a snooze moves the reminder back to its earlier time while that is still ahead…
        $this->actingAs($manager)->putJson($reminderUrl, ['remind_local' => '2026-09-23T09:00', 'request_key' => 'undo-snooze-2'] + $edit)
            ->assertOk();
        $this->assertSame('2026-09-23 09:00', $reminder->fresh()->due_at->setTimezone('Pacific/Auckland')->format('Y-m-d H:i'));
        $this->assertSame(3, $reminder->fresh()->lock_version);
        // …but never to a time that has passed.
        $this->actingAs($manager)->putJson($reminderUrl, ['remind_local' => '2026-09-22T08:00', 'expected_version' => 3,
            'request_key' => 'undo-snooze-3'] + $edit)->assertUnprocessable()->assertJsonValidationErrors('remind_local');

        // A changed period goes back to its earlier times with the new version.
        $periods = "/fleet-assets/vehicles/{$vehicle->id}/unavailable-periods";
        $window = ['starts_local' => '2026-09-24T08:00', 'ends_local' => '2026-09-24T17:00', 'reason' => 'Panel repair'];
        $period = $this->actingAs($manager)->postJson($periods, $window + ['request_key' => 'undo-period'])->assertOk()->json('period');
        $this->actingAs($manager)->putJson("{$periods}/{$period['id']}", ['starts_local' => '2026-09-24T09:00', 'ends_local' => '2026-09-24T16:00',
            'change_reason' => 'Garage confirmed times.', 'expected_version' => 1] + $window)->assertOk();
        $this->actingAs($manager)->putJson("{$periods}/{$period['id']}", ['change_reason' => 'Undo change.', 'expected_version' => 2] + $window)
            ->assertOk()->assertJsonPath('period.lock_version', 3)
            ->assertJsonPath('period.starts_at', Carbon::parse('2026-09-24 08:00', 'Pacific/Auckland')->utc()->toIso8601String());
    }

    public function test_a_return_with_a_concern_opens_linked_maintenance_work_once(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $backup = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $vehicle = $this->vehicle($this->site);
        $this->approveRouting($manager, $backup);
        $booking = $this->booking($vehicle, $manager, '2026-09-22 08:00', '2026-09-22 12:00', ['status' => 'checked_out', 'odometer_out' => 1000]);
        $return = ['odometer_in' => 1040, 'condition_on_return' => 'Concern recorded', 'return_evidence_reference' => 'Photos in the van folder',
            'keys_received' => false, 'return_notes' => 'Scrape on the rear bumper.'];

        $response = $this->actingAs($manager)->postJson("/fleet-assets/bookings/{$booking->id}/return", $return)->assertOk()
            ->assertJsonPath('booking.status', 'returned')->assertJsonPath('maintenance_report.status', 'created');
        $work = FleetWorkOrder::query()->sole();
        $response->assertJsonPath('maintenance_report.work_order_id', $work->id)
            ->assertJsonPath('maintenance_report.reference', $work->reference_number);
        $this->assertStringContainsString($work->reference_number ?? '', $response->json('message'));
        $this->assertSame(['Return condition concern', 'open', $manager->id], [$work->title, $work->status, (int) $work->assigned_to_user_id]);
        $report = DB::table('fleet_maintenance_reports')->sole();
        $this->assertSame(['fleet_vehicle_booking', $booking->id], [$report->source_type, (int) $report->source_id]);
        $this->assertStringContainsString('Scrape on the rear bumper.', (string) $report->description);
        $this->assertStringContainsString('Photos in the van folder', (string) $report->description);

        // A retried return reports the same work; nothing is created twice.
        $this->actingAs($manager)->postJson("/fleet-assets/bookings/{$booking->id}/return", $return)->assertOk()
            ->assertJsonPath('maintenance_report.status', 'created')->assertJsonPath('maintenance_report.work_order_id', $work->id);
        $this->assertSame(1, FleetWorkOrder::query()->count());
        $this->assertSame(1, DB::table('fleet_maintenance_reports')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'fleet.booking.return_concern.reported')->count());
        $row = collect($this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/calendar/summary")->json('bookings'))
            ->first(fn (array $row): bool => $row['kind'] === 'booking' && $row['id'] === $booking->id);
        $this->assertContains('Concern sent to Maintenance', array_column($row['history'], 'label'));

        // No concern, no Maintenance record.
        $clean = $this->booking($vehicle, $manager, '2026-09-22 13:00', '2026-09-22 14:00', ['status' => 'checked_out', 'odometer_out' => 1040]);
        $this->actingAs($manager)->postJson("/fleet-assets/bookings/{$clean->id}/return", ['odometer_in' => 1050,
            'condition_on_return' => 'No new concern'] + $return)->assertOk()
            ->assertJsonPath('maintenance_report', null)->assertJsonPath('message', 'Vehicle returned.');
        $this->assertSame(1, FleetWorkOrder::query()->count());
    }

    public function test_a_return_concern_without_maintenance_routing_still_returns_the_vehicle(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $backup = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $vehicle = $this->vehicle($this->site);
        $booking = $this->booking($vehicle, $manager, '2026-09-22 08:00', '2026-09-22 12:00', ['status' => 'checked_out', 'odometer_out' => 1000]);
        $return = ['odometer_in' => 1040, 'condition_on_return' => 'Concern recorded', 'return_evidence_reference' => 'Photos',
            'keys_received' => false, 'return_notes' => 'Warning light on.'];

        $response = $this->actingAs($manager)->postJson("/fleet-assets/bookings/{$booking->id}/return", $return)->assertOk()
            ->assertJsonPath('booking.status', 'returned')->assertJsonPath('maintenance_report.status', 'not_routed');
        $this->assertStringContainsString('approved Maintenance Coordinator and backup', $response->json('maintenance_report.message'));
        $this->assertSame($response->json('maintenance_report.message'), $response->json('message'));
        $this->assertSame(['returned', 'Concern recorded'], [$booking->fresh()->status, $booking->fresh()->condition_on_return]);
        $this->assertSame(0, FleetWorkOrder::query()->count());
        // A retry says so again without repeating the history entry.
        $this->actingAs($manager)->postJson("/fleet-assets/bookings/{$booking->id}/return", $return)->assertOk()
            ->assertJsonPath('maintenance_report.status', 'not_routed');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'fleet.booking.return_concern.not_routed')->count());

        // Once routing is approved, the retried return sends the concern (once).
        $this->approveRouting($manager, $backup);
        $work = $this->actingAs($manager)->postJson("/fleet-assets/bookings/{$booking->id}/return", $return)->assertOk()
            ->assertJsonPath('maintenance_report.status', 'created')->json('maintenance_report.work_order_id');
        $this->actingAs($manager)->postJson("/fleet-assets/bookings/{$booking->id}/return", $return)->assertOk()
            ->assertJsonPath('maintenance_report.work_order_id', $work);
        $this->assertSame(1, FleetWorkOrder::query()->count());
        $row = collect($this->actingAs($manager)->getJson("/fleet-assets/vehicles/{$vehicle->id}/calendar/summary")->json('bookings'))
            ->first(fn (array $row): bool => $row['kind'] === 'booking' && $row['id'] === $booking->id);
        $this->assertSame(['Concern not sent to Maintenance', 'Concern sent to Maintenance'], array_values(array_filter(
            array_column($row['history'], 'label'), fn (string $label): bool => str_starts_with($label, 'Concern'))));
    }

    public function test_periods_are_busy_time_outside_the_viewers_sites_and_appointment_details_follow_maintenance_access(): void
    {
        $maintenance = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        // Central fleet oversight opens the vehicle, but not its bookings or periods.
        $central = $this->siteUser([$this->foreignSite], ['fleet.viewAny', 'fleet.manage', 'fleet.vehicles.viewAllSites']);
        // All-Sites device access admits the booking rule, but not this Site's Maintenance.
        $devices = $this->siteUser([$this->foreignSite], ['fleet.viewAny', 'securityDevices.devices.viewAllSites']);
        $vehicle = $this->vehicle($this->site);
        $order = $this->workOrder($vehicle, $maintenance, 'Routine service');
        $this->actingAs($maintenance)->postJson("/fleet-assets/vehicles/{$vehicle->id}/appointments", [
            'work_order_id' => $order->id, 'provider_name' => 'Hutt Valley Motors', 'unavailable' => true,
            'starts_local' => '2026-09-24T09:00', 'ends_local' => '2026-09-24T11:00', 'notes' => 'Annual service.',
            'request_key' => 'privacy-appointment',
        ])->assertOk();
        $held = FleetVehicleUnavailablePeriod::query()->where('work_order_id', $order->id)->sole();
        $manual = $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/unavailable-periods", [
            'starts_local' => '2026-09-26T08:00', 'ends_local' => '2026-09-26T17:00', 'reason' => 'Panel repair at the garage',
            'request_key' => 'privacy-manual',
        ])->assertOk()->json('period.id');
        $calendar = "/fleet-assets/vehicles/{$vehicle->id}/calendar";
        $maintenanceDetails = array_values(array_filter(['Hutt Valley Motors', 'Service appointment', (string) $order->fresh()->reference_number]));

        // Outside the viewer's Sites: busy time only, and no period records.
        $events = collect($this->feed($central, $vehicle));
        $this->assertSame([], $events->where('kind', 'unavailable')->values()->all());
        $this->assertSame(['busy:unavailable-'.$held->id, 'busy:unavailable-'.$manual],
            $events->where('kind', 'busy')->pluck('id')->values()->all());
        $this->assertSame([null, null, 'Busy'], [$events->first()['recordId'], $events->first()['workOrderId'], $events->first()['title']]);
        $summary = $this->actingAs($central)->getJson("{$calendar}/summary")->assertOk()->assertJsonPath('bookings', [])->json();
        foreach ([...$maintenanceDetails, 'Panel repair'] as $detail) {
            $this->assertStringNotContainsString($detail, json_encode($events->all()));
            $this->assertStringNotContainsString($detail, json_encode($summary));
        }
        $this->actingAs($central)->getJson("{$calendar}/records/unavailable/{$held->id}")->assertNotFound();
        $this->actingAs($central)->getJson("{$calendar}/records/unavailable/{$manual}")->assertNotFound();

        // At the vehicle's Site without Maintenance access: the period, not its work or provider.
        $events = collect($this->feed($devices, $vehicle));
        $item = $events->firstWhere('id', 'unavailable:'.$held->id);
        $this->assertSame(['Unavailable · Maintenance', null, ['held_by_appointment' => true]],
            [$item['title'], $item['workOrderId'], $item['meta']]);
        $this->assertSame('Unavailable · Panel repair at the garage', $events->firstWhere('id', 'unavailable:'.$manual)['title']);
        $summary = $this->actingAs($devices)->getJson("{$calendar}/summary")->assertOk()
            ->assertJsonPath('can.view_maintenance', false)->json();
        $row = collect($summary['bookings'])->first(fn (array $row): bool => $row['kind'] === 'unavailable' && $row['id'] === $held->id);
        $this->assertSame(['Maintenance', null, null], [$row['purpose'], $row['reference'], $row['work_order_id']]);
        $this->assertSame([null], array_values(array_unique(array_column($row['history'], 'reason'))));
        $this->assertSame($row, $this->actingAs($devices)->getJson("{$calendar}/records/unavailable/{$held->id}")->assertOk()->json('row'));
        foreach ($maintenanceDetails as $detail) {
            $this->assertStringNotContainsString($detail, json_encode($events->all()));
            $this->assertStringNotContainsString($detail, json_encode($summary));
        }

        // Those who read the vehicle's Maintenance see the appointment's period in full.
        $this->assertSame('Unavailable · '.$held->reason,
            collect($this->feed($manager, $vehicle))->firstWhere('id', 'unavailable:'.$held->id)['title']);
        $this->assertStringContainsString('Hutt Valley Motors', $held->reason);
    }

    public function test_a_stale_booking_change_is_refused_instead_of_being_lost(): void
    {
        $requester = $this->siteUser([$this->site], ['fleet.viewAny']);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle($this->site);
        $booking = $this->booking($vehicle, $requester, '2026-09-24 09:00', '2026-09-24 10:00', ['purpose' => 'Library outing']);
        $url = "/fleet-assets/bookings/{$booking->id}";
        $base = ['purpose' => 'Library outing', 'starts_local' => '2026-09-24T09:00', 'ends_local' => '2026-09-24T10:00',
            'reason' => 'Details for the driver.', 'expected_version' => 1];

        // A saves new notes (version 1 → 2); a retry of that request is the same save.
        $this->actingAs($requester)->putJson($url, ['notes' => 'Bring the wheelchair ramp.', 'request_key' => 'booking-change-a'] + $base)
            ->assertOk()->assertJsonPath('booking.lock_version', 2);
        $this->actingAs($requester)->putJson($url, ['notes' => 'Bring the wheelchair ramp.', 'request_key' => 'booking-change-a'] + $base)
            ->assertOk()->assertJsonPath('booking.lock_version', 2);
        // B, still on version 1, changes other details at the same times: refused, not silently dropped.
        $this->actingAs($manager)->putJson($url, ['destination' => 'Te Papa', 'passengers' => 4, 'request_key' => 'booking-change-b'] + $base)
            ->assertStatus(409);
        $this->actingAs($manager)->putJson($url, ['destination' => 'Te Papa', 'passengers' => 4] + $base)->assertStatus(409);
        // A's key can't carry a different change.
        $this->actingAs($requester)->putJson($url, ['notes' => 'Something else.', 'request_key' => 'booking-change-a'] + $base)
            ->assertStatus(409)->assertJsonPath('message', 'This request was already used for a different change. Reload and try again.');
        $fresh = $booking->fresh();
        $this->assertSame([2, 'Bring the wheelchair ramp.', null, null],
            [$fresh->lock_version, $fresh->notes, $fresh->destination, $fresh->passengers]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'fleet.booking.update')->count());
    }

    /** @return list<array<string,mixed>> */
    private function feed(User $viewer, Asset $vehicle): array
    {
        return $this->actingAs($viewer)
            ->getJson("/fleet-assets/vehicles/{$vehicle->id}/calendar/events?start=2026-09-20T00:00:00Z&end=2026-10-05T00:00:00Z")
            ->assertOk()->json('events');
    }

    /** @param array<string,mixed> $extra */
    private function booking(Asset $vehicle, User $user, string $starts, string $ends, array $extra = []): FleetVehicleBooking
    {
        return FleetVehicleBooking::query()->create($extra + [
            'asset_id' => $vehicle->id, 'user_id' => $user->id, 'purpose' => 'Community outing', 'status' => 'pending',
            'starts_at' => Carbon::parse($starts, 'Pacific/Auckland')->utc(), 'ends_at' => Carbon::parse($ends, 'Pacific/Auckland')->utc(),
            'approval_route' => 'required', 'lock_version' => 1,
        ]);
    }

    /**
     * @param  list<Site>  $sites
     * @param  list<string>  $permissions
     */
    private function siteUser(array $sites, array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $sites[0]->id,
            'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->values()->all(),
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $user->permissionOverrides()->syncWithoutDetaching(collect($permissions)->mapWithKeys(fn (string $key): array => [
            Permission::query()->firstOrCreate(['key' => $key], [
                'description' => $key, 'group' => str($key)->before('.')->value(), 'module' => str($key)->before('.')->value(),
            ])->id => ['allowed' => true],
        ])->all());
        $user->unsetRelation('permissionOverrides');

        return $user;
    }

    private function vehicle(Site $site): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id, 'home_site_id' => $site->id, 'name' => 'Kōwhai van', 'status' => 'active',
        ]);
    }

    /** An approved Maintenance Coordinator and backup for the site (PKG-01 report routing). */
    private function approveRouting(User $coordinator, User $backup): void
    {
        DB::table('fleet_maintenance_site_routes')->insert([
            'site_id' => $this->site->id, 'coordinator_user_id' => $coordinator->id, 'backup_user_id' => $backup->id,
            'approved_by_user_id' => $coordinator->id, 'approved_at' => now(), 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A current compliance record assessed as not applicable (with its basis). */
    private function complianceRecord(Asset $vehicle, string $kind): int
    {
        $recordId = DB::table('fleet_vehicle_compliance_records')->insertGetId([
            'asset_id' => $vehicle->id, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $versionId = DB::table('fleet_vehicle_compliance_versions')->insertGetId([
            'record_id' => $recordId, 'version' => 1, 'applicability' => 'not_applicable',
            'applicability_basis' => 'Not required for this vehicle.', 'outcome' => 'recorded',
            'request_key' => "compliance-{$kind}-{$vehicle->id}", 'request_fingerprint' => str_repeat('c', 64),
            'content_sha256' => str_repeat('c', 64), 'created_at' => now(),
        ]);
        DB::table('fleet_vehicle_compliance_records')->where('id', $recordId)->update(['current_version_id' => $versionId]);

        return $recordId;
    }

    /** Every compliance domain assessed, so only Maintenance can stop the vehicle being used. */
    private function readyCompliance(Asset $vehicle): void
    {
        foreach (['registration', 'wof', 'cof', 'ruc'] as $kind) {
            $this->complianceRecord($vehicle, $kind);
        }
    }

    /** A submitted check with no approved rule applied. */
    private function checkRun(Asset $vehicle, User $user, string $outcome): int
    {
        $template = FleetChecklistTemplate::query()->create([
            'name' => 'Pre-drive check', 'type' => 'custom', 'is_active' => true,
            'items' => [['id' => 'tyres', 'label' => 'Tyres', 'type' => 'select', 'options' => ['pass', 'fail'], 'required' => true]],
        ]);

        return DB::table('fleet_checklist_runs')->insertGetId([
            'template_id' => $template->id, 'asset_id' => $vehicle->id, 'user_id' => $user->id,
            'responses' => json_encode(['tyres' => $outcome === 'passed' ? 'pass' : 'fail']),
            'passed' => $outcome === 'passed' ? 1 : 0, 'completed_at' => now(), 'outcome' => $outcome,
            'check_kind' => 'check', 'observed_at' => now(), 'submitted_at' => now(),
            'request_key' => 'calendar-run-'.Str::uuid(), 'request_fingerprint' => str_repeat('r', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function workOrder(Asset $vehicle, User $by, string $title): FleetWorkOrder
    {
        return FleetWorkOrder::query()->create([
            'asset_id' => $vehicle->id, 'reported_by_user_id' => $by->id, 'assigned_to_user_id' => $by->id,
            'title' => $title, 'category' => 'vehicle', 'priority' => 'medium', 'status' => 'open',
        ]);
    }

    /**
     * A Maintenance report on the work, as the PKG-01 report path stores it.
     *
     * @param  array<string,mixed>  $extra
     */
    private function report(FleetWorkOrder $order, User $by, array $extra = []): int
    {
        return DB::table('fleet_maintenance_reports')->insertGetId($extra + [
            'work_order_id' => $order->id, 'asset_id' => $order->asset_id, 'site_id' => $this->site->id,
            'submitted_by_user_id' => $by->id, 'submitted_at' => now(), 'request_key' => 'calendar-report-'.Str::uuid(),
            'request_fingerprint' => str_repeat('f', 64), 'title' => $order->title, 'created_at' => now(),
        ]);
    }

    /** A restriction as PKG-01 records it; released when a release time is given (Auckland time). */
    private function restriction(FleetWorkOrder $order, User $by, ?int $runId, string $createdLocal, ?string $releasedLocal = null): int
    {
        return DB::table('fleet_maintenance_restrictions')->insertGetId([
            'work_order_id' => $order->id, 'asset_id' => $order->asset_id, 'source_run_id' => $runId,
            'source_key' => 'calendar-restriction-'.Str::uuid(), 'restriction_kind' => 'safety',
            'state' => $releasedLocal === null ? 'active' : 'released', 'created_by_user_id' => $by->id,
            'created_at' => Carbon::parse($createdLocal, 'Pacific/Auckland')->utc(),
            'released_by_user_id' => $releasedLocal === null ? null : $by->id,
            'released_at' => $releasedLocal === null ? null : Carbon::parse($releasedLocal, 'Pacific/Auckland')->utc(),
        ]);
    }
}
