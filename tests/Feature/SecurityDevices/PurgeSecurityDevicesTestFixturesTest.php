<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\ItCatalogItem;
use App\Models\ItSecurityDesktopReleaseFixturePack;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeSecurityDevicesTestFixturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_requires_explicit_dry_run_or_force_flag(): void
    {
        $this->artisan('security-devices:purge-test-fixtures')
            ->assertFailed();
    }

    public function test_dry_run_and_force_remove_test_fixtures_safely_while_preserving_real_devices_and_relationship_triggers(): void
    {
        $actor = User::factory()->create(['approved_at' => now()]);

        $realSite = Site::factory()->create(['name' => 'Kōwhai House', 'is_active' => true]);
        $testSiteAlpha = Site::factory()->create(['name' => 'RELEASE Site Alpha', 'is_active' => true]);
        $testSiteDemo = Site::factory()->create(['name' => 'Demo Location Site', 'is_active' => true]);

        $realUx7 = Device::factory()->create([
            'device_uid' => 'NET-UX7-001',
            'name' => 'UX7',
            'provider' => 'unifi',
        ]);
        $realUnif = Device::factory()->create([
            'device_uid' => 'NET-UNIF-095',
            'name' => 'unif',
            'provider' => 'unifi',
        ]);
        $realTracker = Device::factory()->create([
            'device_uid' => 'TRK-CARE-002',
            'name' => 'Care tracker 867963069916998',
            'provider' => 'queclink',
        ]);

        DeviceAssignment::create([
            'device_id' => $realUx7->id,
            'assignable_type' => 'site',
            'assignable_id' => $realSite->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $actor->id,
        ]);

        $fixtureUiAlignment = Device::factory()->create([
            'device_uid' => 'CCT-BFTN2GEX',
            'name' => 'UI Alignment Test Device',
            'provider' => 'hikvision',
        ]);
        $fixtureDemoTracker = Device::factory()->create([
            'device_uid' => 'DEMO-LOCATION-20260922',
            'name' => 'Demo Location Tracker (simulated)',
            'provider' => 'manual',
        ]);
        $fixtureAlphaCamera = Device::factory()->create([
            'device_uid' => 'CCT-REL-ALPHA-78',
            'name' => 'RELEASE Alpha Camera',
            'provider' => 'hikvision',
        ]);
        $fixtureV10Router = Device::factory()->create([
            'device_uid' => 'NET-REL-V10-84',
            'name' => 'RELEASE V10 Alpha Router',
            'provider' => 'unifi',
        ]);

        DeviceAssignment::create([
            'device_id' => $fixtureAlphaCamera->id,
            'assignable_type' => 'site',
            'assignable_id' => $testSiteAlpha->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $actor->id,
        ]);
        DeviceAssignment::create([
            'device_id' => $fixtureDemoTracker->id,
            'assignable_type' => 'site',
            'assignable_id' => $testSiteDemo->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $actor->id,
        ]);

        $relationshipId = DB::table('device_relationships')->insertGetId([
            'parent_device_id' => $realUnif->id,
            'child_device_id' => $fixtureAlphaCamera->id,
            'relationship_type' => 'connected_to',
            'port' => 'Port 4',
            'notes' => 'Test link',
            'created_by_user_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manifestRecords = [
            ['type' => 'site', 'id' => $testSiteAlpha->id],
            ['type' => 'device', 'id' => $fixtureAlphaCamera->id],
            ['type' => 'device', 'id' => $fixtureV10Router->id],
        ];
        $manifest = ['schema_version' => 1, 'records' => $manifestRecords, 'files' => []];

        $pack = ItSecurityDesktopReleaseFixturePack::query()->create([
            'pack_key' => 'it-security-desktop-release-v10',
            'release_revision' => str_repeat('a', 40),
            'state' => 'ready',
            'manifest' => $manifest,
            'manifest_sha256' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)),
            'prepared_at' => now(),
        ]);

        // 1. Dry-run leaves everything untouched.
        $this->artisan('security-devices:purge-test-fixtures --dry-run')
            ->assertSuccessful();

        $this->assertDatabaseHas('devices', ['id' => $fixtureUiAlignment->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('it_security_desktop_release_fixture_packs', ['id' => $pack->id]);
        $this->assertDatabaseHas('device_relationships', ['id' => $relationshipId, 'unlinked_at' => null]);

        // 2. Force executes cleanly inside a transaction.
        $this->artisan('security-devices:purge-test-fixtures --force')
            ->assertSuccessful();

        // Real devices and real Site remain active and untouched.
        $this->assertDatabaseHas('devices', ['id' => $realUx7->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('devices', ['id' => $realUnif->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('devices', ['id' => $realTracker->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('sites', ['id' => $realSite->id, 'deleted_at' => null]);

        // Standalone and unreferenced pack fixture devices are hard-deleted.
        $this->assertDatabaseMissing('devices', ['id' => $fixtureUiAlignment->id]);
        $this->assertDatabaseMissing('devices', ['id' => $fixtureDemoTracker->id]);
        $this->assertDatabaseMissing('devices', ['id' => $fixtureV10Router->id]);

        // Relationship-referenced fixture device is unlinked and soft-deleted (decommissioned),
        // disappearing from active Device queries while preserving immutable relationship history.
        $relationship = DB::table('device_relationships')->where('id', $relationshipId)->first();
        $this->assertNotNull($relationship);
        $this->assertNotNull($relationship->unlinked_at);
        $this->assertSame($actor->id, (int) $relationship->unlinked_by_user_id);
        $this->assertSame('Removed test fixture', $relationship->unlink_reason);

        $this->assertNull(Device::find($fixtureAlphaCamera->id));
        $softDeletedCamera = Device::withTrashed()->find($fixtureAlphaCamera->id);
        $this->assertNotNull($softDeletedCamera);
        $this->assertNotNull($softDeletedCamera->deleted_at);
        $this->assertSame(DeviceStatus::Decommissioned, $softDeletedCamera->status);

        // Test Sites and fixture pack row are removed.
        $this->assertDatabaseMissing('sites', ['id' => $testSiteAlpha->id]);
        $this->assertDatabaseMissing('sites', ['id' => $testSiteDemo->id]);
        $this->assertDatabaseMissing('it_security_desktop_release_fixture_packs', ['id' => $pack->id]);

        // 3. Re-running --dry-run is idempotent and reports zero remaining fixtures.
        $this->artisan('security-devices:purge-test-fixtures --dry-run')
            ->expectsOutputToContain('Test fixture devices to remove (0)')
            ->expectsOutputToContain('Test Sites to remove (0)')
            ->expectsOutputToContain('Real devices preserved (3)')
            ->assertSuccessful();
    }

    public function test_purge_handles_evidence_snapshots_catalog_versions_and_restricted_foreign_keys_safely(): void
    {
        $realAdmin = User::factory()->create(['approved_at' => now()]);
        $fixtureUser = User::factory()->create([
            'email' => 'release-v10-worker@acceptance.invalid',
            'approved_at' => now(),
        ]);

        $restrictedSite = Site::factory()->create(['name' => 'RELEASE V10 Site Alpha', 'is_active' => true]);
        $fixtureDevice = Device::factory()->create([
            'device_uid' => 'CCT-REL-V10-SNAP',
            'name' => 'RELEASE V10 Alpha Camera',
            'provider' => 'manual',
        ]);

        $eventId = DB::table('device_events')->insertGetId([
            'device_id' => $fixtureDevice->id,
            'event_type' => 'offline',
            'severity' => 'warn',
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $ticket = ItTicket::factory()->create([
            'requester_user_id' => $fixtureUser->id,
        ]);

        DB::table('monitoring_incident_evidence_snapshots')->insert([
            'control_room_alert_id' => null,
            'it_ticket_id' => $ticket->id,
            'device_id' => $fixtureDevice->id,
            'device_event_id' => $eventId,
            'site_id' => $restrictedSite->id,
            'evidence_version' => 1,
            'captured_at' => now(),
            'snapshot' => json_encode(['status' => 'offline'], JSON_THROW_ON_ERROR),
            'checksum' => hash('sha256', 'snapshot-1'),
            'created_at' => now(),
        ]);

        $catalogItem = ItCatalogItem::query()->create([
            'name' => 'RELEASE V10 Access Request',
            'slug' => 'release-v10-access-request',
            'category' => 'access',
            'is_published' => true,
            'form_schema' => ['fields' => []],
        ]);
        $catalogItemId = (int) $catalogItem->id;
        $catalogVersionId = (int) $catalogItem->fresh()->published_version_id;
        $this->assertGreaterThan(0, $catalogVersionId);

        // Create external RESTRICT foreign keys to both $fixtureUser and $restrictedSite.
        DB::table('monitoring_collector_enrollments')->insert([
            'site_id' => $restrictedSite->id,
            'issued_by_user_id' => $fixtureUser->id,
            'token_hash' => hash('sha256', 'tok-1'),
            'expires_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manifest = [
            'schema_version' => 1,
            'records' => [
                ['type' => 'site', 'id' => $restrictedSite->id],
                ['type' => 'user', 'id' => $fixtureUser->id],
                ['type' => 'device', 'id' => $fixtureDevice->id],
                ['type' => 'it_catalog_item', 'id' => $catalogItemId],
                ['type' => 'it_ticket', 'id' => $ticket->id],
            ],
            'files' => [],
        ];

        $pack = ItSecurityDesktopReleaseFixturePack::query()->create([
            'pack_key' => 'it-security-desktop-release-v10',
            'release_revision' => str_repeat('b', 40),
            'state' => 'ready',
            'manifest' => $manifest,
            'manifest_sha256' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)),
            'prepared_at' => now(),
        ]);

        $this->assertNotNull($realAdmin->id);

        $this->artisan('security-devices:purge-test-fixtures --force')
            ->assertSuccessful();

        $this->assertDatabaseMissing('devices', ['id' => $fixtureDevice->id]);
        $this->assertDatabaseMissing('it_catalog_items', ['id' => $catalogItemId]);
        $this->assertDatabaseMissing('it_catalog_versions', ['id' => $catalogVersionId]);
        $this->assertDatabaseMissing('it_security_desktop_release_fixture_packs', ['id' => $pack->id]);

        // Restricted user is unapproved rather than violating RESTRICT FK.
        $this->assertNull(User::find($fixtureUser->id)?->approved_at);

        // Restricted Site is deactivated and soft-deleted rather than violating RESTRICT FK.
        $this->assertNull(Site::find($restrictedSite->id));
        $trashedSite = Site::withTrashed()->find($restrictedSite->id);
        $this->assertNotNull($trashedSite);
        $this->assertFalse((bool) $trashedSite->is_active);
        $this->assertNotNull($trashedSite->deleted_at);
    }
}
