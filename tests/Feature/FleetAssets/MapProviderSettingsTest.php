<?php

namespace Tests\Feature\FleetAssets;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use App\Services\Fleet\FleetMapSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        foreach (['fleet.viewAny', 'assets.viewAny', 'fleet.settings.manage'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Test']);
            $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }
        $this->actingAs($actor);
        Http::preventStrayRequests();

        return $actor;
    }

    private function values(): array
    {
        return ['google' => true, 'project' => 'synthetic-project', 'display' => true, 'places' => false,
            'geocoding' => false, 'routes' => false, 'restrictions_reviewed' => true, 'terms_reviewed' => true];
    }

    public function test_setup_reports_missing_keys_at_their_fields_without_saving_or_calling_google(): void
    {
        $this->manager();
        config(['fleet.maps.api_key' => '', 'fleet.maps.google_server_key' => '']);
        $snapshot = app(FleetMapSettings::class)->snapshot();
        $this->putJson('/fleet-assets/settings/maps', ['revision' => $snapshot['revision'], 'values' => [...$this->values(), 'places' => true]])
            ->assertUnprocessable()->assertJsonValidationErrors(['values.google', 'values.places']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'fleet.settings.maps.updated']);
        $this->assertFalse(app(FleetMapSettings::class)->values()['google']);
        Http::assertNothingSent();
    }

    public function test_display_only_saves_without_a_server_key_and_other_capabilities_remain_off(): void
    {
        $this->manager();
        config(['fleet.maps.api_key' => 'synthetic-browser', 'fleet.maps.google_server_key' => '']);
        $maps = app(FleetMapSettings::class);
        $saved = $this->putJson('/fleet-assets/settings/maps', ['revision' => $maps->snapshot()['revision'], 'values' => $this->values()])
            ->assertOk()->assertJsonPath('capabilities.0.status', 'Configured · not verified')
            ->assertJsonPath('capabilities.1.status', 'Not selected')->assertJsonPath('capabilities.0.observation', null)->json();
        $this->getJson('/fleet-assets/settings/maps')->assertOk()->assertJsonPath('revision', $saved['revision']);
        $this->assertSame('google', $maps->browser()['provider']);
        foreach (['places', 'geocoding', 'routes'] as $capability) {
            $this->assertFalse($maps->enabled($capability));
        }
        Http::assertNothingSent();
    }

    public function test_loss_or_rotation_of_a_key_changes_revision_and_truthful_fallback_status(): void
    {
        $this->manager();
        config(['fleet.maps.api_key' => 'synthetic-browser', 'fleet.maps.google_server_key' => 'synthetic-server']);
        AppSetting::create(['key' => FleetMapSettings::KEY, 'value' => [...$this->values(), 'places' => true]]);
        $maps = app(FleetMapSettings::class);
        $before = $maps->snapshot();
        config(['fleet.maps.api_key' => '']);
        $after = $maps->snapshot();
        $this->assertNotSame($before['revision'], $after['revision']);
        $this->assertSame('Credential missing', $after['capabilities'][0]['status']);
        $this->assertSame('Google display required', $after['capabilities'][1]['status']);
        $this->assertFalse($maps->enabled('places'));
        $this->assertSame('osm', $maps->browser()['provider']);
        $this->assertNull($maps->browser()['apiKey']);
        $this->putJson('/fleet-assets/settings/maps', ['revision' => $before['revision'], 'values' => $this->values()])->assertConflict();
        $this->postJson('/fleet-assets/settings/map-capabilities/places', ['q' => 'Any public address'])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_key_references_and_observations_contain_no_secret_query_or_coordinate_data(): void
    {
        $this->manager();
        config(['fleet.maps.api_key' => 'synthetic-browser-private', 'fleet.maps.google_server_key' => 'synthetic-server-private']);
        AppSetting::create(['key' => FleetMapSettings::KEY, 'value' => [...$this->values(), 'places' => true]]);
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => [['id' => 'synthetic-place', 'formattedAddress' => 'Anywhere public address', 'location' => ['latitude' => -40.12, 'longitude' => 173.12]]]])]);
        $this->postJson('/fleet-assets/settings/map-capabilities/places', ['q' => 'An address outside registered sites', 'client_id' => 999999])->assertOk()->assertJsonCount(1, 'results');
        Http::assertSent(fn ($request) => $request['textQuery'] === 'An address outside registered sites' && ! isset($request['client_id']));
        $snapshot = $this->getJson('/fleet-assets/settings/maps')->assertOk()->assertJsonPath('capabilities.1.observation.status', 'succeeded')->json();
        $safe = json_encode($snapshot).AuditLog::where('action', 'fleet.settings.maps.updated')->get()->toJson();
        foreach (['synthetic-browser-private', 'synthetic-server-private', 'Anywhere public address', 'An address outside', '-40.12', '173.12'] as $private) {
            $this->assertStringNotContainsString($private, $safe);
        }
        config(['fleet.maps.google_server_key' => 'synthetic-server-rotated']);
        $this->assertNull(app(FleetMapSettings::class)->snapshot()['capabilities'][1]['observation']);
    }

    public function test_quota_and_rejection_are_observations_not_healthy_configuration(): void
    {
        $this->manager();
        config(['fleet.maps.api_key' => 'synthetic-browser', 'fleet.maps.google_server_key' => 'synthetic-server']);
        AppSetting::create(['key' => FleetMapSettings::KEY, 'value' => [...$this->values(), 'places' => true, 'geocoding' => true]]);
        Http::fake(['places.googleapis.com/*' => Http::response([], 429), 'maps.googleapis.com/*' => Http::response(['status' => 'REQUEST_DENIED'])]);
        $this->postJson('/fleet-assets/settings/map-capabilities/places', ['q' => 'Public library'])->assertStatus(503)->assertHeader('Retry-After', '60');
        $this->postJson('/fleet-assets/settings/map-capabilities/places', ['q' => 'Public library'])->assertStatus(503);
        $this->postJson('/fleet-assets/settings/map-capabilities/geocoding', ['lat' => -41, 'lng' => 174])->assertStatus(503);
        $snapshot = app(FleetMapSettings::class)->snapshot();
        $this->assertSame('quota', $snapshot['capabilities'][1]['observation']['status']);
        $this->assertSame('rejected', $snapshot['capabilities'][2]['observation']['status']);
        $this->assertSame('Configured · not verified', $snapshot['capabilities'][1]['status']);
        $this->assertNull($snapshot['capabilities'][1]['observation']['last_success_at']);
        $this->assertDatabaseCount('fleet_map_usage_logs', 0);
        Http::assertSentCount(2);
        $this->travel(25)->hours();
        $this->assertNull(Cache::get(app(FleetMapSettings::class)->observationKey($snapshot['revision'], 'places')));
    }

    public function test_settings_reject_secrets_and_google_can_be_disabled_after_credentials_are_removed(): void
    {
        $this->manager();
        $maps = app(FleetMapSettings::class);
        $this->putJson('/fleet-assets/settings/maps', ['revision' => $maps->snapshot()['revision'], 'values' => [...$this->values(), 'server_key' => 'do-not-store']])->assertUnprocessable();
        AppSetting::create(['key' => FleetMapSettings::KEY, 'value' => $this->values()]);
        config(['fleet.maps.api_key' => '', 'fleet.maps.google_server_key' => '']);
        $this->putJson('/fleet-assets/settings/maps', ['revision' => $maps->snapshot()['revision'], 'values' => [...$this->values(), 'google' => false]])->assertOk()->assertJsonPath('capabilities.0.status', 'Google is off');
        $this->assertSame('osm', $maps->browser()['provider']);
        Http::assertNothingSent();
    }
}
