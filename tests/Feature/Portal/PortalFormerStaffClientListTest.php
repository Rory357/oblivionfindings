<?php

namespace Tests\Feature\Portal;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The portal rule (Stephan, 26 Oct, for the sidebar list: "Yes, apply it"): anyone with an HR profile, current or
 * archived, isn't treated as a portal member, so neither the sidebar nor the portal landing page lists a linked client.
 */
class PortalFormerStaffClientListTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::factory()->create(['site_id' => Site::factory()->create()->id, 'status' => 'active',
            'first_name' => 'Listed', 'last_name' => 'Person']);
    }

    public function test_a_family_contact_still_sees_their_linked_client(): void
    {
        $this->actingAs($this->familyContact())->get('/portal')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.portalClients.0.id', $this->client->id)
                ->where('clients.0.id', $this->client->id)
                ->etc());
    }

    public function test_a_former_staff_member_with_an_archived_profile_gets_no_linked_client(): void
    {
        $user = $this->familyContact();
        $profile = HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'is_active' => false,
            'start_date' => now()->subYear()->toDateString(), 'end_date' => now()->subMonth()->toDateString()]);
        $profile->delete();

        $response = $this->actingAs($user)->get('/portal')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.portalClients', null)
                ->where('clients', [])
                ->etc());
        $this->assertStringNotContainsString('Listed Person', $response->getContent());
    }

    public function test_a_current_staff_member_gets_no_linked_client(): void
    {
        $user = $this->familyContact();
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'is_active' => true,
            'start_date' => now()->subYear()->toDateString(), 'end_date' => null]);

        $this->actingAs($user)->get('/portal')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.portalClients', null)
                ->where('clients', [])
                ->etc());
    }

    private function familyContact(): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'next_of_kin']);
        $role = Role::query()->firstOrCreate(['name' => 'next_of_kin'], ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system']);
        $user->roles()->syncWithoutDetaching([$role->id]);
        $this->client->portalUsers()->attach($user->id, ['relation' => 'next_of_kin']);

        return $user;
    }
}
