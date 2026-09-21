<?php

use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientGeofenceRule;
use App\Models\ClientGeofenceRuleVersion;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ClientLocationWorkspaceFixture;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Notification::fake();
    Mail::fake();
});

it('persists and reloads an inactive private draft without operational effects and replays one version', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $counts = collect(['asset_geofences', 'asset_geofence_assignments', 'fleet_geofence_states', 'device_assignments', 'control_room_alerts', 'fleet_signals', 'device_command_requests', 'jobs', 'notifications'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
    $url = "/operations/clients/{$client->id}/location/zones";
    $first = $this->actingAs($actor)->postJson($url, $payload)->assertCreated()->assertJsonPath('zone.status', 'draft')->assertJsonPath('zone.revision', 1);
    $this->postJson($url, $payload)->assertCreated()->assertJsonPath('zone.id', $first->json('zone.id'));
    $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertJsonPath('zones.0.geometry.radius_m', 80);
    expect(ClientGeofenceRule::count())->toBe(1)->and(ClientGeofenceRuleVersion::count())->toBe(1);
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('rejects changed replay payloads and stale edits without new revisions', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $url = "/operations/clients/{$client->id}/location/zones";
    $id = $this->actingAs($actor)->postJson($url, $payload)->assertCreated()->json('zone.id');
    $this->postJson($url, [...$payload, 'name' => 'Changed'])->assertConflict();
    $edit = [...$payload, 'expected_revision' => 1, 'idempotency_key' => 'synthetic-draft-edit-001', 'name' => 'Updated visit'];
    $this->putJson("{$url}/{$id}", $edit)->assertOk()->assertJsonPath('zone.revision', 2);
    $this->putJson("{$url}/{$id}", [...$edit, 'idempotency_key' => 'synthetic-draft-edit-002'])->assertConflict();
    expect(ClientGeofenceRuleVersion::count())->toBe(2);
});

it('denies a viewer without management and unrelated client or site records', function () {
    extract(ClientLocationWorkspaceFixture::make(false));
    $url = "/operations/clients/{$client->id}/location/zones";
    $this->actingAs($actor)->getJson($url)->assertOk();
    $this->postJson($url, $payload)->assertForbidden();
    $other = ClientLocationWorkspaceFixture::make();
    $this->getJson("/operations/clients/{$other['client']->id}/location/zones")->assertForbidden();
    expect(ClientGeofenceRule::count())->toBe(0);
});

it('rejects crossing and degenerate boundaries and inconsistent schedules', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $url = "/operations/clients/{$client->id}/location/zones";
    $this->actingAs($actor);
    foreach ([[[0, 0], [1, 1], [0, 1], [1, 0]], [[0, 0], [1, 1], [2, 2]], [[0, 0], [1, 1]]] as $corners) {
        $this->postJson($url, [...$payload, 'geometry' => ['type' => 'polygon', 'coordinates' => array_map(fn ($p) => ['lat' => $p[0], 'lng' => $p[1]], $corners)]])->assertUnprocessable();
    }
    $this->postJson($url, [...$payload, 'geometry' => [...$payload['geometry'], 'radius_m' => 0]])->assertUnprocessable();
    $this->postJson($url, [...$payload, 'schedule' => [...$payload['schedule'], 'end' => '08:00']])->assertUnprocessable();
    $this->postJson($url, [...$payload, 'schedule' => [...$payload['schedule'], 'exception_dates' => ['2026-09-26']]])->assertUnprocessable();
    $this->postJson($url, [...$payload, 'active' => true])->assertUnprocessable();
    expect(ClientGeofenceRule::count())->toBe(0);
});

it('accepts a valid polygon and explicit overnight schedule as an inactive proposal', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $this->actingAs($actor)->postJson("/operations/clients/{$client->id}/location/zones", [...$payload,
        'geometry' => ['type' => 'polygon', 'coordinates' => [['lat' => -36.85, 'lng' => 174.76], ['lat' => -36.851, 'lng' => 174.76], ['lat' => -36.85, 'lng' => 174.761]]],
        'schedule' => [...$payload['schedule'], 'start' => '20:00', 'end' => '07:00', 'following_day' => true],
    ])->assertCreated()->assertJsonPath('zone.status', 'draft');
});

it('rejects forged and changed canonical boundaries without publishing geometry', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $fence = AssetGeofence::create(['site_id' => $site->id, 'name' => 'Site boundary', 'scope' => 'house', 'type' => 'circle', 'shape' => ['lat' => -36.85, 'lng' => 174.76, 'radius_m' => 90], 'is_active' => true]);
    $url = "/operations/clients/{$client->id}/location/zones";
    $hash = $this->actingAs($actor)->getJson($url)->assertOk()->json('boundaries.0.hash');
    unset($payload['geometry']);
    $linked = [...$payload, 'geometry_source' => 'canonical', 'canonical_geofence_id' => $fence->id, 'canonical_geometry_hash' => $hash];
    $fence->update(['shape' => ['lat' => -36.85, 'lng' => 174.76, 'radius_m' => 100]]);
    $this->postJson($url, $linked)->assertConflict();
    $fence->update(['site_id' => Site::factory()->create()->id]);
    $this->postJson($url, $linked)->assertNotFound();
    expect(ClientGeofenceRule::count())->toBe(0)->and(AssetGeofence::count())->toBe(1);
});

it('does not disclose another client boundary even at the same site', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $otherClient = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    $asset = Asset::factory()->create(['site_id' => $site->id, 'client_id' => $otherClient->id]);
    $fence = AssetGeofence::create(['site_id' => $site->id, 'asset_id' => $asset->id, 'name' => 'Other private place', 'scope' => 'resident', 'type' => 'circle', 'shape' => ['lat' => -36.85, 'lng' => 174.76, 'radius_m' => 80], 'is_active' => true]);
    $url = "/operations/clients/{$client->id}/location/zones";
    $this->actingAs($actor)->getJson($url)->assertOk()->assertJsonCount(0, 'boundaries');
    $client->update(['house_geofence_id' => $fence->id]);
    $this->get(route('operations.clients.show', $client, false))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('location.geofences', 0));
    unset($payload['geometry']);
    $this->postJson($url, [...$payload, 'geometry_source' => 'canonical', 'canonical_geofence_id' => $fence->id, 'canonical_geometry_hash' => str_repeat('0', 64)])->assertNotFound();
    $fence->update(['asset_id' => null]);
    $fence->assignedAssets()->attach($asset->id);
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'boundaries');
    $client->update(['house_geofence_id' => null]);
    $this->get(route('operations.clients.show', $client, false))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('location.geofences', 0));
});

it('requires explicit review before replacing a linked draft source', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $fence = AssetGeofence::create(['site_id' => $site->id, 'name' => 'Agreed boundary', 'scope' => 'house', 'type' => 'circle', 'shape' => ['lat' => -36.85, 'lng' => 174.76, 'radius_m' => 90], 'is_active' => true]);
    $url = "/operations/clients/{$client->id}/location/zones";
    $hash = $this->actingAs($actor)->getJson($url)->json('boundaries.0.hash');
    $linked = [...$payload, 'geometry_source' => 'canonical', 'canonical_geofence_id' => $fence->id, 'canonical_geometry_hash' => $hash];
    unset($linked['geometry']);
    $id = $this->postJson($url, $linked)->assertCreated()->json('zone.id');
    $fence->update(['shape' => ['lat' => -36.85, 'lng' => 174.76, 'radius_m' => 110]]);
    $this->putJson($url.'/'.$id, [...$linked, 'expected_revision' => 1])->assertConflict();
    $custom = [...$payload, 'expected_revision' => 1, 'name' => 'Name-only edit'];
    $this->putJson($url.'/'.$id, $custom)->assertConflict();
    $newHash = $this->getJson($url)->json('boundaries.0.hash');
    $this->putJson($url.'/'.$id, [...$linked, 'expected_revision' => 1, 'canonical_geometry_hash' => $newHash])->assertConflict();
    expect(ClientGeofenceRuleVersion::count())->toBe(1);
    $fence->update(['is_active' => false]);
    $this->putJson($url.'/'.$id, $custom)->assertConflict();
    $this->putJson($url.'/'.$id, [...$custom, 'source_change_reviewed' => true])->assertOk()->assertJsonPath('zone.revision', 2)->assertJsonPath('zone.geometry_source', 'custom');
    expect(ClientGeofenceRuleVersion::count())->toBe(2)->and($fence->fresh()->shape['radius_m'])->toBe(110);
});

it('rolls back private records if the audit cannot be written', function () {
    extract(ClientLocationWorkspaceFixture::make());
    AuditLog::creating(function ($entry) {
        if ($entry->action === 'client.location.zone_draft.saved') {
            throw new RuntimeException('Synthetic audit failure');
        }
    });
    $this->actingAs($actor)->postJson("/operations/clients/{$client->id}/location/zones", $payload)->assertStatus(500);
    expect(ClientGeofenceRule::count())->toBe(0)->and(ClientGeofenceRuleVersion::count())->toBe(0);
});

it('rejects an ended assignment on both save and read', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $assignment->update(['collection_stopped_at' => now()]);
    $url = "/operations/clients/{$client->id}/location/zones";
    $this->actingAs($actor)->postJson($url, $payload)->assertForbidden();
    $this->getJson($url)->assertForbidden();
    expect(ClientGeofenceRule::count())->toBe(0);
});

it('rejects a mismatched privacy identity without saving and retains populated migration evidence', function () {
    extract(ClientLocationWorkspaceFixture::make());
    $url = "/operations/clients/{$client->id}/location/zones";
    $this->actingAs($actor)->postJson($url, [...$payload, 'access_fingerprint' => str_repeat('0', 64)])->assertForbidden();
    expect(ClientGeofenceRule::count())->toBe(0);
    $this->postJson($url, $payload)->assertCreated();
    $migration = require database_path('migrations/2026_09_21_000001_create_client_geofence_rule_drafts.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Client zone evidence exists');
    expect(ClientGeofenceRuleVersion::count())->toBe(1);
});
