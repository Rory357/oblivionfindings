<?php

// Main-owned synthetic review probes; execute from the exact submitted candidate.
final class OPSPL01MainBoundaryTest extends \Tests\TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function configureIsolatedTestingDatabase(): void
    {
        static::$testDatabaseBaseName = 'of_main_people_review_20260927';
        parent::configureIsolatedTestingDatabase();
    }

    protected function pruneStaleIsolatedDatabases(PDO $pdo, string $baseName, string $currentDatabase): void {}

    protected function clearTestingMaintenanceMode(): void {}

    protected function loadSchemaDumpIntoTestingDatabase(string $host, string $port, string $username, string $password, string $database): bool
    {
        return $this->loadSchemaDumpViaPdo(getcwd().'/database/schema/mysql-schema.sql', $host, $port, $username, $password, $database);
    }

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Queue::fake();
    }

    private function grant(\App\Models\User $actor, string $key): \App\Models\Permission
    {
        $permission = \App\Models\Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Test']);
        $actor->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);
        $actor->unsetRelation('roles');

        return $permission;
    }

    public function test_control_room_permission_revoked_during_observation_read_withholds_alerts(): void
    {
        extract(\Tests\Support\ClientLocationWorkspaceFixture::make());
        $permission = $this->grant($actor, 'controlRoom.alerts.view');
        $role = $actor->roles()->first();
        \App\Models\ControlRoomAlert::create([
            'source' => 'personal_tracker', 'alert_type' => 'fall_detected', 'severity' => 'critical',
            'status' => 'open', 'site_id' => $site->id, 'client_id' => $client->id,
            'reference_number' => 'MAIN-PL-REVOKED', 'context' => ['normalized_data' => ['client_id' => $client->id]],
            'triggered_at' => now(),
        ]);
        $this->mock(\App\Services\Integration\IntegrationEventHistoryService::class)
            ->shouldReceive('forDeviceWindow')->andReturnUsing(function () use ($role, $permission) {
                $role->permissions()->detach($permission->id);

                return ['positions' => collect(), 'truncated' => false];
            });

        $response = $this->actingAs($actor->fresh())->getJson('/operations/people-locations/alerts');
        $this->assertFalse($actor->fresh()->canDo('controlRoom.alerts.view'));
        $response->assertOk()->assertJsonCount(1, 'people')->assertJsonCount(0, 'alerts')
            ->assertJsonPath('canReadAlerts', false)->assertDontSee('MAIN-PL-REVOKED');
    }

    public function test_transport_permission_revoked_during_report_read_withholds_journey(): void
    {
        extract(\Tests\Support\ClientLocationWorkspaceFixture::make());
        $this->grant($actor, 'people_locations.history.view');
        $permission = $this->grant($actor, 'fleet.viewAny');
        $role = $actor->roles()->first();
        $asset = \App\Models\Asset::factory()->create(['category' => 'vehicle', 'site_id' => $site->id]);
        $journey = \App\Models\FleetResidentTransport::create([
            'asset_id' => $asset->id, 'resident_id' => $client->id, 'resident_name' => $client->full_name,
            'site_id' => $site->id, 'driver_user_id' => $actor->id, 'transport_type' => 'medical',
            'departed_at' => now()->subMinutes(40), 'arrived_at' => now()->subMinutes(20),
            'dropoff_location' => 'Main synthetic restricted destination',
            'status' => 'completed', 'journey_uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $this->mock(\App\Services\Integration\IntegrationEventHistoryService::class)
            ->shouldReceive('forDeviceWindow')->andReturnUsing(function () use ($role, $permission) {
                $role->permissions()->detach($permission->id);

                return ['positions' => collect(), 'truncated' => false];
            });

        $response = $this->actingAs($actor->fresh())->getJson('/operations/people-locations/report-preview?'.http_build_query([
            'person' => 'c'.$client->id, 'source' => $assignment->id,
            'date' => now('Pacific/Auckland')->toDateString(), 'journey' => $journey->id,
        ]));
        $this->assertFalse($actor->fresh()->canDo('fleet.viewAny'));
        $this->assertContains($response->status(), [403, 404, 409], 'A revoked Transport reader must not receive the previously selected journey.');
        $response->assertDontSee('Main synthetic restricted destination');
    }
}
