<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetObligationReminder;
use App\Models\FleetServiceSchedule;
use App\Models\FleetVehicleCheckRequirement;
use App\Models\FleetVehicleOdometerObservation;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\VehicleObligationReminderService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PKG-02B obligation reminders: the owner is told in the app when a service
 * or compliance due point comes within its lead time, failures are kept and
 * retried, and acknowledging never completes the obligation.
 */
class Pkg02bObligationRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-22 09:30:00', 'Pacific/Auckland')->utc());
        $this->site = Site::factory()->create(['name' => 'Kōwhai House']);
    }

    public function test_due_obligations_are_delivered_once_to_their_owner(): void
    {
        $owner = $this->siteUser([], 'Service coordinator');
        $vehicle = $this->vehicle(['fleet_responsible_user_id' => $owner->id]);
        $soon = FleetServiceSchedule::query()->create(['asset_id' => $vehicle->id, 'name' => 'Routine service',
            'interval_months' => 6, 'next_due_at' => '2026-10-01', 'reminder_days_before' => 14, 'is_active' => true,
            'owner_user_id' => $owner->id]);
        $later = FleetServiceSchedule::query()->create(['asset_id' => $vehicle->id, 'name' => 'Tyre rotation',
            'interval_months' => 12, 'next_due_at' => '2026-12-01', 'reminder_days_before' => 14, 'is_active' => true]);
        $service = app(VehicleObligationReminderService::class);

        $this->assertSame(['sent' => 1, 'failed' => 0], $service->deliverDue());
        $reminder = FleetObligationReminder::query()->where('source_id', $soon->id)->sole();
        $this->assertSame('sent', $reminder->state);
        $this->assertSame('date:2026-10-01', $reminder->cycle_key);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertStringContainsString('Routine service due 1 Oct 2026', $owner->notifications()->first()->data['title']);
        $this->assertNull(FleetObligationReminder::query()->where('source_id', $later->id)->first());

        // Running again doesn't repeat a delivered due point.
        $this->assertSame(['sent' => 0, 'failed' => 0], $service->deliverDue());
        $this->assertSame(1, $owner->notifications()->count());

        // A new due point after the service is a new reminder.
        $soon->forceFill(['next_due_at' => '2026-10-02'])->save();
        $this->assertSame(['sent' => 1, 'failed' => 0], $service->deliverDue());
        $this->assertSame(2, FleetObligationReminder::query()->where('source_id', $soon->id)->count());
    }

    public function test_a_delivery_without_an_owner_fails_visibly_and_can_be_retried(): void
    {
        $manager = $this->siteUser(['fleet.viewAny', 'fleet.manage']);
        $reader = $this->siteUser(['fleet.viewAny']);
        $vehicle = $this->vehicle();
        $this->compliance($vehicle, 'wof', ['expires_on' => '2026-09-25']);
        $service = app(VehicleObligationReminderService::class);

        $this->assertSame(['sent' => 0, 'failed' => 1], $service->deliverDue());
        $reminder = FleetObligationReminder::query()->sole();
        $this->assertSame('failed', $reminder->state);
        $this->assertStringContainsString('No owner to notify', $reminder->last_error);

        $row = collect($this->actingAs($manager)->get("/fleet-assets/vehicles/{$vehicle->id}")->assertOk()
            ->viewData('page')['props']['workspace']['obligation_reminders'])->firstWhere('source_type', 'compliance_record');
        $this->assertSame('Delivery failed', $row['status_label']);
        $this->assertTrue($row['can']['retry']);

        $url = "/fleet-assets/vehicles/{$vehicle->id}/obligation-reminders/compliance_record/{$reminder->source_id}";
        $this->actingAs($reader)->postJson("{$url}/retry", ['request_key' => 'retry-reader'])->assertForbidden();
        // Recording the missing owner and retrying delivers to them.
        $vehicle->forceFill(['fleet_responsible_user_id' => $manager->id])->save();
        $this->actingAs($manager)->postJson("{$url}/retry", ['request_key' => 'retry-manager'])
            ->assertOk()->assertJsonPath('reminder.state', 'sent');
        $this->actingAs($manager)->postJson("{$url}/retry", ['request_key' => 'retry-manager'])
            ->assertOk()->assertJsonPath('reminder.state', 'sent');
        $this->actingAs($manager)->postJson("{$url}/retry", ['request_key' => 'retry-again-1'])->assertStatus(409);
        $this->assertSame(['failed', 'sent'], $reminder->events()->orderBy('id')->pluck('action')->all());
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/obligation-reminders/compliance_record/999999/retry",
            ['request_key' => 'retry-missing'])->assertNotFound();
    }

    public function test_the_owner_acknowledges_without_completing_the_obligation(): void
    {
        $owner = $this->siteUser(['fleet.viewAny']);
        $other = $this->siteUser(['fleet.viewAny']);
        $vehicle = $this->vehicle(['fleet_responsible_user_id' => $owner->id]);
        $schedule = FleetServiceSchedule::query()->create(['asset_id' => $vehicle->id, 'name' => 'Routine service',
            'interval_months' => 6, 'next_due_at' => '2026-09-30', 'is_active' => true]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/obligation-reminders/service_schedule/{$schedule->id}/acknowledge";

        $this->actingAs($other)->postJson($url, ['note' => 'Seen.', 'request_key' => 'ack-other'])->assertForbidden();
        $this->actingAs($owner)->postJson($url, ['note' => '', 'request_key' => 'ack-empty'])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->actingAs($owner)->postJson($url, ['note' => 'Booked with the garage for 29 Sept.', 'request_key' => 'ack-owner'])
            ->assertOk()->assertJsonPath('reminder.state', 'acknowledged');
        $this->actingAs($owner)->postJson($url, ['note' => 'Booked with the garage for 29 Sept.', 'request_key' => 'ack-owner'])
            ->assertOk();
        $reminder = FleetObligationReminder::query()->sole();
        $this->assertSame(1, $reminder->events()->count());
        $this->assertSame('2026-09-30', $schedule->fresh()->next_due_at->toDateString());
        // An acknowledged due point isn't delivered afterwards.
        $this->assertSame(['sent' => 0, 'failed' => 0], app(VehicleObligationReminderService::class)->deliverDue());

        $this->actingAs($owner)->get("/fleet-assets/vehicles/{$vehicle->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspace.obligation_reminders.0.status_label', 'Acknowledged')
                ->where('workspace.obligation_reminders.0.can.acknowledge', false)->etc());
    }

    public function test_ruc_is_reminded_by_distance_from_the_recorded_odometer(): void
    {
        $owner = $this->siteUser([]);
        $vehicle = $this->vehicle(['fleet_responsible_user_id' => $owner->id]);
        $this->compliance($vehicle, 'ruc', ['ruc_start_km' => 80000, 'ruc_end_km' => 90000]);
        FleetVehicleOdometerObservation::query()->create(['asset_id' => $vehicle->id, 'value_km' => 88500,
            'observed_at' => now()->subDay(), 'source_kind' => 'dashboard_manual', 'recorded_by_user_id' => $owner->id,
            'request_key' => 'odo-1', 'request_fingerprint' => str_repeat('a', 64), 'created_at' => now()]);
        $service = app(VehicleObligationReminderService::class);

        $this->assertSame(['sent' => 0, 'failed' => 0], $service->deliverDue());
        FleetVehicleOdometerObservation::query()->create(['asset_id' => $vehicle->id, 'value_km' => 89100,
            'observed_at' => now(), 'source_kind' => 'dashboard_manual', 'recorded_by_user_id' => $owner->id,
            'request_key' => 'odo-2', 'request_fingerprint' => str_repeat('b', 64), 'created_at' => now()]);
        $this->assertSame(['sent' => 1, 'failed' => 0], $service->deliverDue());
        $this->assertSame('km:90000', FleetObligationReminder::query()->sole()->cycle_key);
    }

    public function test_a_request_key_cant_be_reused_for_a_different_acknowledgement_or_action(): void
    {
        $manager = $this->siteUser(['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle(['fleet_responsible_user_id' => $manager->id]);
        $schedule = FleetServiceSchedule::query()->create(['asset_id' => $vehicle->id, 'name' => 'Routine service',
            'interval_months' => 6, 'next_due_at' => '2026-09-30', 'is_active' => true]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/obligation-reminders/service_schedule/{$schedule->id}";
        $used = 'This request was already used for a different change. Reload and try again.';

        $this->actingAs($manager)->postJson("{$url}/acknowledge", ['note' => 'Booked with the garage.', 'request_key' => 'ack-once'])
            ->assertOk()->assertJsonPath('reminder.state', 'acknowledged');
        // The same request again is the same acknowledgement…
        $this->actingAs($manager)->postJson("{$url}/acknowledge", ['note' => 'Booked with the garage.', 'request_key' => 'ack-once'])
            ->assertOk();
        // …but the key can't carry a different note or another action.
        $this->actingAs($manager)->postJson("{$url}/acknowledge", ['note' => 'A different note.', 'request_key' => 'ack-once'])
            ->assertStatus(409)->assertJsonPath('message', $used);
        $this->actingAs($manager)->postJson("{$url}/retry", ['request_key' => 'ack-once'])
            ->assertStatus(409)->assertJsonPath('message', $used);
        $reminder = FleetObligationReminder::query()->sole();
        $this->assertSame('Booked with the garage.', $reminder->acknowledgement_note);
        $this->assertSame(1, $reminder->events()->count());
    }

    public function test_the_vehicle_check_is_reminded_to_its_owner_like_other_obligations(): void
    {
        $responsible = $this->siteUser([], 'Fleet coordinator');
        $checkOwner = $this->siteUser(['fleet.viewAny'], 'Check owner');
        $manager = $this->siteUser(['fleet.viewAny', 'fleet.manage']);
        $reader = $this->siteUser(['fleet.viewAny']);
        $vehicle = $this->vehicle(['fleet_responsible_user_id' => $responsible->id, 'inspection_due_at' => '2026-09-26']);
        $later = $this->vehicle(['fleet_responsible_user_id' => $responsible->id, 'inspection_due_at' => '2026-11-01']);
        $unplanned = $this->vehicle(['fleet_responsible_user_id' => $responsible->id]);
        $service = app(VehicleObligationReminderService::class);

        // Within its lead time the vehicle's fleet responsible person is told, once.
        $this->assertSame(['sent' => 1, 'failed' => 0], $service->deliverDue());
        $reminder = FleetObligationReminder::query()->sole();
        $this->assertSame(['vehicle_check', $vehicle->id, 'date:2026-09-26', 'sent'],
            [$reminder->source_type, (int) $reminder->source_id, $reminder->cycle_key, $reminder->state]);
        $this->assertStringContainsString('Vehicle check due 26 Sep 2026', $responsible->notifications()->sole()->data['title']);
        $this->assertSame(['sent' => 0, 'failed' => 0], $service->deliverDue());

        // The check requirement's owner owns the next due point.
        FleetVehicleCheckRequirement::query()->create(['asset_id' => $vehicle->id, 'owner_user_id' => $checkOwner->id, 'lock_version' => 1]);
        $vehicle->forceFill(['inspection_due_at' => '2026-09-28'])->save();
        $this->assertSame(['sent' => 1, 'failed' => 0], $service->deliverDue());
        $this->assertSame(1, $checkOwner->notifications()->count());
        $this->assertSame(1, $responsible->notifications()->count());

        $row = collect($this->actingAs($manager)->get("/fleet-assets/vehicles/{$vehicle->id}")->assertOk()
            ->viewData('page')['props']['workspace']['obligation_reminders'])->firstWhere('source_type', 'vehicle_check');
        $this->assertSame(["vehicle_check:{$vehicle->id}", $vehicle->id, 'Vehicle check', '2026-09-28', 'Sent'],
            [$row['key'], $row['source_id'], $row['name'], $row['due_on'], $row['status_label']]);
        $this->assertSame(['id' => $checkOwner->id, 'name' => 'Check owner'], $row['owner']);
        $this->assertSame([true, false], [$row['can']['acknowledge'], $row['can']['retry']]);

        // The owner acknowledges through the same endpoint; the check itself is unchanged.
        $url = "/fleet-assets/vehicles/{$vehicle->id}/obligation-reminders/vehicle_check";
        $this->actingAs($reader)->postJson("{$url}/{$vehicle->id}/acknowledge", ['note' => 'Seen.', 'request_key' => 'ack-check-reader'])
            ->assertForbidden();
        $this->actingAs($checkOwner)->postJson("{$url}/{$later->id}/acknowledge", ['note' => 'Booked.', 'request_key' => 'ack-check-wrong'])
            ->assertNotFound();
        $this->actingAs($checkOwner)->postJson("{$url}/{$vehicle->id}/acknowledge", ['note' => 'Booked for Monday.', 'request_key' => 'ack-check'])
            ->assertOk()->assertJsonPath('reminder.state', 'acknowledged');
        $this->actingAs($checkOwner)->postJson("{$url}/{$vehicle->id}/acknowledge", ['note' => 'Booked for Monday.', 'request_key' => 'ack-check'])
            ->assertOk();
        $this->assertSame(['sent', 'acknowledged'], FleetObligationReminder::query()->where('cycle_key', 'date:2026-09-28')->sole()
            ->events()->orderBy('id')->pluck('action')->all());
        $this->assertSame('2026-09-28', $vehicle->fresh()->inspection_due_at->toDateString());
        // Only a failed delivery can be tried again.
        $this->actingAs($manager)->postJson("{$url}/{$vehicle->id}/retry", ['request_key' => 'retry-check'])->assertStatus(409);

        // Not yet within the lead time, or with no check date, nothing is sent.
        $this->assertNull(FleetObligationReminder::query()->where('source_id', $later->id)->first());
        $this->assertNull(FleetObligationReminder::query()->where('source_id', $unplanned->id)->first());
    }

    public function test_a_vehicle_check_reminder_without_an_owner_fails_and_is_retried_to_the_new_owner(): void
    {
        $manager = $this->siteUser(['fleet.viewAny', 'fleet.manage']);
        $vehicle = $this->vehicle(['inspection_due_at' => '2026-09-24']);
        $service = app(VehicleObligationReminderService::class);

        $this->assertSame(['sent' => 0, 'failed' => 1], $service->deliverDue());
        $reminder = FleetObligationReminder::query()->sole();
        $this->assertSame(['vehicle_check', 'failed'], [$reminder->source_type, $reminder->state]);
        $this->assertStringContainsString('No owner to notify', $reminder->last_error);
        // The daily command delivers the same due points.
        $this->artisan('fleet:deliver-obligation-reminders')->assertSuccessful();
        $this->assertSame('failed', $reminder->fresh()->state);

        $vehicle->forceFill(['fleet_responsible_user_id' => $manager->id])->save();
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/obligation-reminders/vehicle_check/{$vehicle->id}/retry",
            ['request_key' => 'retry-check-owner'])->assertOk()->assertJsonPath('reminder.state', 'sent');
        $this->assertSame(1, $manager->notifications()->count());
    }

    /** @param array<string,mixed> $data */
    private function compliance(Asset $vehicle, string $kind, array $data): void
    {
        $recordId = DB::table('fleet_vehicle_compliance_records')->insertGetId([
            'asset_id' => $vehicle->id, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $versionId = DB::table('fleet_vehicle_compliance_versions')->insertGetId($data + [
            'record_id' => $recordId, 'version' => 1, 'applicability' => 'applicable', 'outcome' => 'recorded',
            'evidence_reference' => strtoupper($kind).'-1', 'request_key' => "evidence-{$kind}",
            'request_fingerprint' => str_repeat('e', 64), 'content_sha256' => str_repeat('e', 64), 'created_at' => now(),
        ]);
        DB::table('fleet_vehicle_compliance_records')->where('id', $recordId)->update(['current_version_id' => $versionId]);
    }

    /** @param list<string> $permissions */
    private function siteUser(array $permissions, string $name = 'Staff member'): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager', 'name' => $name]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
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

    /** @param array<string,mixed> $extra */
    private function vehicle(array $extra = []): Asset
    {
        return Asset::factory()->vehicle()->create($extra + [
            'site_id' => $this->site->id, 'home_site_id' => $this->site->id, 'name' => 'Kōwhai van', 'status' => 'active',
        ]);
    }
}
