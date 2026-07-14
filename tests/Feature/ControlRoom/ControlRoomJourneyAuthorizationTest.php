<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\MedicationError;
use App\Models\Permission;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomJourneyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_site_bound_operator_only_receives_accessible_incidents_sites_and_clients(): void
    {
        $siteA = Site::factory()->create(['type' => 'house']);
        $siteB = Site::factory()->create(['type' => 'house']);
        $clientA = Client::factory()->create(['site_id' => $siteA->id, 'status' => 'active']);
        $clientB = Client::factory()->create(['site_id' => $siteB->id, 'status' => 'active']);
        $operator = $this->siteBoundUser($siteA, [
            'controlRoom.viewAny',
            'controlRoom.alerts.create',
            'safeguarding.viewAny',
        ]);

        $visible = $this->clientIncident($clientA, $operator, 'Visible incident');
        $hidden = $this->clientIncident($clientB, $operator, 'Hidden incident');
        $hiddenMedication = $this->medicationError($clientB, $operator, 'MED-TEST-HIDDEN-LIST');
        $hiddenSafeguarding = SafeguardingConcern::withoutEvents(fn () => SafeguardingConcern::factory()->create([
            'reference_number' => 'SG-TEST-HIDDEN-LIST',
            'site_id' => $siteB->id,
            'is_sensitive' => false,
            'reported_by_user_id' => $operator->id,
        ]));
        $queue = TriageQueue::query()->create([
            'name' => 'Journey queue',
            'code' => 'journey_queue',
            'tier' => 1,
            'is_active' => true,
        ]);
        ControlRoomAlert::factory()->open()->create([
            'queue_id' => $queue->id,
            'site_id' => $siteA->id,
            'client_id' => $clientA->id,
        ]);
        ControlRoomAlert::factory()->open()->create([
            'queue_id' => $queue->id,
            'site_id' => $siteB->id,
            'client_id' => $clientB->id,
        ]);

        $restricted = SafeguardingConcern::withoutEvents(fn () => SafeguardingConcern::factory()->create([
            'reference_number' => 'SG-TEST-RESTRICTED-LIST',
            'site_id' => $siteA->id,
            'is_sensitive' => true,
            'subject_name' => 'Restricted subject',
            'reported_by_user_id' => User::factory()->create()->id,
        ]));

        $this->actingAs($operator)
            ->get('/control-room/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('incidents.data', 1)
                ->where('incidents.data.0.id', 'ci_'.$visible->id)
                ->where('incidents.data.0.source_id', $visible->id)
                ->has('sites', 1)
                ->where('sites.0.id', $siteA->id)
                ->has('clients', 1)
                ->where('clients.0.id', $clientA->id)
            );

        $this->actingAs($operator)
            ->get('/control-room/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sites', 1)
                ->where('sites.0.id', $siteA->id)
                ->has('clients', 1)
                ->where('clients.0.id', $clientA->id)
                ->has('queues', 1)
                ->where('queues.0.active_alerts', 1)
            );

        $this->assertNotSame($visible->id, $hidden->id);
        $this->assertNotNull($hiddenMedication->id);
        $this->assertNotNull($hiddenSafeguarding->id);
        $this->assertNotNull($restricted->id);
    }

    public function test_site_bound_operator_cannot_filter_the_incident_tracker_by_an_inaccessible_site_or_client(): void
    {
        $siteA = Site::factory()->create(['type' => 'house']);
        $siteB = Site::factory()->create(['type' => 'house']);
        $clientB = Client::factory()->create(['site_id' => $siteB->id, 'status' => 'active']);
        $operator = $this->siteBoundUser($siteA, ['controlRoom.viewAny']);

        $this->actingAs($operator)
            ->get('/control-room/incidents?site_id='.$siteB->id)
            ->assertForbidden();

        $this->actingAs($operator)
            ->get('/control-room/incidents?client_id='.$clientB->id)
            ->assertForbidden();
    }

    public function test_site_bound_operator_cannot_create_a_manual_alert_for_an_inaccessible_site_or_client(): void
    {
        $siteA = Site::factory()->create(['type' => 'house']);
        $siteB = Site::factory()->create(['type' => 'house']);
        $clientB = Client::factory()->create(['site_id' => $siteB->id, 'status' => 'active']);
        $operator = $this->siteBoundUser($siteA, ['controlRoom.alerts.create']);

        $this->actingAs($operator)
            ->postJson('/control-room/alerts', [
                'source' => 'manual',
                'alert_type' => 'welfare_check',
                'severity' => 'high',
                'site_id' => $siteB->id,
            ])
            ->assertForbidden();

        $this->actingAs($operator)
            ->postJson('/control-room/alerts', [
                'source' => 'manual',
                'alert_type' => 'welfare_check',
                'severity' => 'high',
                'client_id' => $clientB->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_manual_alert_rejects_an_inconsistent_client_and_site_pair(): void
    {
        $siteA = Site::factory()->create(['type' => 'house']);
        $siteB = Site::factory()->create(['type' => 'house']);
        $clientA = Client::factory()->create(['site_id' => $siteA->id, 'status' => 'active']);
        $globalOperator = $this->userWithPermissions([
            'controlRoom.alerts.create',
            'reports.viewAny',
        ]);

        $this->actingAs($globalOperator)
            ->postJson('/control-room/alerts', [
                'source' => 'manual',
                'alert_type' => 'welfare_check',
                'severity' => 'high',
                'client_id' => $clientA->id,
                'site_id' => $siteB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_id');

        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_manual_alert_rejects_foreign_asset_and_signal_references_even_when_the_alert_tuple_is_local(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 1, 'type' => 'house']);
        $foreignSite = Site::factory()->create(['tenant_id' => 2, 'type' => 'house']);
        $localClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $localSite->id,
            'status' => 'active',
        ]);
        $foreignAsset = Asset::factory()->forSite($foreignSite)->create();
        $foreignSignal = FleetSignal::query()->create([
            'asset_id' => $foreignAsset->id,
            'signal_type' => 'geofence_breach',
            'severity_hint' => 'high',
            'occurred_at' => now(),
            'idempotency_key' => 'foreign-alert-create-signal',
            'payload' => ['latitude' => -36.8485, 'longitude' => 174.7633],
        ]);
        $operator = $this->userWithPermissions([
            'controlRoom.alerts.create',
            'reports.viewAny',
        ]);

        $payload = [
            'source' => 'fleet',
            'alert_type' => 'geofence_breach',
            'severity' => 'high',
            'site_id' => $localSite->id,
            'client_id' => $localClient->id,
        ];

        $this->actingAs($operator)
            ->postJson('/control-room/alerts', $payload + ['asset_id' => $foreignAsset->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('asset_id');

        $this->actingAs($operator)
            ->postJson('/control-room/alerts', $payload + ['fleet_signal_id' => $foreignSignal->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fleet_signal_id');

        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_site_bound_operator_cannot_create_an_alert_from_an_inaccessible_incident_source(): void
    {
        $siteA = Site::factory()->create(['type' => 'house']);
        $siteB = Site::factory()->create(['type' => 'house']);
        $clientB = Client::factory()->create(['site_id' => $siteB->id, 'status' => 'active']);
        $operator = $this->siteBoundUser($siteA, ['controlRoom.alerts.create']);
        $source = $this->clientIncident($clientB, $operator, 'Other site incident');

        $this->actingAs($operator)
            ->post('/control-room/incidents/create-alert', [
                'source_type' => 'client_incident',
                'source_id' => $source->id,
                'severity' => 'high',
                'notes' => 'Attempted cross-site hand-off.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_site_bound_operator_cannot_create_an_alert_from_other_site_medication_or_safeguarding_sources(): void
    {
        $siteA = Site::factory()->create(['type' => 'house']);
        $siteB = Site::factory()->create(['type' => 'house']);
        $clientB = Client::factory()->create(['site_id' => $siteB->id, 'status' => 'active']);
        $operator = $this->siteBoundUser($siteA, [
            'controlRoom.alerts.create',
            'safeguarding.viewAny',
        ]);
        $medication = $this->medicationError($clientB, $operator, 'MED-TEST-HIDDEN-SOURCE');
        $safeguarding = SafeguardingConcern::withoutEvents(fn () => SafeguardingConcern::factory()->create([
            'reference_number' => 'SG-TEST-HIDDEN-SOURCE',
            'site_id' => $siteB->id,
            'is_sensitive' => false,
            'reported_by_user_id' => $operator->id,
        ]));

        $this->actingAs($operator)
            ->post('/control-room/incidents/create-alert', [
                'source_type' => 'medication_error',
                'source_id' => $medication->id,
                'severity' => 'high',
                'notes' => 'Attempted other-site medication hand-off.',
            ])
            ->assertForbidden();

        $this->actingAs($operator)
            ->post('/control-room/incidents/create-alert', [
                'source_type' => 'safeguarding',
                'source_id' => $safeguarding->id,
                'severity' => 'high',
                'notes' => 'Attempted other-site safeguarding hand-off.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_site_bound_operator_cannot_hand_off_a_restricted_safeguarding_source(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $operator = $this->siteBoundUser($site, [
            'controlRoom.alerts.create',
            'safeguarding.viewAny',
        ]);
        $source = SafeguardingConcern::withoutEvents(fn () => SafeguardingConcern::factory()->create([
            'reference_number' => 'SG-TEST-RESTRICTED-SOURCE',
            'site_id' => $site->id,
            'is_sensitive' => true,
            'reported_by_user_id' => User::factory()->create()->id,
        ]));

        $this->actingAs($operator)
            ->post('/control-room/incidents/create-alert', [
                'source_type' => 'safeguarding',
                'source_id' => $source->id,
                'severity' => 'high',
                'notes' => 'Attempted restricted hand-off.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_site_bound_operator_cannot_flag_an_incident_for_an_inaccessible_client(): void
    {
        $siteA = Site::factory()->create(['type' => 'house']);
        $siteB = Site::factory()->create(['type' => 'house']);
        $clientB = Client::factory()->create(['site_id' => $siteB->id, 'status' => 'active']);
        $operator = $this->siteBoundUser($siteA, ['controlRoom.alerts.create']);

        $this->actingAs($operator)
            ->post('/control-room/incidents/flag', [
                'client_id' => $clientB->id,
                'type' => 'fall',
                'severity' => 'high',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('client_incidents', [
            'client_id' => $clientB->id,
            'source' => 'control_room',
        ]);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_global_operator_retains_cross_site_access(): void
    {
        $siteA = Site::factory()->create(['type' => 'house']);
        $siteB = Site::factory()->create(['type' => 'house']);
        $clientA = Client::factory()->create(['site_id' => $siteA->id, 'status' => 'active']);
        $clientB = Client::factory()->create(['site_id' => $siteB->id, 'status' => 'active']);
        $globalOperator = $this->userWithPermissions([
            'controlRoom.viewAny',
            'controlRoom.alerts.create',
            'reports.viewAny',
        ]);

        $this->clientIncident($clientA, $globalOperator, 'Site A incident');
        $this->clientIncident($clientB, $globalOperator, 'Site B incident');
        $queue = TriageQueue::query()->create([
            'name' => 'Global journey queue',
            'code' => 'global_journey_queue',
            'tier' => 1,
            'is_active' => true,
        ]);
        ControlRoomAlert::factory()->open()->create([
            'queue_id' => $queue->id,
            'site_id' => $siteA->id,
            'client_id' => $clientA->id,
        ]);
        ControlRoomAlert::factory()->open()->create([
            'queue_id' => $queue->id,
            'site_id' => $siteB->id,
            'client_id' => $clientB->id,
        ]);

        $this->actingAs($globalOperator)
            ->get('/control-room/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('incidents.data', 2)
                ->has('sites', 2)
                ->has('clients', 2)
            );

        $this->actingAs($globalOperator)
            ->get('/control-room/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts.data', 2)
                ->has('queues', 1)
                ->where('queues.0.active_alerts', 2)
            );
    }

    private function clientIncident(Client $client, User $reporter, string $title): ClientIncident
    {
        return ClientIncident::withoutEvents(fn () => ClientIncident::query()->create([
            'client_id' => $client->id,
            'reported_by' => $reporter->id,
            'title' => $title,
            'type' => 'injury',
            'severity' => 'high',
            'status' => 'submitted',
            'occurred_at' => now()->subHour(),
            'description' => $title,
        ]));
    }

    private function medicationError(Client $client, User $reporter, string $reference): MedicationError
    {
        return MedicationError::withoutEvents(fn () => MedicationError::query()->create([
            'reference_number' => $reference,
            'client_id' => $client->id,
            'error_type' => 'wrong_dose',
            'severity' => 'major',
            'description' => 'Medication error at another site.',
            'reported_by' => $reporter->id,
            'reported_at' => now()->subHour(),
            'status' => 'reported',
        ]));
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = $this->userWithPermissions($permissionKeys);

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function userWithPermissions(array $permissionKeys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync($permissions->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]));

        return $user;
    }
}
