<?php

namespace Tests\Feature\Respite;

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\FamilyPortalSetting;
use App\Models\RespiteBooking;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RespitePortalVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $portalUser;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->portalUser = User::factory()->create([
            'role' => 'next_of_kin',
            'approved_at' => now(),
        ]);
        $this->portalUser->roles()->attach(Role::where('name', 'next_of_kin')->first());

        $this->client = Client::factory()->create();
        $this->client->portalUsers()->attach($this->portalUser->id, ['relation' => 'guardian']);
    }

    public function test_respite_bookings_are_visible_in_portal_schedule_calendar_and_dashboard_by_default(): void
    {
        $booking = RespiteBooking::factory()->create([
            'client_id' => $this->client->id,
            'status' => 'confirmed',
            'start_at' => now()->addDays(4)->setTime(10, 0),
            'end_at' => now()->addDays(6)->setTime(15, 0),
        ]);

        $this->actingAs($this->portalUser)
            ->get(route('portal.clients.schedule', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('showRespite', true)
                ->where('respiteStays.0.id', $booking->id)
            );

        $calendarEvents = $this->actingAs($this->portalUser)
            ->getJson(route('portal.clients.calendar.events', [
                'client' => $this->client,
                'start' => now()->startOfDay()->toIso8601String(),
                'end' => now()->addDays(30)->endOfDay()->toIso8601String(),
            ]))
            ->assertOk()
            ->json();

        $this->assertContains('respite_stay', collect($calendarEvents)->pluck('extendedProps.type')->all());

        $this->actingAs($this->portalUser)
            ->get(route('portal.clients.dashboard', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('upcomingRespite.0.id', $booking->id)
            );
    }

    public function test_show_respite_false_hides_portal_respite_surfaces(): void
    {
        RespiteBooking::factory()->create([
            'client_id' => $this->client->id,
            'status' => 'confirmed',
            'start_at' => now()->addDays(4)->setTime(10, 0),
            'end_at' => now()->addDays(6)->setTime(15, 0),
        ]);

        FamilyPortalSetting::create([
            'client_id' => $this->client->id,
            'show_shift_schedule' => true,
            'show_respite' => false,
        ]);

        $this->actingAs($this->portalUser)
            ->get(route('portal.clients.schedule', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('showRespite', false)
                ->where('respiteStays', [])
            );

        $calendarEvents = $this->actingAs($this->portalUser)
            ->getJson(route('portal.clients.calendar.events', [
                'client' => $this->client,
                'start' => now()->startOfDay()->toIso8601String(),
                'end' => now()->addDays(30)->endOfDay()->toIso8601String(),
            ]))
            ->assertOk()
            ->json();

        $this->assertNotContains('respite_stay', collect($calendarEvents)->pluck('extendedProps.type')->all());

        $this->actingAs($this->portalUser)
            ->get(route('portal.clients.dashboard', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('upcomingRespite', [])
            );
    }

    public function test_withdrawing_family_information_consent_disables_sensitive_portal_surfaces(): void
    {
        FamilyPortalSetting::create([
            'client_id' => $this->client->id,
            'show_shift_schedule' => true,
            'show_respite' => true,
            'show_care_notes' => true,
            'show_incidents' => true,
        ]);

        $consentType = ConsentType::factory()->create([
            'name' => 'Information Sharing with Whānau / Family',
            'category' => 'communication',
        ]);

        $consent = ClientConsent::create([
            'client_id' => $this->client->id,
            'consent_type_id' => $consentType->id,
            'status' => 'given',
            'given_at' => now(),
            'given_method' => 'written',
            'given_by_relationship' => 'guardian',
            'given_by_user_id' => $this->portalUser->id,
        ]);

        $consent->update([
            'status' => 'withdrawn',
            'withdrawn_at' => now(),
            'withdrawal_reason' => 'Client withdrew portal sharing consent.',
        ]);

        $setting = FamilyPortalSetting::where('client_id', $this->client->id)->firstOrFail();

        $this->assertFalse($setting->show_respite);
        $this->assertFalse($setting->show_care_notes);
        $this->assertFalse($setting->show_incidents);
        $this->assertTrue($setting->show_shift_schedule);
    }
}
