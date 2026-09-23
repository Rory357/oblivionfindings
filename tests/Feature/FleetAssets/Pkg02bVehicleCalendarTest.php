<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
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
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PKG-02B vehicle calendar: the feed and summary behind the calendar, unavailable
 * periods, booking changes, service appointments and reminder snoozing.
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
}
