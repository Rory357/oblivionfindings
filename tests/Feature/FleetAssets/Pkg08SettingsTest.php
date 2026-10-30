<?php

namespace Tests\Feature\FleetAssets;

use App\Models\AppSetting;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\FleetVehicleBooking;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\Fleet\FleetBookingApprovedNotification;
use App\Notifications\Fleet\FleetBookingRejectedNotification;
use App\Notifications\Fleet\FleetComplianceDueNotification;
use App\Notifications\Fleet\FleetSourceUpdateNotification;
use App\Services\Fleet\FleetMapSettings;
use App\Services\Fleet\FleetNotificationPreferences;
use App\Services\Fleet\ReverseGeocodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Pkg08SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function reader(bool $manage = false): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        foreach (['fleet.viewAny', ...($manage ? ['fleet.settings.manage'] : [])] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Test']);
            $user->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }

        return $user;
    }

    public function test_preferences_persist_only_for_actor_and_reject_stale_or_unknown_writes(): void
    {
        $user = $this->reader();
        $other = $this->reader();
        $this->actingAs($user);
        $snapshot = $this->getJson('/fleet-assets/settings/notification-preferences')->assertOk()->json();
        $data = ['revision' => $snapshot['revision'], 'overrides' => ['fleet.booking_decisions' => ['email' => false]]];
        $saved = $this->putJson('/fleet-assets/settings/notification-preferences', [...$data, 'user_id' => $other->id])->assertOk()->json();
        $this->assertDatabaseHas('user_notification_preferences', ['user_id' => $user->id, 'key' => 'fleet.booking_decisions', 'channel_email' => false]);
        $this->assertDatabaseMissing('user_notification_preferences', ['user_id' => $other->id]);
        $this->assertSame(['email' => false], $this->getJson('/fleet-assets/settings/notification-preferences')->json('overrides')['fleet.booking_decisions']);
        $this->putJson('/fleet-assets/settings/notification-preferences', $data)->assertConflict()->assertJsonPath('latest.revision', $saved['revision']);
        $this->putJson('/fleet-assets/settings/notification-preferences', ['revision' => $saved['revision'], 'overrides' => ['controlRoom.required' => ['inapp' => false]]])->assertUnprocessable();
        $this->putJson('/fleet-assets/settings/notification-preferences', ['revision' => $saved['revision'], 'overrides' => ['fleet.booking_decisions' => ['push' => true]]])->assertUnprocessable();
        $this->putJson('/fleet-assets/settings/notification-preferences', ['revision' => $saved['revision'], 'overrides' => []])->assertOk();
        $this->assertDatabaseMissing('user_notification_preferences', ['user_id' => $user->id]);
    }

    public function test_inherited_channels_follow_current_roles_but_personal_choices_win(): void
    {
        $user = $this->reader();
        $role = Role::create(['name' => 'pkg08-reader', 'label' => 'Settings reader']);
        $user->roles()->attach($role);
        $pref = RoleNotificationPreference::create(['role_id' => $role->id, 'key' => 'fleet.booking_decisions', 'enabled' => true, 'channel_inapp' => true, 'channel_email' => true, 'channel_push' => false]);
        $service = app(FleetNotificationPreferences::class);
        $before = $service->snapshot($user);
        $this->actingAs($user)->putJson('/fleet-assets/settings/notification-preferences', ['revision' => $before['revision'], 'overrides' => ['fleet.booking_decisions' => ['email' => false]]])->assertOk();
        $this->assertSame(['database'], $service->channels($user, 'fleet.booking_decisions'));
        $saved = $service->snapshot($user);
        $pref->update(['channel_inapp' => false]);
        $this->assertSame([], $service->channels($user, 'fleet.booking_decisions'));
        $this->putJson('/fleet-assets/settings/notification-preferences', ['revision' => $saved['revision'], 'overrides' => []])->assertConflict();
    }

    public function test_legacy_settings_writes_replace_channel_inheritance_and_delivery_uses_saved_choices(): void
    {
        $user = $this->reader();
        $service = app(FleetNotificationPreferences::class);
        UserNotificationPreference::create(['user_id' => $user->id, 'key' => 'fleet.booking_decisions', 'enabled' => true, 'channel_inapp' => true, 'channel_email' => false, 'channel_overrides' => ['email' => false]]);
        $this->actingAs($user)->put('/settings/notifications', ['prefs' => ['fleet.booking_decisions' => ['enabled' => true, 'inapp' => false, 'email' => true, 'push' => false]]])->assertRedirect();
        $this->assertSame(['mail'], $service->channels($user, 'fleet.booking_decisions'));
        $this->assertSame(['mail'], (new FleetBookingApprovedNotification(new FleetVehicleBooking))->via($user));
        $this->assertSame(['mail'], (new FleetBookingRejectedNotification(new FleetVehicleBooking))->via($user));
        UserNotificationPreference::create(['user_id' => $user->id, 'key' => 'fleet.maintenance_reminders', 'enabled' => false]);
        $this->assertSame([], (new FleetComplianceDueNotification(new Asset, 'maintenance', null, 'warning'))->via($user));
        $this->assertSame(['database', 'mail'], (new FleetComplianceDueNotification(new Asset, 'wof', null, 'warning'))->via($user));
    }

    public function test_checks_use_saved_state_are_own_only_and_never_send(): void
    {
        Notification::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $user = $this->reader();
        UserNotificationPreference::create(['user_id' => $user->id, 'key' => 'fleet.booking_decisions', 'enabled' => false]);
        $this->actingAs($user)->postJson('/fleet-assets/settings/notification-checks', ['key' => 'fleet.booking_decisions', 'overrides' => ['fleet.booking_decisions' => ['email' => true]]])->assertOk()->assertJsonPath('mode', 'dry_run')->assertJsonPath('email', 'Skipped by your saved preferences.');
        $this->getJson('/fleet-assets/settings/notification-checks')->assertJsonCount(1);
        $this->actingAs($this->reader())->getJson('/fleet-assets/settings/notification-checks')->assertJsonCount(0);
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_missing_source_or_foreign_recipient_cannot_receive_source_updates(): void
    {
        $user = $this->reader();
        $this->assertSame([], (new FleetSourceUpdateNotification('fleet.handover_updates', 99999999))->via($user));
        $this->assertSame([], (new FleetSourceUpdateNotification('fleet.import_results', 99999999))->via($user));
        $this->assertFalse((new FleetSourceUpdateNotification('fleet.import_results', 99999999))->shouldSend($user, 'mail'));
    }

    public function test_map_configuration_is_permission_gated_versioned_and_never_exposes_server_key(): void
    {
        Http::preventStrayRequests();
        config(['fleet.maps.api_key' => 'synthetic-browser-key', 'fleet.maps.google_server_key' => 'synthetic-server-secret']);
        $service = app(FleetMapSettings::class);
        $snapshot = $service->snapshot();
        $values = [...$snapshot['values'], 'google' => true, 'display' => true, 'project' => 'test-project', 'restrictions_reviewed' => true, 'terms_reviewed' => true];
        $this->actingAs($this->reader())->putJson('/fleet-assets/settings/maps', ['revision' => $snapshot['revision'], 'values' => $values])->assertForbidden();
        $this->actingAs($this->reader(true));
        $saved = $this->putJson('/fleet-assets/settings/maps', ['revision' => $snapshot['revision'], 'values' => [...$values, 'google' => '1', 'display' => '1']])->assertOk()->assertJsonPath('values.google', true)->assertJsonPath('values.display', true)->assertDontSee('synthetic-server-secret')->json();
        $this->assertTrue($service->enabled('display'));
        $this->assertFalse($service->enabled('routes'));
        $this->assertSame('synthetic-browser-key', $service->browser()['apiKey']);
        $this->putJson('/fleet-assets/settings/maps', ['revision' => $snapshot['revision'], 'values' => $values])->assertConflict();
        $this->putJson('/fleet-assets/settings/maps', ['revision' => $saved['revision'], 'values' => [...$values, 'google' => false]])->assertOk();
        $this->assertNull($service->browser()['apiKey']);
        $this->assertStringNotContainsString('synthetic-server-secret', AuditLog::where('action', 'fleet.settings.maps.updated')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_unauthorised_user_cannot_read_or_write_workspace_settings(): void
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->actingAs($user);
        foreach (['notification-preferences', 'notification-checks', 'maps', 'history', 'tracking-devices'] as $path) {
            $this->getJson('/fleet-assets/settings/'.$path)->assertForbidden();
        }
        $this->putJson('/fleet-assets/settings/notification-preferences', ['overrides' => []])->assertForbidden();
        $this->postJson('/fleet-assets/settings/notification-checks', ['key' => 'fleet.booking_decisions'])->assertForbidden();
    }

    public function test_google_capabilities_fail_closed_use_separate_credentials_and_do_not_persist_content(): void
    {
        $user = $this->reader(true);
        $this->actingAs($user);
        Http::preventStrayRequests();
        $this->postJson('/fleet-assets/settings/map-capabilities/places', ['q' => 'Public library'])->assertStatus(503);
        Http::assertNothingSent();
        config(['fleet.maps.api_key' => 'synthetic-browser', 'fleet.maps.google_server_key' => 'synthetic-server']);
        AppSetting::create(['key' => FleetMapSettings::KEY, 'value' => ['google' => true, 'display' => true, 'places' => true, 'geocoding' => true, 'routes' => true, 'project' => 'fixture', 'restrictions_reviewed' => true, 'terms_reviewed' => true]]);
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => [['id' => 'place-fixture', 'formattedAddress' => 'Synthetic public library', 'location' => ['latitude' => -41.28, 'longitude' => 174.77]]]])]);
        $this->postJson('/fleet-assets/settings/map-capabilities/places', ['q' => 'Public library', 'user_id' => 999])->assertOk()->assertJsonPath('provider', 'google')->assertJsonPath('results.0.display_name', 'Synthetic public library')->assertDontSee('synthetic-server');
        Http::assertSent(fn ($request) => $request->hasHeader('X-Goog-Api-Key', 'synthetic-server') && $request->hasHeader('X-Goog-FieldMask', 'places.id,places.formattedAddress,places.location,places.attributions') && $request['textQuery'] === 'Public library' && ! isset($request['user_id']));
        $this->assertDatabaseHas('fleet_map_usage_logs', ['user_id' => $user->id, 'context' => 'google_places']);
        $this->postJson('/fleet-assets/settings/map-capabilities/geocoding', ['lat' => 91, 'lng' => 174])->assertUnprocessable();
        $this->postJson('/fleet-assets/settings/map-capabilities/routes', ['origin' => ['lat' => -41, 'lng' => 174], 'destination' => ['lat' => -40, 'lng' => 181]])->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_google_quota_backoff_does_not_repeat_failed_paid_requests(): void
    {
        $this->actingAs($this->reader());
        config(['fleet.maps.api_key' => 'synthetic-browser', 'fleet.maps.google_server_key' => 'synthetic-server']);
        AppSetting::create(['key' => FleetMapSettings::KEY, 'value' => ['google' => true, 'display' => true, 'places' => true, 'restrictions_reviewed' => true, 'terms_reviewed' => true]]);
        Http::fake(['places.googleapis.com/*' => Http::response([], 429)]);
        $this->postJson('/fleet-assets/settings/map-capabilities/places', ['q' => 'Public library'])->assertStatus(503)->assertHeader('Retry-After', '60');
        $this->postJson('/fleet-assets/settings/map-capabilities/places', ['q' => 'Public library'])->assertStatus(503);
        Http::assertSentCount(1);
    }

    public function test_history_does_not_disclose_another_users_preference_changes(): void
    {
        $other = $this->reader();
        $snapshot = app(FleetNotificationPreferences::class)->snapshot($other);
        $this->actingAs($other)->putJson('/fleet-assets/settings/notification-preferences', ['revision' => $snapshot['revision'], 'overrides' => ['fleet.booking_decisions' => ['email' => false]]])->assertOk();
        $this->actingAs($this->reader())->getJson('/fleet-assets/settings/history')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($other)->getJson('/fleet-assets/settings/history')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_preference_write_rolls_back_if_required_audit_cannot_be_written(): void
    {
        $user = $this->reader();
        $snapshot = app(FleetNotificationPreferences::class)->snapshot($user);
        $listener = function ($event) {
            if ($event->action === 'fleet.settings.notifications.updated') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        };
        Event::listen('eloquent.creating: '.AuditLog::class, $listener);
        try {
            $this->withoutExceptionHandling()->actingAs($user)->putJson('/fleet-assets/settings/notification-preferences', ['revision' => $snapshot['revision'], 'overrides' => ['fleet.booking_decisions' => ['email' => false]]]);
            $this->fail('Expected synthetic audit failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }
        $this->assertDatabaseMissing('user_notification_preferences', ['user_id' => $user->id]);
    }

    public function test_google_reverse_geocoding_cannot_run_automatically_during_ingestion(): void
    {
        config(['fleet.maps.reverse_geocode_enabled' => true, 'fleet.maps.reverse_geocode_provider' => 'google', 'fleet.maps.google_server_key' => 'synthetic-server']);
        Http::preventStrayRequests();
        $this->assertNull(app(ReverseGeocodeService::class)->reverseGeocode(-41.2, 174.7));
        Http::assertNothingSent();
        $this->assertDatabaseCount('fleet_map_usage_logs', 0);
    }
}
