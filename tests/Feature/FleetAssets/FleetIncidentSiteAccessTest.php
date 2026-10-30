<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\FleetIncident;
use App\Models\FleetIncidentAttachment;
use App\Models\FleetVehicleBooking;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\Fleet\FleetIncidentReportedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * FA-T01: a fleet incident belongs to its asset's Site. Viewers and incident
 * managers only reach incidents, counts, exports and pickers at their approved
 * Sites; `fleet.manage` is the explicit all-Sites bypass. A foreign id answers
 * 404 exactly like a missing one, before the payload is validated.
 */
class FleetIncidentSiteAccessTest extends TestCase
{
    use RefreshDatabase;

    private Site $localSite;

    private Site $otherSite;

    private Asset $localVehicle;

    private Asset $otherVehicle;

    private FleetIncident $localIncident;

    private FleetIncident $otherIncident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->localSite = Site::factory()->create(['name' => 'Harbour House']);
        $this->otherSite = Site::factory()->create(['name' => 'Forest House']);
        $this->localVehicle = $this->vehicleAt($this->localSite, 'Harbour Van');
        $this->otherVehicle = $this->vehicleAt($this->otherSite, 'Forest Van');
        $this->localIncident = $this->incidentOn($this->localVehicle, 'Harbour Van clipped the gate post.');
        $this->otherIncident = $this->incidentOn($this->otherVehicle, 'Forest Van reversed into a bollard.');
    }

    public function test_site_viewer_list_counts_pickers_and_export_cover_only_their_sites(): void
    {
        $viewer = $this->staffAt($this->localSite, ['fleet.viewAny']);
        $localDriver = $this->staffAt($this->localSite, [], 'Harbour Driver');
        $this->staffAt($this->otherSite, [], 'Forest Driver');
        $this->incidentOn(
            Asset::factory()->vehicle()->create(['site_id' => null, 'name' => 'Unsited Van']),
            'Unsited Van lost a mirror.',
        );

        $this->actingAs($viewer)
            ->get('/fleet-assets/incidents')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/incidents/index')
                ->where('incidents.meta.total', 1)
                ->where('incidents.data.0.id', $this->localIncident->id)
                ->where('tabCounts.all', 1)
                ->where('stats.reported', 1)
                ->where('formOptions.sites', fn ($sites): bool => collect($sites)->pluck('id')->all() === [$this->localSite->id])
                ->where('formOptions.assets', fn ($assets): bool => collect($assets)->pluck('id')->all() === [$this->localVehicle->id])
                ->where('formOptions.users', fn ($users): bool => collect($users)->pluck('id')->sort()->values()->all()
                    === collect([$viewer->id, $localDriver->id])->sort()->values()->all()));

        $csv = $this->actingAs($viewer)
            ->get('/fleet-assets/incidents?export=csv')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Harbour Van clipped the gate post.', $csv);
        $this->assertStringNotContainsString('Forest Van', $csv);
        $this->assertStringNotContainsString('Unsited Van', $csv);

        // Without a current HR Site there is nothing to see: the boundary fails closed.
        $unplaced = User::factory()->create(['approved_at' => now()]);
        $this->grant($unplaced, ['fleet.viewAny']);

        $this->actingAs($unplaced)
            ->get('/fleet-assets/incidents')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('incidents.meta.total', 0)
                ->where('tabCounts.all', 0)
                ->has('formOptions.assets', 0)
                ->has('formOptions.users', 0)
                ->has('formOptions.sites', 0));
    }

    public function test_option_search_and_filters_never_reach_other_sites(): void
    {
        $viewer = $this->staffAt($this->localSite, ['fleet.viewAny']);
        $localDriver = $this->staffAt($this->localSite, [], 'Harbour Driver');
        $otherDriver = $this->staffAt($this->otherSite, [], 'Forest Driver');

        $assets = $this->actingAs($viewer)
            ->getJson('/fleet-assets/incidents/options/search?type=assets&q=Van')
            ->assertOk()
            ->json('results');
        $this->assertSame([$this->localVehicle->id], collect($assets)->pluck('id')->all());

        $people = $this->actingAs($viewer)
            ->getJson('/fleet-assets/incidents/options/search?type=users&q=Driver')
            ->assertOk()
            ->json('results');
        $this->assertSame([$localDriver->id], collect($people)->pluck('id')->all());

        // A foreign vehicle or driver in the filters is never echoed back as an option.
        $this->actingAs($viewer)
            ->get("/fleet-assets/incidents?vehicle_id={$this->otherVehicle->id}&driver_id={$otherDriver->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('incidents.meta.total', 0)
                ->where('formOptions.assets', fn ($options): bool => ! collect($options)->contains('id', $this->otherVehicle->id))
                ->where('formOptions.users', fn ($options): bool => ! collect($options)->contains('id', $otherDriver->id)));

        $this->actingAs($viewer)
            ->get("/fleet-assets/incidents?site_id={$this->otherSite->id}")
            ->assertNotFound();

        $this->actingAs($viewer)
            ->get("/fleet-assets/incidents?site_id={$this->localSite->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('incidents.meta.total', 1)
                ->where('incidents.data.0.id', $this->localIncident->id));
    }

    public function test_foreign_incident_detail_and_evidence_are_concealed_like_missing_ids(): void
    {
        Storage::fake('private');
        $viewer = $this->staffAt($this->localSite, ['fleet.viewAny']);
        $localEvidence = $this->evidenceOn($this->localIncident);
        $otherEvidence = $this->evidenceOn($this->otherIncident);
        $missingId = (int) FleetIncident::withTrashed()->max('id') + 1000;

        $this->actingAs($viewer)
            ->getJson("/fleet-assets/incidents/{$this->localIncident->id}")
            ->assertOk()
            ->assertJsonPath('incident.id', $this->localIncident->id);

        foreach ([$this->otherIncident->id, $missingId] as $hiddenId) {
            $this->actingAs($viewer)->getJson("/fleet-assets/incidents/{$hiddenId}")->assertNotFound();
            $this->actingAs($viewer)->get("/fleet-assets/incidents/{$hiddenId}")->assertNotFound();

            // A deep link to a hidden incident opens no detail, exactly like a missing one.
            $this->actingAs($viewer)
                ->get("/fleet-assets/incidents?incident={$hiddenId}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('detail', null));
        }

        $this->actingAs($viewer)
            ->get("/fleet-assets/incidents?incident={$this->localIncident->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.id', $this->localIncident->id));

        $this->actingAs($viewer)
            ->get("/fleet-assets/incidents/{$this->localIncident->id}/attachments/{$localEvidence->id}/download")
            ->assertOk();

        $this->actingAs($viewer)
            ->get("/fleet-assets/incidents/{$this->otherIncident->id}/attachments/{$otherEvidence->id}/download")
            ->assertNotFound();
    }

    public function test_incident_manage_permission_does_not_widen_sites_and_foreign_ids_404_before_validation(): void
    {
        Storage::fake('private');
        $manager = $this->staffAt($this->localSite, ['fleet.viewAny', 'fleet.incidents.manage']);
        $followup = $this->otherIncident->followups()->create([
            'notes' => 'Chase the insurer.',
            'created_by' => $manager->id,
        ]);
        $evidence = $this->evidenceOn($this->otherIncident);
        $url = "/fleet-assets/incidents/{$this->otherIncident->id}";

        // Each payload is invalid, so a 404 proves the Site check ran first.
        $this->actingAs($manager)->putJson($url, ['severity' => 'catastrophic'])->assertNotFound();
        $this->actingAs($manager)->postJson("{$url}/status", [])->assertNotFound();
        $this->actingAs($manager)->postJson("{$url}/followups", [])->assertNotFound();
        $this->actingAs($manager)->postJson("{$url}/followups/{$followup->id}/complete")->assertNotFound();
        $this->actingAs($manager)->postJson("{$url}/attachments", [])->assertNotFound();
        $this->actingAs($manager)->deleteJson("{$url}/attachments/{$evidence->id}")->assertNotFound();
        $this->actingAs($manager)->postJson("{$url}/police-report", ['reported_at' => 'not-a-date'])->assertNotFound();
        $this->actingAs($manager)->postJson("{$url}/claim", ['insurance_excess' => -5])->assertNotFound();
        $this->actingAs($manager)->postJson("{$url}/off-road", ['off_road_from' => 'not-a-date'])->assertNotFound();
        $this->actingAs($manager)->postJson("{$url}/back-in-service", ['service_resumed_at' => 'not-a-date'])->assertNotFound();

        $this->otherIncident->refresh();
        $this->assertSame('reported', $this->otherIncident->status);
        $this->assertFalse((bool) $this->otherIncident->police_notified);
        $this->assertFalse((bool) $this->otherIncident->insurance_claimed);
        $this->assertFalse((bool) $this->otherIncident->vehicle_off_road);
        $this->assertNull($followup->fresh()->completed_at);
        $this->assertSame(1, $this->otherIncident->followups()->count());
        $this->assertSame(1, $this->otherIncident->attachments()->count());
        Storage::disk('private')->assertExists($evidence->path);
        $this->assertSame(0, AuditLog::query()
            ->where(fn ($audit) => $audit
                ->where('action', 'like', 'fleet.incident.%')
                ->orWhere('action', 'fleetincident.update'))
            ->count());

        // The same action permission still works at the manager's own Site.
        $this->actingAs($manager)
            ->postJson("/fleet-assets/incidents/{$this->localIncident->id}/status", ['status' => 'investigating'])
            ->assertOk();
        $this->assertSame('investigating', $this->localIncident->fresh()->status);
    }

    public function test_fleet_manage_is_the_explicit_all_sites_bypass(): void
    {
        $fleetManager = $this->staffAt($this->localSite, ['fleet.viewAny', 'fleet.manage']);

        $this->actingAs($fleetManager)
            ->get('/fleet-assets/incidents')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('incidents.meta.total', 2)
                ->where('tabCounts.all', 2)
                ->where('formOptions.sites', fn ($sites): bool => collect($sites)->pluck('id')->contains($this->localSite->id)
                    && collect($sites)->pluck('id')->contains($this->otherSite->id)));

        $this->actingAs($fleetManager)
            ->get("/fleet-assets/incidents?site_id={$this->otherSite->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('incidents.meta.total', 1)
                ->where('incidents.data.0.id', $this->otherIncident->id));

        $this->actingAs($fleetManager)
            ->getJson("/fleet-assets/incidents/{$this->otherIncident->id}")
            ->assertOk()
            ->assertJsonPath('incident.id', $this->otherIncident->id);

        $this->actingAs($fleetManager)
            ->postJson("/fleet-assets/incidents/{$this->otherIncident->id}/status", ['status' => 'investigating'])
            ->assertOk();
        $this->assertSame('investigating', $this->otherIncident->fresh()->status);
    }

    public function test_reporting_conceals_foreign_assets_bookings_and_people_before_validation(): void
    {
        Notification::fake();
        $reporter = $this->staffAt($this->localSite, ['fleet.viewAny']);
        $localDriver = $this->staffAt($this->localSite, [], 'Harbour Driver');
        $otherDriver = $this->staffAt($this->otherSite, [], 'Forest Driver');
        $otherBooking = FleetVehicleBooking::factory()->create(['asset_id' => $this->otherVehicle->id]);
        $missingAssetId = (int) Asset::query()->max('id') + 1000;
        $report = [
            'asset_id' => $this->localVehicle->id,
            'incident_type' => 'damage',
            'severity' => 'minor',
            'occurred_at' => now()->subHour()->toDateTimeString(),
            'description' => 'Scraped the wing mirror on the carport.',
        ];

        // A foreign asset answers like a missing one, even with an otherwise empty payload.
        foreach ([$this->otherVehicle->id, $missingAssetId] as $assetId) {
            $this->actingAs($reporter)
                ->postJson('/fleet-assets/incidents', ['asset_id' => $assetId])
                ->assertNotFound();
        }
        $this->actingAs($reporter)
            ->postJson('/fleet-assets/incidents', [...$report, 'driver_user_id' => $otherDriver->id])
            ->assertNotFound();
        $this->actingAs($reporter)
            ->postJson('/fleet-assets/incidents', [...$report, 'assigned_to_user_id' => $otherDriver->id])
            ->assertNotFound();
        $this->actingAs($reporter)
            ->postJson('/fleet-assets/incidents', [...$report, 'booking_id' => $otherBooking->id])
            ->assertNotFound();

        $this->assertSame(2, FleetIncident::query()->count());
        Notification::assertNothingSent();

        // A malformed id is still an ordinary validation error.
        $this->actingAs($reporter)
            ->postJson('/fleet-assets/incidents', [...$report, 'asset_id' => 'not-an-id'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('asset_id');

        $this->actingAs($reporter)
            ->postJson('/fleet-assets/incidents', [...$report, 'driver_user_id' => $localDriver->id])
            ->assertOk();
        $this->assertDatabaseHas('fleet_incidents', [
            'asset_id' => $this->localVehicle->id,
            'driver_user_id' => $localDriver->id,
            'reported_by_user_id' => $reporter->id,
        ]);
    }

    public function test_edits_conceal_foreign_people_and_assets_but_keep_values_the_incident_already_holds(): void
    {
        $manager = $this->staffAt($this->localSite, ['fleet.viewAny', 'fleet.incidents.manage']);
        $localDriver = $this->staffAt($this->localSite, [], 'Harbour Driver');
        $otherDriver = $this->staffAt($this->otherSite, [], 'Forest Driver');
        // A driver from another Site was at the wheel when this was reported.
        $this->localIncident->update(['driver_user_id' => $otherDriver->id]);
        $url = "/fleet-assets/incidents/{$this->localIncident->id}";

        $this->actingAs($manager)
            ->postJson("{$url}/followups", ['notes' => 'Book the panel beater.', 'assigned_to_user_id' => $otherDriver->id])
            ->assertNotFound();
        $this->actingAs($manager)->putJson($url, ['asset_id' => $this->otherVehicle->id])->assertNotFound();
        $this->actingAs($manager)->putJson($url, ['supervisor_user_id' => $otherDriver->id])->assertNotFound();

        $this->assertSame(0, $this->localIncident->followups()->count());
        $this->assertSame($this->localVehicle->id, (int) $this->localIncident->fresh()->asset_id);
        $this->assertNull($this->localIncident->fresh()->supervisor_user_id);

        // Re-sending the driver the incident already holds never blocks an edit.
        $this->actingAs($manager)
            ->putJson($url, ['driver_user_id' => $otherDriver->id, 'location' => 'Harbour House car park'])
            ->assertOk();
        $this->assertSame('Harbour House car park', $this->localIncident->fresh()->location);

        $this->actingAs($manager)
            ->postJson("{$url}/followups", ['notes' => 'Book the panel beater.', 'assigned_to_user_id' => $localDriver->id])
            ->assertOk();
        $this->assertDatabaseHas('fleet_incident_followups', [
            'fleet_incident_id' => $this->localIncident->id,
            'assigned_to_user_id' => $localDriver->id,
        ]);
    }

    public function test_new_incident_notifications_only_reach_managers_who_can_open_it(): void
    {
        Notification::fake();
        $incidentDesk = $this->roleWith(['fleet.viewAny', 'fleet.incidents.manage']);
        $localDesk = $this->staffAt($this->localSite);
        $otherDesk = $this->staffAt($this->otherSite);
        $localDesk->roles()->attach($incidentDesk);
        $otherDesk->roles()->attach($incidentDesk);
        $fleetManager = $this->staffAt($this->otherSite);
        $fleetManager->roles()->attach(Role::query()->where('name', 'fleet_manager')->firstOrFail());

        $this->actingAs($localDesk)
            ->postJson('/fleet-assets/incidents', [
                'asset_id' => $this->localVehicle->id,
                'incident_type' => 'damage',
                'severity' => 'minor',
                'occurred_at' => now()->subHour()->toDateTimeString(),
                'description' => 'Door ding in the Harbour House car park.',
            ])
            ->assertOk();

        Notification::assertSentTo([$localDesk, $fleetManager], FleetIncidentReportedNotification::class);
        Notification::assertNotSentTo($otherDesk, FleetIncidentReportedNotification::class);
    }

    /** @param list<string> $permissionKeys */
    private function staffAt(Site $site, array $permissionKeys = [], ?string $name = null): User
    {
        $user = User::factory()->create([
            ...($name ? ['name' => $name] : []),
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $this->grant($user, $permissionKeys);

        return $user;
    }

    /** @param list<string> $permissionKeys */
    private function grant(User $user, array $permissionKeys): void
    {
        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                ['description' => $permissionKey, 'group' => 'fleet', 'module' => 'Fleet'],
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }
    }

    /** @param list<string> $permissionKeys */
    private function roleWith(array $permissionKeys): Role
    {
        $role = Role::query()->create([
            'name' => 'fleet_incident_desk_'.str()->uuid(),
            'label' => 'Fleet incident desk test role',
            'level' => 50,
            'type' => 'custom',
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissionKeys)->pluck('id'));

        return $role;
    }

    private function vehicleAt(Site $site, string $name): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $site->id,
            'category' => 'vehicle',
            'name' => $name,
        ]);
    }

    private function incidentOn(Asset $asset, string $description): FleetIncident
    {
        return FleetIncident::factory()->create([
            'asset_id' => $asset->id,
            'incident_type' => 'damage',
            'severity' => 'minor',
            'status' => 'reported',
            'description' => $description,
        ]);
    }

    private function evidenceOn(FleetIncident $incident): FleetIncidentAttachment
    {
        $path = 'fleet_incident_attachments/'.str()->uuid().'.jpg';
        Storage::disk('private')->put($path, 'evidence');

        return $incident->attachments()->create([
            'disk' => 'private',
            'original_name' => 'scene.jpg',
            'path' => $path,
            'mime' => 'image/jpeg',
            'size' => 8,
            'kind' => 'photo',
        ]);
    }
}
