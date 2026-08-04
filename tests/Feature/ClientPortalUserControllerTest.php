<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClientPortalUserControllerTest extends TestCase
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
        $this->client = Client::factory()->create([
            'site_id' => Site::factory()->create()->id,
        ]);
    }

    public function test_portal_user_store_can_create_missing_next_of_kin_user_and_send_password_email(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->admin)->post("/clients/{$this->client->id}/portal-users", [
            'name' => 'Nok Person',
            'email' => 'nok.person@example.com',
            'relation' => 'sister',
            'portal_role' => 'next_of_kin',
            'action' => 'create_user',
        ]);

        $response->assertRedirect();

        $user = User::where('email', 'nok.person@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('next_of_kin'));
        $this->assertTrue($this->client->portalUsers()->whereKey($user->id)->exists());
        $this->assertEquals('sister', $this->client->portalUsers()->whereKey($user->id)->first()->pivot->relation);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_portal_user_store_first_step_returns_user_not_found_error_when_email_missing(): void
    {
        $response = $this->actingAs($this->admin)->post("/clients/{$this->client->id}/portal-users", [
            'name' => 'Nok Person',
            'email' => 'unknown@example.com',
            'relation' => 'mother',
            'portal_role' => 'next_of_kin',
            'action' => 'link',
        ]);

        $response->assertSessionHasErrors(['email']);
        $this->assertDatabaseMissing('users', ['email' => 'unknown@example.com']);
    }

    public function test_portal_user_store_can_save_next_of_kin_contact_only_when_user_missing(): void
    {
        $response = $this->actingAs($this->admin)->post("/clients/{$this->client->id}/portal-users", [
            'name' => 'Contact Only Person',
            'email' => 'contact.only@example.com',
            'relation' => 'aunt',
            'portal_role' => 'next_of_kin',
            'action' => 'contact_only',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseMissing('users', ['email' => 'contact.only@example.com']);
        $this->assertDatabaseHas('client_emergency_contacts', [
            'client_id' => $this->client->id,
            'name' => 'Contact Only Person',
            'email' => 'contact.only@example.com',
            'relationship' => 'aunt',
        ]);
    }

    public function test_link_unlink_and_relink_never_restore_historical_family_conversation_access(): void
    {
        $portalUser = User::factory()->create([
            'email' => 'historical.family@example.com',
            'role' => null,
        ]);
        $conversation = OpsConversation::query()->create([
            'conversation_type' => 'family',
            'client_id' => $this->client->id,
            'title' => 'Historical private family conversation',
        ]);
        $message = OpsMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $this->admin->id,
            'sender_type' => 'user',
            'message_type' => 'text',
            'content' => 'Private historical message',
            'client_id' => $this->client->id,
        ]);
        $linkPayload = [
            'name' => $portalUser->name,
            'email' => $portalUser->email,
            'relation' => 'sister',
            'portal_role' => 'next_of_kin',
            'action' => 'link',
        ];

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/portal-users", $linkPayload)
            ->assertRedirect();
        $this->assertDatabaseHas('client_portal_users', [
            'client_id' => $this->client->id,
            'user_id' => $portalUser->id,
        ]);
        $this->assertDatabaseMissing('ops_conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $portalUser->id,
        ]);

        // Explicit participation is revoked with the canonical portal link.
        OpsConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $portalUser->id,
            'role' => 'family',
        ]);
        $this->delete("/clients/{$this->client->id}/portal-users/{$portalUser->id}")
            ->assertRedirect();
        $this->assertDatabaseMissing('client_portal_users', [
            'client_id' => $this->client->id,
            'user_id' => $portalUser->id,
        ]);
        $this->assertDatabaseMissing('ops_conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $portalUser->id,
        ]);
        $this->assertDatabaseHas('ops_messages', [
            'id' => $message->id,
            'conversation_id' => $conversation->id,
        ]);

        $this->post("/clients/{$this->client->id}/portal-users", $linkPayload)
            ->assertRedirect();
        $this->assertDatabaseHas('client_portal_users', [
            'client_id' => $this->client->id,
            'user_id' => $portalUser->id,
        ]);
        $this->assertDatabaseMissing('ops_conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $portalUser->id,
        ]);

        $this->actingAs($portalUser)
            ->get("/portal/clients/{$this->client->id}/messages/{$conversation->id}")
            ->assertForbidden();
    }

    public function test_unlink_rejects_a_nested_user_who_is_not_a_current_portal_member(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
            'approved_at' => now(),
        ]);
        $conversation = OpsConversation::query()->create([
            'conversation_type' => 'family',
            'client_id' => $this->client->id,
            'title' => 'Protected staff participation',
        ]);
        OpsConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $staff->id,
            'role' => 'staff',
        ]);

        $this->actingAs($this->admin)
            ->delete("/clients/{$this->client->id}/portal-users/{$staff->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('ops_conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $staff->id,
        ]);
    }

    public function test_link_rejects_a_current_staff_identity_before_assigning_a_portal_role(): void
    {
        $staff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'email' => 'current.staff.portal@example.com',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $staff->id,
            'primary_site_id' => $this->client->site_id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        $this->actingAs($this->admin)
            ->post("/clients/{$this->client->id}/portal-users", [
                'name' => $staff->name,
                'email' => $staff->email,
                'relation' => 'carer',
                'portal_role' => 'next_of_kin',
                'action' => 'link',
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('client_portal_users', [
            'client_id' => $this->client->id,
            'user_id' => $staff->id,
        ]);
        $this->assertFalse($staff->fresh()->hasRole('next_of_kin'));
        $this->assertFalse($staff->fresh()->canAccessClientPortal($this->client));
    }
}
