<?php

namespace Tests\Feature\Consents;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the DeviceAssignmentController consent guard for client-targeted
 * tracker assignments.
 */
class DeviceAssignmentConsentEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    private Device $tracker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $site = Site::factory()->create(['tenant_id' => 1]);
        $this->client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);
        $this->tracker = Device::factory()->tracking()->create();
    }

    public function test_client_tracker_assignment_without_consent_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$this->tracker->id}/assign", [
                'assignable_type' => 'client',
                'assignable_id' => $this->client->id,
                'assignment_type' => 'permanent',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('assignable_type');

        $this->assertDatabaseCount('device_assignments', 0);
    }

    public function test_client_tracker_assignment_with_valid_consent_succeeds(): void
    {
        $consent = $this->givenConsentFor($this->client);

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$this->tracker->id}/assign", [
                'assignable_type' => 'client',
                'assignable_id' => $this->client->id,
                'assignment_type' => 'permanent',
                'consent_id' => $consent->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $this->tracker->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->client->id,
            'consent_id' => $consent->id,
        ]);
    }

    public function test_client_tracker_assignment_with_expired_consent_is_rejected(): void
    {
        $consent = $this->givenConsentFor($this->client, expiresAt: now()->subDay());

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$this->tracker->id}/assign", [
                'assignable_type' => 'client',
                'assignable_id' => $this->client->id,
                'assignment_type' => 'permanent',
                'consent_id' => $consent->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('assignable_type');

        $this->assertDatabaseCount('device_assignments', 0);
    }

    public function test_client_tracker_assignment_with_wrong_client_consent_is_rejected(): void
    {
        $otherClient = Client::factory()->create();
        $consent = $this->givenConsentFor($otherClient);

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$this->tracker->id}/assign", [
                'assignable_type' => 'client',
                'assignable_id' => $this->client->id,
                'assignment_type' => 'permanent',
                'consent_id' => $consent->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('assignable_type');

        $this->assertDatabaseCount('device_assignments', 0);
    }

    public function test_non_tracking_device_assigned_to_client_skips_consent_check(): void
    {
        // A camera (security domain) assigned to a client — should NOT trigger
        // the tracker-specific consent gate.
        $camera = Device::factory()->security()->create();

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$camera->id}/assign", [
                'assignable_type' => 'client',
                'assignable_id' => $this->client->id,
                'assignment_type' => 'permanent',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('device_assignments', 1);
    }

    public function test_tracker_assigned_to_site_skips_client_consent_check(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$this->tracker->id}/assign", [
                'assignable_type' => 'site',
                'assignable_id' => $site->id,
                'assignment_type' => 'permanent',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('device_assignments', 1);
    }

    private function givenConsentFor(Client $client, ?\DateTimeInterface $expiresAt = null): ClientConsent
    {
        $consentType = ConsentType::factory()->create([
            'name' => 'Personal Tracker (Wandering Risk)',
            'purpose' => 'Client personal safety tracking',
        ]);

        return ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'status' => 'given',
            'given_at' => now()->subDay(),
            'given_method' => 'electronic',
            'expires_at' => $expiresAt,
        ]);
    }
}
