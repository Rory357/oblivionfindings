<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
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

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->client = Client::factory()->create();
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
}
