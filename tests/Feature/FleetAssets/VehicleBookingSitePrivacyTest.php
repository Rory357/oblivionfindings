<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\FleetVehicleBooking;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Notifications\Fleet\FleetBookingApprovedNotification;
use App\Notifications\Fleet\FleetBookingRejectedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VehicleBookingSitePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $primarySite;

    private Site $secondarySite;

    private Site $foreignSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-08-17 09:00:00', 'Pacific/Auckland'));

        $this->primarySite = Site::factory()->create(['name' => 'Harbour House']);
        $this->secondarySite = Site::factory()->create(['name' => 'Kauri House']);
        $this->foreignSite = Site::factory()->create(['name' => 'Rimu House']);
    }

    public function test_register_export_counts_calendar_and_every_picker_are_scoped_when_filters_are_omitted(): void
    {
        $archivedSite = Site::factory()->create([
            'name' => 'Archived House',
            'is_active' => false,
            'archived' => true,
            'archived_at' => now(),
        ]);
        $viewer = $this->siteUser(
            [$this->primarySite, $this->secondarySite, $archivedSite],
            ['fleet.viewAny', 'clients.viewAny'],
        );
        $primaryVehicle = $this->vehicle($this->primarySite, 'Harbour Van');
        $secondaryVehicle = $this->vehicle($this->secondarySite, 'Kauri Van');
        $foreignVehicle = $this->vehicle($this->foreignSite, 'Rimu Van');
        $archivedVehicle = $this->vehicle($archivedSite, 'Archived Van');
        $primaryClient = $this->client($this->primarySite, 'Ada', 'Harbour');
        $secondaryClient = $this->client($this->secondarySite, 'Ben', 'Kauri');
        $this->client($this->foreignSite, 'Cara', 'Rimu');

        $primaryBooking = $this->booking($primaryVehicle, $viewer, 'Primary appointment');
        $secondaryBooking = $this->booking($secondaryVehicle, $viewer, 'Secondary appointment');
        $this->booking($foreignVehicle, $viewer, 'Foreign private appointment');
        $this->booking($archivedVehicle, $viewer, 'Archived private appointment');

        $this->actingAs($viewer)
            ->get('/fleet-assets/bookings?new=1&view=calendar&week_start=2026-08-17')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/bookings/index')
                ->has('bookings.data', 2)
                ->where('bookings.meta.total', 2)
                ->where('hero.pending', 2)
                ->has('calendar_bookings', 2)
                ->has('vehicles', 2)
                ->has('booking_options.vehicles', 2)
                ->has('booking_options.sites', 2)
                ->has('booking_options.clients', 2)
                ->where('bookings.data', fn (mixed $rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$primaryBooking->id, $secondaryBooking->id])->sort()->values()->all())
                ->where('calendar_bookings', fn (mixed $rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$primaryBooking->id, $secondaryBooking->id])->sort()->values()->all())
                ->where('vehicles', fn (mixed $rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$primaryVehicle->id, $secondaryVehicle->id])->sort()->values()->all())
                ->where('booking_options.vehicles', fn (mixed $rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$primaryVehicle->id, $secondaryVehicle->id])->sort()->values()->all())
                ->where('booking_options.sites', fn (mixed $rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$this->primarySite->id, $this->secondarySite->id])->sort()->values()->all())
                ->where('booking_options.clients', fn (mixed $rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$primaryClient->id, $secondaryClient->id])->sort()->values()->all()));

        $csv = $this->actingAs($viewer)->get('/fleet-assets/bookings?export=csv');
        $csv->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('Primary appointment', $content);
        $this->assertStringContainsString('Secondary appointment', $content);
        $this->assertStringNotContainsString('Foreign private appointment', $content);
        $this->assertStringNotContainsString('Archived private appointment', $content);
    }

    public function test_selected_vehicle_conflicts_status_and_availability_conceal_foreign_and_missing_assets(): void
    {
        $viewer = $this->siteUser([$this->primarySite], ['fleet.viewAny']);
        $local = $this->vehicle($this->primarySite, 'Local availability van');
        $foreign = $this->vehicle($this->foreignSite, 'Foreign availability van');
        $localBooking = $this->booking($local, $viewer, 'Visible availability');
        $this->booking($foreign, $viewer, 'Private availability');

        $query = http_build_query([
            'check_asset_id' => $local->id,
            'check_starts_at' => now()->addMinutes(30)->toISOString(),
            'check_ends_at' => now()->addHours(3)->toISOString(),
        ]);
        $this->actingAs($viewer)
            ->get("/fleet-assets/bookings?{$query}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking_vehicle_status', 'active')
                ->has('booking_conflicts', 1)
                ->where('booking_conflicts.0.id', $localBooking->id)
                ->has('booking_vehicle_bookings', 1)
                ->where('booking_vehicle_bookings.0.id', $localBooking->id));

        foreach ([$foreign->id, 987654321] as $assetId) {
            $this->actingAs($viewer)
                ->get('/fleet-assets/bookings?'.http_build_query([
                    'check_asset_id' => $assetId,
                    'check_starts_at' => now()->toISOString(),
                    'check_ends_at' => now()->addHour()->toISOString(),
                ]))
                ->assertNotFound();
        }
    }

    public function test_foreign_booking_and_missing_booking_direct_ids_are_concealed_identically(): void
    {
        $viewer = $this->siteUser([$this->primarySite], ['fleet.viewAny']);
        $foreignBooking = $this->booking(
            $this->vehicle($this->foreignSite, 'Foreign detail van'),
            $viewer,
            'Foreign detail',
        );

        $this->actingAs($viewer)->get("/fleet-assets/bookings/{$foreignBooking->id}")->assertNotFound();
        $this->actingAs($viewer)->get('/fleet-assets/bookings/987654321')->assertNotFound();
    }

    public function test_store_conceals_foreign_asset_client_and_site_ids_without_booking_or_audit_effects(): void
    {
        $driver = $this->siteUser(
            [$this->primarySite],
            ['fleet.viewAny', 'clients.viewAny'],
            driverEligible: true,
        );
        $localVehicle = $this->vehicle($this->primarySite, 'Local bookable van');
        $foreignVehicle = $this->vehicle($this->foreignSite, 'Foreign bookable van');
        $localClient = $this->client($this->primarySite, 'Local', 'Client');
        $foreignClient = $this->client($this->foreignSite, 'Foreign', 'Client');
        $baselineBookings = FleetVehicleBooking::query()->count();
        $baselineAudits = DB::table('audit_logs')->where('action', 'fleet.booking.create')->count();

        $valid = $this->storePayload($localVehicle, $localClient, $this->primarySite);
        $attempts = [
            array_replace($valid, ['asset_id' => $foreignVehicle->id]),
            array_replace($valid, ['asset_id' => 987654321]),
            array_replace($valid, ['client_id' => $foreignClient->id]),
            array_replace($valid, ['client_id' => 987654321]),
            array_replace($valid, ['pickup_site_id' => $this->foreignSite->id]),
            array_replace($valid, ['return_site_id' => 987654321]),
            array_replace($valid, ['pickup_site_id' => 0]),
        ];

        foreach ($attempts as $payload) {
            $this->actingAs($driver)->post('/fleet-assets/bookings', $payload)->assertNotFound();
            $this->assertSame($baselineBookings, FleetVehicleBooking::query()->count());
            $this->assertSame($baselineAudits, DB::table('audit_logs')->where('action', 'fleet.booking.create')->count());
        }

        $invalidActionPayload = array_replace($valid, [
            'purpose' => '',
            'starts_at' => 'not-a-date',
            'ends_at' => 'also-not-a-date',
        ]);
        foreach ([
            array_replace($invalidActionPayload, ['asset_id' => $foreignVehicle->id]),
            array_replace($invalidActionPayload, ['client_id' => $foreignClient->id]),
            array_replace($invalidActionPayload, ['pickup_site_id' => $this->foreignSite->id]),
        ] as $payload) {
            $this->actingAs($driver)->post('/fleet-assets/bookings', $payload)->assertNotFound();
            $this->assertSame($baselineBookings, FleetVehicleBooking::query()->count());
            $this->assertSame($baselineAudits, DB::table('audit_logs')->where('action', 'fleet.booking.create')->count());
        }
    }

    public function test_secondary_site_store_is_allowed_and_client_advisory_input_is_not_persisted(): void
    {
        $driver = $this->siteUser(
            [$this->primarySite, $this->secondarySite],
            ['fleet.viewAny', 'clients.viewAny'],
            driverEligible: true,
        );
        $vehicle = $this->vehicle($this->secondarySite, 'Secondary site van');
        $client = $this->client($this->secondarySite, 'Secondary', 'Client');
        $payload = $this->storePayload($vehicle, $client, $this->secondarySite);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower(str_replace(['`', '"'], '', $query->sql));
        });

        $this->actingAs($driver)
            ->post('/fleet-assets/bookings', $payload)
            ->assertRedirect();
        $this->assertTrue(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'from sites') && str_contains($sql, 'for update'),
        ), 'Submitted and Asset-provenance Sites must be locked before booking creation.');
        $this->assertTrue(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'from assets') && str_contains($sql, 'for update'),
        ), 'The canonical Asset must serialize even an initially empty booking range.');

        $this->actingAs($driver)
            ->post('/fleet-assets/bookings', $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('asset_id');

        $booking = FleetVehicleBooking::query()->where('asset_id', $vehicle->id)->sole();
        $this->assertSame('pending', $booking->status);
        $this->assertSame($this->secondarySite->id, (int) $booking->pickup_site_id);
        $this->assertArrayNotHasKey('client_id', $booking->getAttributes());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fleet.booking.create',
            'auditable_id' => $booking->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'fleet.booking.create')
            ->where('auditable_id', $booking->id)
            ->count());
    }

    public function test_every_foreign_mutation_is_concealed_before_status_payload_audit_or_notification_effects(): void
    {
        Notification::fake();
        $manager = $this->siteUser(
            [$this->primarySite],
            ['fleet.viewAny', 'fleet.manage', 'fleet.bookings.approve'],
        );
        $owner = $this->siteUser([$this->foreignSite], []);
        $vehicle = $this->vehicle($this->foreignSite, 'Foreign mutation van');
        $bookings = [
            'approve' => $this->booking($vehicle, $owner, 'Approve private', 'pending'),
            'reject' => $this->booking($vehicle, $owner, 'Reject private', 'pending'),
            'checkout' => $this->booking($vehicle, $owner, 'Checkout private', 'approved'),
            'return' => $this->booking($vehicle, $owner, 'Return private', 'checked_out'),
            'cancel' => $this->booking($vehicle, $owner, 'Cancel private', 'approved'),
        ];
        $before = collect($bookings)->mapWithKeys(fn (FleetVehicleBooking $booking, string $action): array => [
            $action => $booking->fresh()->getAttributes(),
        ]);
        $auditCount = DB::table('audit_logs')->where('action', 'like', 'fleet.booking.%')->count();

        $requests = [
            ['approve', []],
            ['reject', []], // Scoped 404 must precede required-reason validation.
            ['checkout', ['odometer_out' => 123]],
            ['return', ['odometer_in' => 456, 'return_notes' => 'must not persist']],
            ['cancel', []],
        ];
        foreach ($requests as [$action, $payload]) {
            $this->actingAs($manager)
                ->post("/fleet-assets/bookings/{$bookings[$action]->id}/{$action}", $payload)
                ->assertNotFound();
        }

        foreach ($bookings as $action => $booking) {
            $this->assertSame($before[$action], $booking->fresh()->getAttributes());
        }
        $this->assertSame($auditCount, DB::table('audit_logs')->where('action', 'like', 'fleet.booking.%')->count());
        Notification::assertNothingSent();
    }

    public function test_global_site_scope_never_replaces_the_exact_booking_action(): void
    {
        Notification::fake();
        $owner = $this->siteUser([$this->foreignSite], []);
        $booking = $this->booking(
            $this->vehicle($this->foreignSite, 'Global scope van'),
            $owner,
            'Global scope approval',
        );
        $globalViewer = $this->siteUser(
            [$this->primarySite],
            ['fleet.viewAny', 'securityDevices.devices.viewAllSites'],
        );

        $this->actingAs($globalViewer)
            ->get("/fleet-assets/bookings/{$booking->id}")
            ->assertOk();
        $this->actingAs($globalViewer)
            ->post("/fleet-assets/bookings/{$booking->id}/approve")
            ->assertForbidden();
        $this->assertSame('pending', $booking->fresh()->status);

        $this->grantPermissions($globalViewer, ['fleet.bookings.approve']);
        $this->actingAs($globalViewer)
            ->post("/fleet-assets/bookings/{$booking->id}/approve")
            ->assertRedirect();

        $this->assertSame('approved', $booking->fresh()->status);
        $this->assertSame($globalViewer->id, (int) $booking->fresh()->approved_by_user_id);
        Notification::assertSentToTimes($owner, FleetBookingApprovedNotification::class, 1);
    }

    public function test_exact_lifecycle_action_permissions_use_canonical_site_scope_without_incidental_read_permission(): void
    {
        Notification::fake();
        $owner = $this->siteUser([$this->primarySite], []);
        $assetViewer = $this->siteUser([$this->primarySite], ['assets.viewAny']);
        $approver = $this->siteUser([$this->primarySite], ['fleet.bookings.approve']);
        $manager = $this->siteUser([$this->primarySite], ['fleet.manage']);
        $localVehicle = $this->vehicle($this->primarySite, 'Action-only local van');
        $foreignVehicle = $this->vehicle($this->foreignSite, 'Action-only foreign van');
        $readOnly = $this->booking($localVehicle, $owner, 'Asset read permission is not an action');
        $approval = $this->booking($localVehicle, $owner, 'Approval without read permission');
        $cancellation = $this->booking($localVehicle, $owner, 'Cancellation without read permission', 'approved');
        $foreign = $this->booking($foreignVehicle, $owner, 'Foreign action-only booking', 'approved');

        $this->actingAs($assetViewer)
            ->get("/fleet-assets/bookings/{$readOnly->id}")
            ->assertOk();
        $this->actingAs($assetViewer)
            ->post("/fleet-assets/bookings/{$readOnly->id}/cancel")
            ->assertForbidden();
        $this->actingAs($approver)
            ->post("/fleet-assets/bookings/{$approval->id}/approve")
            ->assertRedirect();
        $this->actingAs($manager)
            ->post("/fleet-assets/bookings/{$cancellation->id}/cancel")
            ->assertRedirect();
        $this->actingAs($manager)
            ->post("/fleet-assets/bookings/{$foreign->id}/cancel")
            ->assertNotFound();

        $this->assertSame('pending', $readOnly->fresh()->status);
        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertSame('cancelled', $cancellation->fresh()->status);
        $this->assertSame('approved', $foreign->fresh()->status);
        Notification::assertSentToTimes($owner, FleetBookingApprovedNotification::class, 1);
    }

    public function test_same_site_lifecycle_replay_and_competing_stale_transition_have_one_effect(): void
    {
        Notification::fake();
        $manager = $this->siteUser(
            [$this->primarySite],
            ['fleet.viewAny', 'fleet.manage', 'fleet.bookings.approve'],
        );
        $competingManager = $this->siteUser(
            [$this->primarySite],
            ['fleet.viewAny', 'fleet.bookings.approve'],
        );
        $owner = $this->siteUser([$this->primarySite], []);
        $vehicle = $this->vehicle($this->primarySite, 'Lifecycle van');
        $booking = $this->booking($vehicle, $owner, 'Lifecycle booking');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower(str_replace(['`', '"'], '', $query->sql));
        });

        $this->actingAs($manager)
            ->post("/fleet-assets/bookings/{$booking->id}/approve")
            ->assertRedirect();
        $this->assertTrue(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'from assets') && str_contains($sql, 'for update'),
        ), 'The canonical Asset must serialize booking lifecycle transitions.');
        $this->assertTrue(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'from fleet_vehicle_bookings') && str_contains($sql, 'for update'),
        ), 'The canonical booking must be locked before lifecycle and replay checks.');
        $this->actingAs($competingManager)
            ->post("/fleet-assets/bookings/{$booking->id}/reject", ['rejection_reason' => 'stale competing decision'])
            ->assertUnprocessable();
        $this->actingAs($manager)
            ->post("/fleet-assets/bookings/{$booking->id}/approve")
            ->assertUnprocessable();

        $this->assertSame('approved', $booking->fresh()->status);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('auditable_id', $booking->id)
            ->where('action', 'fleet.booking.approve')
            ->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('auditable_id', $booking->id)
            ->where('action', 'fleet.booking.reject')
            ->count());
        Notification::assertSentToTimes($owner, FleetBookingApprovedNotification::class, 1);
        Notification::assertNotSentTo($owner, FleetBookingRejectedNotification::class);

        $this->actingAs($manager)
            ->post("/fleet-assets/bookings/{$booking->id}/checkout", ['odometer_out' => 100])
            ->assertRedirect();
        $this->actingAs($manager)
            ->post("/fleet-assets/bookings/{$booking->id}/return", ['odometer_in' => 125])
            ->assertRedirect();
        $this->assertSame('returned', $booking->fresh()->status);

        $cancel = $this->booking($vehicle, $owner, 'Cancellation booking');
        $reject = $this->booking($vehicle, $owner, 'Rejection booking');
        $this->actingAs($manager)->post("/fleet-assets/bookings/{$cancel->id}/cancel")->assertRedirect();
        $this->actingAs($manager)
            ->post("/fleet-assets/bookings/{$reject->id}/reject", ['rejection_reason' => 'Vehicle unavailable'])
            ->assertRedirect();
        $this->assertSame('cancelled', $cancel->fresh()->status);
        $this->assertSame('rejected', $reject->fresh()->status);
    }

    /**
     * @param  list<Site>  $sites
     * @param  list<string>  $permissions
     */
    private function siteUser(array $sites, array $permissions, bool $driverEligible = false): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'manager',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $sites[0]->id ?? null,
            'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->values()->all(),
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $this->grantPermissions($user, $permissions);

        if ($driverEligible) {
            HrDriverEligibility::query()->create([
                'tenant_id' => 1,
                'user_id' => $user->id,
                'licence_number' => 'DL-'.$user->id,
                'licence_class' => '1',
                'licence_expires_at' => today()->addYear(),
                'status' => 'eligible',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        return $user;
    }

    /** @param list<string> $permissionKeys */
    private function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = collect($permissionKeys)
            ->map(function (string $key): int {
                $group = str($key)->before('.')->value() ?: 'fleet';

                return Permission::query()->firstOrCreate(
                    ['key' => $key],
                    ['description' => $key, 'group' => $group, 'module' => $group],
                )->id;
            })
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
        $user->unsetRelation('permissionOverrides');
    }

    private function vehicle(Site $site, string $name): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $site->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function client(Site $site, string $firstName, string $lastName): Client
    {
        return Client::factory()->create([
            'site_id' => $site->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'status' => 'active',
            'transport_needs' => ['wheelchair_ramp' => true],
        ]);
    }

    private function booking(
        Asset $vehicle,
        User $owner,
        string $purpose,
        string $status = 'pending',
    ): FleetVehicleBooking {
        return FleetVehicleBooking::query()->create([
            'asset_id' => $vehicle->id,
            'user_id' => $owner->id,
            'purpose' => $purpose,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'pickup_site_id' => $vehicle->site_id,
            'return_site_id' => $vehicle->site_id,
            'status' => $status,
        ]);
    }

    /** @return array<string, mixed> */
    private function storePayload(Asset $vehicle, Client $client, Site $site): array
    {
        return [
            'asset_id' => $vehicle->id,
            'client_id' => $client->id,
            'purpose' => 'Accessible transport appointment',
            'starts_at' => now()->addDay()->toISOString(),
            'ends_at' => now()->addDay()->addHours(2)->toISOString(),
            'passengers' => 2,
            'pickup_site_id' => $site->id,
            'return_site_id' => $site->id,
        ];
    }
}
