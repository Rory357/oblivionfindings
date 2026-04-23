<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Models\Shift;
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
        $portalUser = User::factory()->create([
            'approved_at' => now(),
        ]);
        $staffUser = User::factory()->create([
            'approved_at' => now(),
        ]);
        $client = Client::factory()->create();

        $client->portalUsers()->attach($portalUser->id, ['relation' => 'next_of_kin']);

        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'user_id' => $staffUser->id,
            'status' => 'scheduled',
            'starts_at' => '2026-04-24 13:38:00',
            'ends_at' => '2026-04-24 17:38:00',
        ]);

        $this->actingAs($portalUser)
            ->getJson("/portal/clients/{$client->id}/calendar/events?start=2026-04-20T00:00:00+12:00&end=2026-04-27T00:00:00+12:00")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('0.id', 'shift-' . $shift->id)
                ->where('0.extendedProps.staff_name', $staffUser->name)
                ->etc()
            );
    }

    public function test_portal_messages_include_shift_staff_in_care_team_picker(): void
    {
        $portalUser = User::factory()->create([
            'approved_at' => now(),
        ]);
        $staffUser = User::factory()->create([
            'approved_at' => now(),
        ]);
        $client = Client::factory()->create();

        $client->portalUsers()->attach($portalUser->id, ['relation' => 'next_of_kin']);

        Shift::factory()->create([
            'client_id' => $client->id,
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
}
