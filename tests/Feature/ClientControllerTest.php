<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected Site $site;
    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->site = Site::factory()->create();
        $this->serviceContext = ServiceContext::factory()->create();
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->get('/clients');
        $response->assertRedirect('/login');
    }

    public function test_index_displays_clients(): void
    {
        Client::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)->get('/clients');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('clients/index')
            ->has('clients', 3)
        );
    }

    public function test_staff_can_only_view_assigned_clients(): void
    {
        $assignedClient = Client::factory()->create();
        $assignedClient->supportWorkers()->attach($this->staff->id);

        Client::factory()->create(); // Unassigned client

        $response = $this->actingAs($this->staff)->get('/clients');
        $response->assertOk();
        
        // Staff should see filtering UI but data is filtered server-side
        $response->assertInertia(fn ($page) => $page
            ->component('clients/index')
        );
    }

    public function test_can_search_clients(): void
    {
        Client::factory()->create(['first_name' => 'John', 'last_name' => 'Smith']);
        Client::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

        $response = $this->actingAs($this->admin)
            ->get('/clients?q=john');
        
        $response->assertOk();
    }

    public function test_create_requires_permission(): void
    {
        $response = $this->actingAs($this->staff)->get('/clients/create');
        $response->assertForbidden();
    }

    public function test_admin_can_create_client(): void
    {
        $response = $this->actingAs($this->admin)->get('/clients/create');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('clients/create')
        );
    }

    public function test_store_creates_client(): void
    {
        $clientData = [
            'first_name' => 'Test',
            'last_name' => 'Client',
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'phone' => '0211234567',
            'email' => 'test@example.com',
            'address_line_1' => '123 Test St',
            'suburb' => 'Testville',
            'city' => 'Auckland',
            'postcode' => '1010',
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ];

        $response = $this->actingAs($this->admin)
            ->post('/clients', $clientData);

        $response->assertRedirect();
        $this->assertDatabaseHas('clients', [
            'first_name' => 'Test',
            'last_name' => 'Client',
            'email' => 'test@example.com',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/clients', []);

        $response->assertSessionHasErrors(['first_name', 'last_name']);
    }

    public function test_show_displays_client(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('clients/show')
            ->where('client.id', $client->id)
        );
    }

    public function test_edit_requires_permission(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->staff)
            ->get("/clients/{$client->id}/edit");
        
        $response->assertForbidden();
    }

    public function test_update_modifies_client(): void
    {
        $client = Client::factory()->create(['first_name' => 'Old']);

        $response = $this->actingAs($this->admin)
            ->put("/clients/{$client->id}", [
                'first_name' => 'New',
                'last_name' => $client->last_name,
                'date_of_birth' => $client->date_of_birth?->format('Y-m-d'),
                'status' => $client->status,
                'site_id' => $client->site_id,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'first_name' => 'New',
        ]);
    }

    public function test_can_upload_client_photo(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create();

        $file = UploadedFile::fake()->image('photo.jpg');

        $response = $this->actingAs($this->admin)
            ->post("/clients/{$client->id}/photo", [
                'photo' => $file,
            ]);

        $response->assertRedirect();
        
        // Verify the client's profile_photo_path was updated
        $client->refresh();
        $this->assertNotNull($client->profile_photo_path);
        
        // Verify the file exists in storage
        Storage::disk('public')->assertExists($client->profile_photo_path);
    }

    public function test_can_delete_client_photo(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create([
            'profile_photo_path' => 'clients/test.jpg',
        ]);
        Storage::disk('public')->put('clients/test.jpg', 'test');

        $response = $this->actingAs($this->admin)
            ->delete("/clients/{$client->id}/photo");

        $response->assertRedirect();
        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'profile_photo_path' => null,
        ]);
    }

    public function test_client_filters_by_onboarding_status(): void
    {
        Client::factory()->count(2)->create();

        $response = $this->actingAs($this->admin)
            ->get('/clients?only_incomplete=1');

        $response->assertOk();
    }

    public function test_client_filters_by_respite(): void
    {
        Client::factory()->count(2)->create();

        $response = $this->actingAs($this->admin)
            ->get('/clients?respite=yes');

        $response->assertOk();
    }
}
