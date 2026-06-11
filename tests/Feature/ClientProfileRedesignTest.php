<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Models\ClientTransportBooking;
use App\Models\OpsConversation;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backend added for the client profile redesign: transport bookings,
 * onboarding add-step, the staff side of the family chat, and the extended
 * emergency-contact (family tree) fields.
 */
class ClientProfileRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->client = Client::factory()->create();
    }

    public function test_transport_booking_can_be_created_and_cancelled(): void
    {
        $response = $this->actingAs($this->admin)->post(
            "/operations/clients/{$this->client->id}/transport-bookings",
            [
                'purpose' => 'GP appointment',
                'destination' => 'Island Bay Medical',
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'vehicle' => 'House van',
                'escort_required' => true,
                'return_trip' => true,
            ],
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('client_transport_bookings', [
            'client_id' => $this->client->id,
            'purpose' => 'GP appointment',
            'status' => 'requested',
            'escort_required' => 1,
        ]);

        $booking = ClientTransportBooking::firstOrFail();

        $this->actingAs($this->admin)
            ->delete("/operations/clients/{$this->client->id}/transport-bookings/{$booking->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('client_transport_bookings', ['id' => $booking->id]);
    }

    public function test_transport_booking_stores_scheduled_at_as_worker_timezone_utc(): void
    {
        // Worker enters 10:30am wall-clock; it must round-trip to 10:30am in the
        // worker timezone, i.e. be stored as the correct UTC instant (not naive).
        $this->actingAs($this->admin)->post(
            "/operations/clients/{$this->client->id}/transport-bookings",
            [
                'purpose' => 'GP appointment',
                'scheduled_at' => '2026-06-12T10:30',
            ],
        )->assertRedirect();

        $booking = ClientTransportBooking::firstOrFail();
        $expected = CarbonImmutable::parse(
            '2026-06-12T10:30',
            config('app.worker_timezone', 'Pacific/Auckland'),
        )->utc();

        $this->assertTrue(
            $booking->scheduled_at->equalTo($expected),
            "scheduled_at should be the UTC instant of 10:30 worker-time, got {$booking->scheduled_at->toIso8601String()}",
        );
        $this->assertSame(
            '10:30',
            $booking->scheduled_at
                ->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))
                ->format('H:i'),
        );
    }

    public function test_transport_booking_rejects_other_clients_booking(): void
    {
        $other = Client::factory()->create();
        $booking = ClientTransportBooking::create([
            'client_id' => $other->id,
            'purpose' => 'Outing',
            'scheduled_at' => now()->addDay(),
            'status' => 'requested',
        ]);

        $this->actingAs($this->admin)
            ->put("/operations/clients/{$this->client->id}/transport-bookings/{$booking->id}", [
                'status' => 'cancelled',
            ])
            ->assertNotFound();
    }

    public function test_onboarding_step_can_be_added_to_workflow(): void
    {
        $this->actingAs($this->admin)
            ->post("/operations/clients/{$this->client->id}/onboarding-workflow")
            ->assertRedirect();

        $workflow = ClientOnboardingWorkflow::where('client_id', $this->client->id)->firstOrFail();
        $existingSteps = $workflow->steps()->count();

        $response = $this->actingAs($this->admin)->post(
            "/operations/onboarding/{$workflow->id}/steps",
            [
                'step_name' => 'Emergency contacts confirmed',
                'category' => 'governance',
                'due_date' => now()->addWeek()->toDateString(),
                'is_required' => true,
                'notes' => 'Confirm with whānau.',
            ],
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('client_onboarding_steps', [
            'workflow_id' => $workflow->id,
            'step_name' => 'Emergency contacts confirmed',
            'category' => 'governance',
            'status' => 'pending',
            'step_order' => $existingSteps + 1,
        ]);
    }

    public function test_family_chat_creates_shared_family_conversation(): void
    {
        $this->actingAs($this->admin)
            ->post("/operations/clients/{$this->client->id}/family-chat", [
                'content' => 'Kia ora — Tane had a great day at cooking group.',
            ])
            ->assertSuccessful();

        $conversation = OpsConversation::where('client_id', $this->client->id)
            ->where('conversation_type', 'family')
            ->firstOrFail();

        $this->assertDatabaseHas('ops_messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $this->admin->id,
            'sender_type' => 'user',
        ]);

        $show = $this->actingAs($this->admin)
            ->getJson("/operations/clients/{$this->client->id}/family-chat");

        $show->assertSuccessful()
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.mine', true);
    }

    public function test_emergency_contact_accepts_extended_family_tree_fields(): void
    {
        $response = $this->actingAs($this->admin)->post(
            "/operations/clients/{$this->client->id}/medical/emergency-contacts",
            [
                'name' => 'Hana Wineera',
                'relationship' => 'Sister',
                'phone' => '021 555 0871',
                'address' => '14 Kōwhai Lane, Island Bay',
                'preferred_method' => 'phone',
                'is_primary_contact' => true,
                'can_view_medical' => true,
                'can_view_incidents' => true,
                'can_receive_updates' => true,
            ],
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('client_emergency_contacts', [
            'client_id' => $this->client->id,
            'name' => 'Hana Wineera',
            'is_primary_contact' => 1,
            'can_view_medical' => 1,
            'can_view_incidents' => 1,
        ]);
    }

    public function test_retired_progress_notes_index_redirects_to_clients(): void
    {
        $this->actingAs($this->admin)
            ->get('/operations/progress-notes')
            ->assertRedirect('/operations/clients');

        $this->actingAs($this->admin)
            ->get("/operations/progress-notes?client_id={$this->client->id}")
            ->assertRedirect(
                "/operations/clients/{$this->client->id}?tab=progress_notes&type=progress",
            );
    }
}
