<?php

namespace Tests\Feature\Portal;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\FamilyPortalSetting;
use App\Models\NextOfKin;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortalSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_calendar_events_accept_positive_timezone_offsets(): void
    {
        $client = $this->makePortalClient();
        $portalUser = $this->makePortalUser($client);
        $staffUser = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
        $this->assignWorkerToSite($staffUser, $client->site);

        NextOfKin::query()->create([
            'client_id' => $client->id,
            'user_id' => $portalUser->id,
            'relationship' => 'guardian',
        ]);
        $familyConsentType = ConsentType::factory()->create([
            'name' => 'Information Sharing with Whānau / Family',
            'category' => 'communication',
        ]);
        ClientConsent::query()->create([
            'client_id' => $client->id,
            'consent_type_id' => $familyConsentType->id,
            'status' => 'given',
            'given_at' => now(),
            'expires_at' => now()->addMonth(),
            'given_by_user_id' => $portalUser->id,
            'given_by_relationship' => 'next_of_kin',
            'given_method' => 'portal',
            'created_by' => $portalUser->id,
            'updated_by' => $portalUser->id,
        ]);
        FamilyPortalSetting::query()->create([
            'client_id' => $client->id,
            'show_shift_schedule' => true,
        ]);

        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'user_id' => $staffUser->id,
            'status' => 'scheduled',
            'starts_at' => '2026-04-24 13:38:00',
            'ends_at' => '2026-04-24 17:38:00',
        ]);

        $this->actingAs($portalUser)
            ->getJson("/portal/clients/{$client->id}/calendar/events?start=2026-04-20T00:00:00+12:00&end=2026-04-27T00:00:00+12:00")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('0.id', 'shift-'.$shift->id)
                ->where('0.extendedProps.staff_name', $staffUser->name)
                ->etc()
            );
    }

    public function test_portal_messages_include_shift_staff_in_care_team_picker(): void
    {
        $client = $this->makePortalClient();
        $portalUser = $this->makePortalUser($client);
        $staffUser = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
        $this->assignWorkerToSite($staffUser, $client->site);

        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'user_id' => $staffUser->id,
            'status' => 'scheduled',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(4),
        ]);

        $this->actingAs($portalUser)
            ->get("/portal/clients/{$client->id}/messages")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal/messages')
                ->has('supportWorkers', 1)
                ->where('supportWorkers.0.id', $staffUser->id)
                ->where('supportWorkers.0.name', $staffUser->name)
            );
    }

    public function test_portal_location_normalizes_active_consent_for_the_ui(): void
    {
        $client = $this->makePortalClient();
        $portalUser = $this->makePortalUser($client);
        $consentType = ConsentType::factory()->create([
            'name' => 'Personal Tracker (Wandering Risk)',
        ]);
        $givenAt = now()->subHour()->startOfSecond();

        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'status' => 'given',
            'given_at' => $givenAt,
            'expires_at' => now()->addDay(),
            'given_by_user_id' => $portalUser->id,
            'given_by_relationship' => 'next_of_kin',
            'given_method' => 'portal',
            'created_by' => $portalUser->id,
            'updated_by' => $portalUser->id,
        ]);
        $device = Device::factory()->tracking()->create();
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assigned_at' => now(),
            'consent_id' => $consent->id,
        ]);

        $this->actingAs($portalUser)
            ->get("/portal/clients/{$client->id}/location")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal/location')
                ->where('trackingConsent.status', 'active')
                ->where('trackingConsent.given_at', $givenAt->toISOString())
            );
    }

    private function makePortalClient(): Client
    {
        return Client::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'status' => 'active',
        ]);
    }

    private function makePortalUser(Client $client): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'next_of_kin',
        ]);
        $role = Role::query()->firstOrCreate(
            ['name' => 'next_of_kin'],
            ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system'],
        );
        $user->roles()->syncWithoutDetaching([$role->id]);
        $client->portalUsers()->attach($user->id, ['relation' => 'next_of_kin']);

        return $user;
    }

    private function assignWorkerToSite(User $worker, Site $site): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }
}
