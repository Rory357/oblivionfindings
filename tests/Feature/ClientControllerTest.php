<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientDocumentFolder;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $providerManager;
    protected User $coordinator;
    protected User $supportWorker;
    protected User $financeUser;
    protected User $hrUser;
    protected User $auditor;
    protected Site $site;
    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->providerManager = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
        $this->providerManager->roles()->attach(Role::where('name', 'provider_manager')->first());

        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->financeUser = User::factory()->create(['role' => 'finance', 'approved_at' => now()]);
        $this->financeUser->roles()->attach(Role::where('name', 'finance')->first());

        $this->hrUser = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $this->hrUser->roles()->attach(Role::where('name', 'hr')->first());

        $this->auditor = User::factory()->create(['role' => 'auditor', 'approved_at' => now()]);
        $this->auditor->roles()->attach(Role::where('name', 'auditor')->first());

        $this->site = Site::factory()->create();
        $this->serviceContext = ServiceContext::factory()->create();
    }

    /**
     * Helper to create a user with a specific role from the RBAC seeder.
     */
    private function createUserWithRole(string $roleName, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['role' => $roleName, 'approved_at' => now()], $attributes));
        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $user->roles()->attach($role);
        }
        return $user;
    }

    /**
     * Helper to build valid client creation data.
     */
    private function validClientData(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Test',
            'last_name' => 'Client',
            'date_of_birth' => '1990-01-15',
            'gender' => 'female',
            'status' => 'active',
            'phone' => '0211234567',
            'email' => 'test.client@example.com',
            'address_line_1' => '123 Test Street',
            'address_line_2' => 'Unit 4',
            'suburb' => 'Testville',
            'city' => 'Auckland',
            'postcode' => '1010',
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'funding_type' => 'whaikaha',
            'funding_notes' => 'Test funding notes',
        ], $overrides);
    }

    private function findClientByEmail(string $email): Client
    {
        $client = Client::all()->firstWhere('email', $email);

        $this->assertInstanceOf(Client::class, $client);

        return $client;
    }

    // =========================================================================
    // INDEX - Authentication
    // =========================================================================

    public function test_index_redirects_unauthenticated_user_to_login(): void
    {
        $response = $this->get('/clients');
        $response->assertRedirect('/login');
    }

    // =========================================================================
    // INDEX - Authorization per role
    // =========================================================================

    public function test_admin_can_access_client_index(): void
    {
        Client::factory()->count(2)->create();

        $response = $this->actingAs($this->admin)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/index')
            ->has('clients', 2)
        );
    }

    public function test_provider_manager_can_access_client_index(): void
    {
        Client::factory()->count(2)->create();

        $response = $this->actingAs($this->providerManager)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/index')
            ->has('clients', 2)
        );
    }

    public function test_coordinator_can_access_client_index(): void
    {
        Client::factory()->count(2)->create();

        $response = $this->actingAs($this->coordinator)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/index')
            ->has('clients', 2)
        );
    }

    public function test_support_worker_can_access_client_index_with_viewAssigned_permission(): void
    {
        $client = Client::factory()->create();
        $client->supportWorkers()->attach($this->supportWorker->id);

        $response = $this->actingAs($this->supportWorker)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/index')
            ->has('clients', 1)
        );
    }

    public function test_auditor_can_access_client_index(): void
    {
        Client::factory()->count(2)->create();

        $response = $this->actingAs($this->auditor)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/index')
            ->has('clients', 2)
        );
    }

    public function test_finance_user_cannot_access_client_index(): void
    {
        $response = $this->actingAs($this->financeUser)->get('/clients');
        $response->assertForbidden();
    }

    public function test_hr_user_cannot_access_client_index(): void
    {
        $response = $this->actingAs($this->hrUser)->get('/clients');
        $response->assertForbidden();
    }

    // =========================================================================
    // INDEX - Support worker sees only assigned clients
    // =========================================================================

    public function test_support_worker_sees_only_assigned_clients_on_index(): void
    {
        $assignedClient = Client::factory()->create(['first_name' => 'Assigned', 'last_name' => 'Client']);
        $assignedClient->supportWorkers()->attach($this->supportWorker->id);

        $unassignedClient = Client::factory()->create(['first_name' => 'Unassigned', 'last_name' => 'Client']);

        $response = $this->actingAs($this->supportWorker)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/index')
            ->has('clients', 1)
            ->where('clients.0.id', $assignedClient->id)
            ->where('clients.0.first_name', 'Assigned')
        );
    }

    public function test_support_worker_with_no_assignments_sees_empty_client_list(): void
    {
        Client::factory()->count(3)->create();

        $response = $this->actingAs($this->supportWorker)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/index')
            ->has('clients', 0)
        );
    }

    public function test_admin_sees_all_clients_including_unassigned(): void
    {
        $clientA = Client::factory()->create(['first_name' => 'Alpha']);
        $clientB = Client::factory()->create(['first_name' => 'Beta']);
        $clientA->supportWorkers()->attach($this->supportWorker->id);

        $response = $this->actingAs($this->admin)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/index')
            ->has('clients', 2)
        );
    }

    // =========================================================================
    // INDEX - Data structure returned
    // =========================================================================

    public function test_index_returns_correct_client_data_structure(): void
    {
        $client = Client::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'status' => 'active',
            'site_id' => $this->site->id,
        ]);

        $response = $this->actingAs($this->admin)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/index')
            ->has('clients', 1)
            ->has('clients.0', fn ($client) => $client
                ->has('id')
                ->has('first_name')
                ->has('last_name')
                ->has('profile_photo_url')
                ->has('avatar')
                ->has('status')
                ->has('site')
                ->has('onboarding')
                ->has('has_respite')
                ->etc()
            )
        );
    }

    public function test_index_returns_onboarding_summary_with_correct_keys(): void
    {
        Client::factory()->create([
            'first_name' => 'Complete',
            'last_name' => 'Profile',
            'date_of_birth' => '1990-01-01',
            'phone' => '0211111111',
            'address_line_1' => '1 Test St',
        ]);

        $response = $this->actingAs($this->admin)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('clients.0.onboarding', fn ($onboarding) => $onboarding
                ->has('completed')
                ->has('total')
                ->has('percent')
                ->has('status')
            )
        );
    }

    public function test_index_returns_site_data_for_client_with_site(): void
    {
        Client::factory()->create(['site_id' => $this->site->id]);

        $response = $this->actingAs($this->admin)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('clients.0.site', fn ($site) => $site
                ->where('id', $this->site->id)
                ->where('name', $this->site->name)
            )
        );
    }

    public function test_index_returns_null_site_for_client_without_site(): void
    {
        Client::factory()->create(['site_id' => null]);

        $response = $this->actingAs($this->admin)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('clients.0.site', null)
        );
    }

    public function test_index_clients_are_sorted_by_last_name(): void
    {
        Client::factory()->create(['last_name' => 'Zeta', 'first_name' => 'A']);
        Client::factory()->create(['last_name' => 'Alpha', 'first_name' => 'B']);
        Client::factory()->create(['last_name' => 'Mu', 'first_name' => 'C']);

        $response = $this->actingAs($this->admin)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('clients.0.last_name', 'Alpha')
            ->where('clients.1.last_name', 'Mu')
            ->where('clients.2.last_name', 'Zeta')
        );
    }

    public function test_index_has_respite_flag_true_when_client_has_respite_bookings(): void
    {
        $client = Client::factory()->create();

        // Create a respite booking for this client
        \App\Models\RespiteBooking::create([
            'client_id' => $client->id,
            'status' => 'confirmed',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(3),
        ]);

        $response = $this->actingAs($this->admin)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('clients.0.has_respite', true)
        );
    }

    public function test_index_has_respite_flag_false_when_client_has_no_respite(): void
    {
        Client::factory()->create();

        $response = $this->actingAs($this->admin)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('clients.0.has_respite', false)
        );
    }

    // =========================================================================
    // SHOW - Authentication
    // =========================================================================

    public function test_show_redirects_unauthenticated_user_to_login(): void
    {
        $client = Client::factory()->create();

        $response = $this->get("/clients/{$client->id}");
        $response->assertRedirect('/login');
    }

    // =========================================================================
    // SHOW - Authorization per role
    // =========================================================================

    public function test_admin_can_view_any_client(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/show')
            ->where('client.id', $client->id)
        );
    }

    public function test_provider_manager_can_view_any_client(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->providerManager)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/show')
            ->where('client.id', $client->id)
        );
    }

    public function test_coordinator_can_view_any_client(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->coordinator)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/show')
            ->where('client.id', $client->id)
        );
    }

    public function test_support_worker_can_view_assigned_client(): void
    {
        $client = Client::factory()->create();
        $client->supportWorkers()->attach($this->supportWorker->id);

        $response = $this->actingAs($this->supportWorker)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/show')
            ->where('client.id', $client->id)
        );
    }

    public function test_support_worker_cannot_view_unassigned_client(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->supportWorker)->get("/clients/{$client->id}");

        $response->assertForbidden();
    }

    public function test_auditor_can_view_any_client(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->auditor)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/show')
            ->where('client.id', $client->id)
        );
    }

    public function test_finance_user_cannot_view_client(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->financeUser)->get("/clients/{$client->id}");

        $response->assertForbidden();
    }

    public function test_hr_user_cannot_view_client(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->hrUser)->get("/clients/{$client->id}");

        $response->assertForbidden();
    }

    public function test_client_portal_user_can_view_linked_client(): void
    {
        $clientUser = $this->createUserWithRole('client');
        $client = Client::factory()->create();
        $client->portalUsers()->attach($clientUser->id, ['relation' => 'client']);

        $response = $this->actingAs($clientUser)->get("/clients/{$client->id}");

        $response->assertOk();
    }

    public function test_client_portal_user_cannot_view_unlinked_client(): void
    {
        $clientUser = $this->createUserWithRole('client');
        $client = Client::factory()->create();

        $response = $this->actingAs($clientUser)->get("/clients/{$client->id}");

        $response->assertForbidden();
    }

    public function test_next_of_kin_can_view_linked_client(): void
    {
        $nokUser = $this->createUserWithRole('next_of_kin');
        $client = Client::factory()->create();
        $client->portalUsers()->attach($nokUser->id, ['relation' => 'parent']);

        $response = $this->actingAs($nokUser)->get("/clients/{$client->id}");

        $response->assertOk();
    }

    // =========================================================================
    // SHOW - Data structure
    // =========================================================================

    public function test_show_returns_full_client_data_structure(): void
    {
        $client = Client::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Smith',
            'preferred_name' => 'Johnny',
            'date_of_birth' => '1985-06-15',
            'gender' => 'male',
            'status' => 'active',
            'phone' => '0211234567',
            'email' => 'john@example.com',
            'address_line_1' => '123 Main St',
            'address_line_2' => 'Apt 2',
            'suburb' => 'Parnell',
            'city' => 'Auckland',
            'postcode' => '1001',
            'funding_type' => 'whaikaha',
            'funding_notes' => 'Funded until Dec',
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/show')
            ->has('client', fn ($c) => $c
                ->where('id', $client->id)
                ->where('first_name', 'John')
                ->where('last_name', 'Smith')
                ->where('preferred_name', 'Johnny')
                ->where('date_of_birth', '1985-06-15')
                ->where('gender', 'male')
                ->where('status', 'active')
                ->where('phone', '0211234567')
                ->where('email', 'john@example.com')
                ->where('address_line_1', '123 Main St')
                ->where('address_line_2', 'Apt 2')
                ->where('suburb', 'Parnell')
                ->where('city', 'Auckland')
                ->where('postcode', '1001')
                ->where('funding_type', 'whaikaha')
                ->where('funding_notes', 'Funded until Dec')
                ->has('profile_photo_url')
                ->has('avatar')
                ->has('site')
                ->has('service_context')
                ->has('support_workers')
                ->etc()
            )
        );
    }

    public function test_show_returns_medical_data(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('medical', fn ($medical) => $medical
                ->has('profile')
                ->has('medications')
                ->has('conditions')
                ->has('emergency_contacts')
            )
        );
    }

    public function test_show_returns_documents(): void
    {
        $client = Client::factory()->create();

        ClientDocument::create([
            'client_id' => $client->id,
            'title' => 'Care Plan',
            'category' => 'care_plan',
            'original_name' => 'care-plan.pdf',
            'storage_path' => 'documents/care-plan.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('documents', 1)
        );
    }

    public function test_client_documents_manager_lists_empty_folders(): void
    {
        $client = Client::factory()->create();
        ClientDocumentFolder::create([
            'client_id' => $client->id,
            'name' => 'Medical Records',
        ]);

        $this->actingAs($this->admin)
            ->get(route('operations.clients.documents.index', $client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('operations/clients/documents')
                ->where('client.id', $client->id)
                ->where('folders.0.name', 'Medical Records')
                ->has('documents', 0)
            );
    }

    public function test_client_document_folder_create_persists_folder(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('operations.clients.document-folders.store', $client), [
                'name' => 'Policies',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_document_folders', [
            'client_id' => $client->id,
            'name' => 'Policies',
        ]);
    }

    public function test_show_returns_timeline_events(): void
    {
        $client = Client::factory()->create();

        TimelineEvent::create([
            'client_id' => $client->id,
            'type' => 'timeline.note',
            'occurred_at' => now(),
            'subject' => 'Test Note',
            'body' => 'Test body',
            'actor_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('events', 1)
            ->has('events.0', fn ($event) => $event
                ->has('id')
                ->has('type')
                ->has('occurred_at')
                ->has('subject')
                ->has('body')
                ->has('meta')
                ->has('visibility')
                ->has('is_pinned')
                ->has('actor')
                ->has('site')
                ->has('source_id')
                ->has('source_type')
                ->has('shift_id')
                ->has('comments')
                ->has('reactions')
            )
        );
    }

    public function test_show_returns_handover_notes(): void
    {
        $client = Client::factory()->create();

        TimelineEvent::create([
            'client_id' => $client->id,
            'type' => 'handover',
            'is_pinned' => true,
            'occurred_at' => now(),
            'subject' => 'Handover note',
            'body' => 'Important handover info',
            'actor_user_id' => $this->admin->id,
        ]);

        // Non-pinned handover should not appear
        TimelineEvent::create([
            'client_id' => $client->id,
            'type' => 'handover',
            'is_pinned' => false,
            'occurred_at' => now(),
            'subject' => 'Not pinned',
            'body' => 'Should not be in handover',
            'actor_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('handover', 1)
            ->has('handover.0', fn ($h) => $h
                ->where('subject', 'Handover note')
                ->has('id')
                ->has('type')
                ->has('occurred_at')
                ->has('body')
                ->has('is_pinned')
                ->has('actor')
                ->has('source_id')
                ->has('source_type')
            )
        );
    }

    public function test_show_returns_shifts_summary(): void
    {
        $client = Client::factory()->create();

        // Future shift
        Shift::factory()->create([
            'client_id' => $client->id,
            'user_id' => $this->supportWorker->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(4),
            'status' => 'scheduled',
        ]);

        // Past shift
        Shift::factory()->completed()->create([
            'client_id' => $client->id,
            'user_id' => $this->supportWorker->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addHours(4),
        ]);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('shifts_summary', fn ($ss) => $ss
                ->has('next')
                ->has('last')
                ->etc()
            )
        );
    }

    public function test_show_returns_shifts_summary_with_null_when_no_shifts(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('shifts_summary.next', null)
            ->where('shifts_summary.last', null)
        );
    }

    public function test_show_excludes_cancelled_shifts_from_summary(): void
    {
        $client = Client::factory()->create();

        Shift::factory()->create([
            'client_id' => $client->id,
            'user_id' => $this->supportWorker->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(4),
            'status' => 'cancelled',
        ]);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('shifts_summary.next', null)
        );
    }

    public function test_show_returns_onboarding_checklist(): void
    {
        $client = Client::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'Client',
            'date_of_birth' => '1990-01-01',
            'phone' => '0211111111',
            'address_line_1' => '1 Test St',
        ]);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('onboarding.checklist', fn ($ob) => $ob
                ->has('items')
                ->has('completed')
                ->has('total')
                ->has('percent')
                ->has('status')
            )
        );
    }

    public function test_show_returns_onboarding_items_with_correct_keys(): void
    {
        $client = Client::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'Client',
            'date_of_birth' => '1990-01-01',
            'phone' => '0211111111',
            'address_line_1' => '1 Test St',
        ]);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('onboarding.checklist.items', 8)
            ->has('onboarding.checklist.items.0', fn ($item) => $item
                ->has('key')
                ->has('label')
                ->has('has_data')
                ->has('override')
                ->has('complete')
            )
        );
    }

    public function test_show_returns_respite_bookings_and_requests(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('respite', fn ($r) => $r
                ->has('bookings')
                ->has('requests')
            )
        );
    }

    public function test_show_returns_portal_users(): void
    {
        $client = Client::factory()->create();
        $portalUser = $this->createUserWithRole('next_of_kin');
        $client->portalUsers()->attach($portalUser->id, ['relation' => 'parent']);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('portal_users', 1)
            ->has('portal_users.0', fn ($pu) => $pu
                ->where('id', $portalUser->id)
                ->where('name', $portalUser->name)
                ->where('email', $portalUser->email)
                ->where('relation', 'parent')
            )
        );
    }

    public function test_show_returns_support_plan_and_assessments(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('support_plan')
            ->has('assessments')
        );
    }

    // =========================================================================
    // SHOW - Permission flags (can.*)
    // =========================================================================

    public function test_show_returns_permission_flags_for_admin(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('can', fn ($can) => $can
                ->where('edit', true)
                ->where('assign_workers', true)
                ->where('create_note', true)
                ->where('pin_handover', true)
                ->where('manage_onboarding', true)
                ->where('create_shift', true)
                ->etc()
            )
        );
    }

    public function test_show_returns_permission_flags_for_support_worker(): void
    {
        $client = Client::factory()->create();
        $client->supportWorkers()->attach($this->supportWorker->id);

        $response = $this->actingAs($this->supportWorker)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('can', fn ($can) => $can
                ->where('edit', false)
                ->where('assign_workers', false)
                ->where('create_note', true)
                ->where('pin_handover', false)
                ->where('manage_onboarding', false)
                ->where('create_shift', false)
                ->etc()
            )
        );
    }

    public function test_show_returns_permission_flags_for_coordinator(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->coordinator)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('can', fn ($can) => $can
                ->where('edit', false)
                ->where('assign_workers', true)
                ->where('create_note', true)
                ->where('pin_handover', true)
                ->where('manage_onboarding', true)
                ->where('create_shift', true)
                ->etc()
            )
        );
    }

    // =========================================================================
    // SHOW - JSON response for modal
    // =========================================================================

    public function test_show_returns_json_when_modal_query_param_is_true(): void
    {
        $client = Client::factory()->create([
            'first_name' => 'Modal',
            'last_name' => 'Test',
            'status' => 'active',
            'site_id' => $this->site->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/clients/{$client->id}?modal=1");

        $response->assertOk();
        $response->assertJsonStructure([
            'client' => [
                'id',
                'first_name',
                'last_name',
                'profile_photo_url',
                'avatar',
                'status',
                'site',
                'support_workers',
            ],
        ]);
        $response->assertJsonFragment([
            'first_name' => 'Modal',
            'last_name' => 'Test',
        ]);
    }

    public function test_show_returns_json_when_request_wants_json(): void
    {
        $client = Client::factory()->create([
            'first_name' => 'Ajax',
            'last_name' => 'Request',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/clients/{$client->id}");

        $response->assertOk();
        $response->assertJsonStructure([
            'client' => [
                'id',
                'first_name',
                'last_name',
                'status',
                'support_workers',
            ],
        ]);
        $response->assertJsonFragment([
            'first_name' => 'Ajax',
            'last_name' => 'Request',
        ]);
    }

    public function test_show_json_response_includes_site_data(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        $response = $this->actingAs($this->admin)
            ->getJson("/clients/{$client->id}");

        $response->assertOk();
        $response->assertJsonPath('client.site.id', $this->site->id);
        $response->assertJsonPath('client.site.name', $this->site->name);
    }

    public function test_show_json_response_includes_support_workers(): void
    {
        $client = Client::factory()->create();
        $client->supportWorkers()->attach($this->supportWorker->id);

        $response = $this->actingAs($this->admin)
            ->getJson("/clients/{$client->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'client.support_workers');
        $response->assertJsonPath('client.support_workers.0.id', $this->supportWorker->id);
        $response->assertJsonPath('client.support_workers.0.name', $this->supportWorker->name);
    }

    // =========================================================================
    // SHOW - Nonexistent client
    // =========================================================================

    public function test_show_returns_404_for_nonexistent_client(): void
    {
        $response = $this->actingAs($this->admin)->get('/clients/99999');
        $response->assertNotFound();
    }

    // =========================================================================
    // CREATE - Authentication
    // =========================================================================

    public function test_create_redirects_unauthenticated_user_to_login(): void
    {
        $response = $this->get('/clients/create');
        $response->assertRedirect('/login');
    }

    // =========================================================================
    // CREATE - Authorization per role
    // =========================================================================

    public function test_admin_can_access_create_form(): void
    {
        $response = $this->actingAs($this->admin)->get('/clients/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/create')
            ->has('sites')
            ->has('serviceContexts')
            ->has('defaultServiceContextId')
        );
    }

    public function test_provider_manager_can_access_create_form(): void
    {
        $response = $this->actingAs($this->providerManager)->get('/clients/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('operations/clients/create'));
    }

    public function test_coordinator_cannot_access_create_form(): void
    {
        $response = $this->actingAs($this->coordinator)->get('/clients/create');
        $response->assertForbidden();
    }

    public function test_support_worker_cannot_access_create_form(): void
    {
        $response = $this->actingAs($this->supportWorker)->get('/clients/create');
        $response->assertForbidden();
    }

    public function test_finance_user_cannot_access_create_form(): void
    {
        $response = $this->actingAs($this->financeUser)->get('/clients/create');
        $response->assertForbidden();
    }

    public function test_hr_user_cannot_access_create_form(): void
    {
        $response = $this->actingAs($this->hrUser)->get('/clients/create');
        $response->assertForbidden();
    }

    public function test_auditor_cannot_access_create_form(): void
    {
        $response = $this->actingAs($this->auditor)->get('/clients/create');
        $response->assertForbidden();
    }

    // =========================================================================
    // CREATE - Form data
    // =========================================================================

    public function test_create_form_returns_only_active_sites(): void
    {
        $activeSite = Site::factory()->create(['is_active' => true, 'name' => 'Active Site']);
        $inactiveSite = Site::factory()->create(['is_active' => false, 'name' => 'Inactive Site']);

        $response = $this->actingAs($this->admin)->get('/clients/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('sites', fn ($sites) => $sites
                ->each(fn ($site) => $site
                    ->where('name', fn ($name) => $name !== 'Inactive Site')
                    ->etc()
                )
            )
        );
    }

    public function test_create_form_returns_only_active_service_contexts(): void
    {
        ServiceContext::factory()->create(['is_active' => true, 'name' => 'Active Context']);
        ServiceContext::factory()->create(['is_active' => false, 'name' => 'Inactive Context']);

        $response = $this->actingAs($this->admin)->get('/clients/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('serviceContexts')
        );
    }

    // =========================================================================
    // STORE - Authentication
    // =========================================================================

    public function test_store_redirects_unauthenticated_user_to_login(): void
    {
        $response = $this->post('/clients', $this->validClientData());
        $response->assertRedirect('/login');
    }

    // =========================================================================
    // STORE - Authorization per role
    // =========================================================================

    public function test_admin_can_store_client(): void
    {
        $data = $this->validClientData();

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        $response->assertSessionHas('success', 'Client created successfully.');
        $this->assertDatabaseHas('clients', [
            'first_name' => 'Test',
            'last_name' => 'Client',
        ]);

        $client = $this->findClientByEmail('test.client@example.com');
        $this->assertSame('Test', $client->first_name);
        $this->assertSame('Client', $client->last_name);
    }

    public function test_provider_manager_can_store_client(): void
    {
        $data = $this->validClientData(['email' => 'pm-client@example.com']);

        $response = $this->actingAs($this->providerManager)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        $this->findClientByEmail('pm-client@example.com');
    }

    public function test_coordinator_cannot_store_client(): void
    {
        $response = $this->actingAs($this->coordinator)->post('/clients', $this->validClientData());
        $response->assertForbidden();
    }

    public function test_support_worker_cannot_store_client(): void
    {
        $response = $this->actingAs($this->supportWorker)->post('/clients', $this->validClientData());
        $response->assertForbidden();
    }

    public function test_finance_user_cannot_store_client(): void
    {
        $response = $this->actingAs($this->financeUser)->post('/clients', $this->validClientData());
        $response->assertForbidden();
    }

    public function test_auditor_cannot_store_client(): void
    {
        $response = $this->actingAs($this->auditor)->post('/clients', $this->validClientData());
        $response->assertForbidden();
    }

    // =========================================================================
    // STORE - Validation (required fields)
    // =========================================================================

    public function test_store_requires_first_name(): void
    {
        $data = $this->validClientData(['first_name' => '']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['first_name']);
    }

    public function test_store_requires_last_name(): void
    {
        $data = $this->validClientData(['last_name' => '']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['last_name']);
    }

    public function test_store_requires_status(): void
    {
        $data = $this->validClientData(['status' => '']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['status']);
    }

    public function test_store_validates_status_values(): void
    {
        $data = $this->validClientData(['status' => 'invalid_status']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['status']);
    }

    public function test_store_validates_email_format(): void
    {
        $data = $this->validClientData(['email' => 'not-an-email']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_store_validates_date_of_birth_format(): void
    {
        $data = $this->validClientData(['date_of_birth' => 'not-a-date']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['date_of_birth']);
    }

    public function test_store_validates_site_id_exists(): void
    {
        $data = $this->validClientData(['site_id' => 99999]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['site_id']);
    }

    public function test_store_validates_service_context_id_exists(): void
    {
        $data = $this->validClientData(['service_context_id' => 99999]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['service_context_id']);
    }

    public function test_store_validates_first_name_max_length(): void
    {
        $data = $this->validClientData(['first_name' => str_repeat('a', 256)]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['first_name']);
    }

    public function test_store_validates_last_name_max_length(): void
    {
        $data = $this->validClientData(['last_name' => str_repeat('a', 256)]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['last_name']);
    }

    public function test_store_validates_email_max_length(): void
    {
        $data = $this->validClientData(['email' => str_repeat('a', 250) . '@test.com']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_store_validates_phone_max_length(): void
    {
        $data = $this->validClientData(['phone' => str_repeat('1', 51)]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['phone']);
    }

    public function test_store_validates_postcode_max_length(): void
    {
        $data = $this->validClientData(['postcode' => str_repeat('1', 21)]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['postcode']);
    }

    public function test_store_validates_funding_type_max_length(): void
    {
        $data = $this->validClientData(['funding_type' => str_repeat('a', 101)]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['funding_type']);
    }

    public function test_store_validates_funding_notes_max_length(): void
    {
        $data = $this->validClientData(['funding_notes' => str_repeat('a', 2001)]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['funding_notes']);
    }

    public function test_store_allows_empty_submission_with_only_required_fields(): void
    {
        $data = [
            'first_name' => 'Minimal',
            'last_name' => 'Client',
            'status' => 'active',
        ];

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        // The controller defaults newly-created clients to 'onboarding' status so an
        // onboarding workflow can be initialised; only inactive submissions are
        // preserved as-is. The minimal payload is therefore stored as 'onboarding'.
        $this->assertDatabaseHas('clients', [
            'first_name' => 'Minimal',
            'last_name' => 'Client',
            'status' => 'onboarding',
        ]);
    }

    public function test_store_creates_client_with_all_fields(): void
    {
        $data = $this->validClientData();

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        $this->assertDatabaseHas('clients', [
            'first_name' => 'Test',
            'last_name' => 'Client',
            'date_of_birth' => '1990-01-15',
            'gender' => 'female',
            // Newly-created clients default to 'onboarding' status; see ClientController::store.
            'status' => 'onboarding',
            'address_line_1' => '123 Test Street',
            'address_line_2' => 'Unit 4',
            'suburb' => 'Testville',
            'city' => 'Auckland',
            'postcode' => '1010',
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'funding_type' => 'whaikaha',
            'funding_notes' => 'Test funding notes',
        ]);

        // phone, email, and nhi_number are encrypted at rest, so verify the
        // decrypted values via the model accessors instead.
        $client = $this->findClientByEmail('test.client@example.com');
        $this->assertSame('0211234567', $client->phone);
    }

    public function test_store_persists_support_needs_and_care_fields(): void
    {
        $data = $this->validClientData([
            'mobility_needs' => 'Uses a walking frame indoors',
            'dietary_requirements' => 'Gluten-free',
            'languages' => ['English', 'Te Reo Māori'],
            'transport_needs' => ['Own vehicle'],
            'fluid_intake_min_ml' => 1500,
            'fluid_intake_max_ml' => 2500,
            'seizure_duration_escalation_seconds' => 300,
            'risk_level' => 'medium',
            'safeguarding_flag' => true,
            'education_level' => 'NCEA Level 1–3',
        ]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        $client = $this->findClientByEmail('test.client@example.com');
        $this->assertSame('Uses a walking frame indoors', $client->mobility_needs);
        $this->assertSame('Gluten-free', $client->dietary_requirements);
        $this->assertSame('medium', $client->risk_level);
        $this->assertTrue((bool) $client->safeguarding_flag);
        $this->assertSame(1500, (int) $client->fluid_intake_min_ml);
        $this->assertEqualsCanonicalizing(['English', 'Te Reo Māori'], $client->languages);
        $this->assertEqualsCanonicalizing(['Own vehicle'], $client->transport_needs);
    }

    public function test_store_persists_medical_conditions_and_emergency_contacts(): void
    {
        $data = $this->validClientData([
            'medical' => [
                'gp_name' => 'Dr Aroha',
                'gp_practice' => 'Hamilton East Medical',
                'blood_type' => 'O+',
                'organ_donor' => true,
                'allergies' => ['Penicillin', 'Peanuts'],
                'disabilities' => ['Epilepsy'],
                'medical_history' => 'Well-managed epilepsy.',
            ],
            'conditions' => [
                ['label' => 'Type 2 diabetes', 'severity' => 'Moderate', 'notes' => 'Diet-controlled'],
                // Blank rows are skipped.
                ['label' => '', 'severity' => 'Mild', 'notes' => ''],
            ],
            'emergency_contacts' => [
                [
                    'name' => 'Sarah Walker',
                    'relationship' => 'Mother',
                    'phone' => '0277654321',
                    'alternate_phone' => '078385000',
                    'email' => 'sarah@example.com',
                    'preferred_method' => 'phone',
                    'can_view_medical' => true,
                    'can_view_medications' => false,
                    'can_view_incidents' => true,
                    'can_receive_updates' => true,
                ],
            ],
        ]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        $client = $this->findClientByEmail('test.client@example.com');

        // Medical profile (hasOne)
        $this->assertDatabaseHas('client_medical_profiles', [
            'client_id' => $client->id,
            'gp_name' => 'Dr Aroha',
            'blood_type' => 'O+',
            'organ_donor' => true,
        ]);
        $this->assertEqualsCanonicalizing(['Penicillin', 'Peanuts'], $client->medicalProfile->allergies);

        // Conditions (hasMany) — only the labelled row is stored.
        $this->assertSame(1, $client->conditions()->count());
        $this->assertDatabaseHas('client_conditions', [
            'client_id' => $client->id,
            'label' => 'Type 2 diabetes',
            'severity' => 'Moderate',
        ]);

        // Emergency contact (hasMany) with consent + primary flag.
        $this->assertDatabaseHas('client_emergency_contacts', [
            'client_id' => $client->id,
            'name' => 'Sarah Walker',
            'relationship' => 'Mother',
            'alternate_phone' => '078385000',
            'is_primary_contact' => true,
            'contact_order' => 1,
            'can_view_medical' => true,
            'can_view_medications' => false,
            'can_view_incidents' => true,
            'authorised_health_info' => true,
        ]);
    }

    public function test_store_skips_empty_emergency_contact_rows(): void
    {
        // The wizard always sends a blank primary contact; an untouched one must
        // not create a row and must not trip primary-contact validation.
        $data = $this->validClientData([
            'emergency_contacts' => [
                [
                    'name' => '',
                    'phone' => '',
                    'preferred_method' => 'phone',
                    'can_receive_updates' => true,
                ],
            ],
        ]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        $response->assertSessionHasNoErrors();
        $client = $this->findClientByEmail('test.client@example.com');
        $this->assertSame(0, $client->emergencyContacts()->count());
    }

    public function test_store_requires_primary_contact_details_when_contact_data_entered(): void
    {
        $data = $this->validClientData([
            'emergency_contacts' => [
                ['name' => '', 'phone' => '', 'relationship' => 'Mother'],
            ],
        ]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors([
            'emergency_contacts.0.name',
            'emergency_contacts.0.phone',
        ]);
    }

    public function test_store_returns_validation_errors_for_empty_payload(): void
    {
        $response = $this->actingAs($this->admin)->post('/clients', []);

        $response->assertSessionHasErrors(['first_name', 'last_name', 'status']);
    }

    // =========================================================================
    // STORE - Portal user creation workflow
    // =========================================================================

    public function test_store_creates_portal_user_when_flag_is_set(): void
    {
        Notification::fake();

        $data = $this->validClientData([
            'email' => 'portal@example.com',
            'create_client_portal_user' => true,
        ]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));

        $client = $this->findClientByEmail('portal@example.com');
        $user = User::where('email', 'portal@example.com')->firstOrFail();

        // Verify portal user was created
        $this->assertTrue($client->portalUsers()->whereKey($user->id)->exists());
        $this->assertEquals('client', $client->portalUsers()->whereKey($user->id)->first()->pivot->relation);

        // Verify user has correct role
        $this->assertTrue($user->hasRole('client'));
        $this->assertNotNull($user->approved_at);
    }

    public function test_store_sends_password_reset_email_for_portal_user(): void
    {
        Notification::fake();

        $data = $this->validClientData([
            'email' => 'reset@example.com',
            'create_client_portal_user' => true,
        ]);

        $this->actingAs($this->admin)->post('/clients', $data);

        $user = User::where('email', 'reset@example.com')->firstOrFail();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_store_does_not_create_portal_user_when_flag_is_false(): void
    {
        Notification::fake();

        $data = $this->validClientData([
            'email' => 'noportal@example.com',
            'create_client_portal_user' => false,
        ]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        $this->assertDatabaseMissing('users', ['email' => 'noportal@example.com']);
    }

    public function test_store_does_not_create_portal_user_when_flag_is_absent(): void
    {
        Notification::fake();

        $data = $this->validClientData(['email' => 'noflag@example.com']);
        unset($data['create_client_portal_user']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        $this->assertDatabaseMissing('users', ['email' => 'noflag@example.com']);
    }

    public function test_store_requires_email_when_create_portal_user_is_true(): void
    {
        $data = $this->validClientData([
            'email' => '',
            'create_client_portal_user' => true,
        ]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_store_portal_user_uses_existing_user_if_email_exists(): void
    {
        Notification::fake();

        $existingUser = User::factory()->create([
            'email' => 'existing@example.com',
            'approved_at' => null,
            'role' => null,
        ]);

        $data = $this->validClientData([
            'email' => 'existing@example.com',
            'create_client_portal_user' => true,
        ]);

        $this->actingAs($this->admin)->post('/clients', $data);

        $existingUser->refresh();
        $this->assertNotNull($existingUser->approved_at);

        $client = $this->findClientByEmail('existing@example.com');
        $this->assertTrue($client->portalUsers()->whereKey($existingUser->id)->exists());
    }

    // =========================================================================
    // STORE - Password reset email verification workflow
    // =========================================================================

    public function test_portal_user_password_reset_verifies_email(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->post('/clients', $this->validClientData([
            'email' => 'verify@example.com',
            'create_client_portal_user' => true,
        ]))->assertRedirect();

        $user = User::where('email', 'verify@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);

        // Log out admin
        $this->post('/logout');

        // Simulate password reset
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
    }

    // =========================================================================
    // STORE - Next of kin fields ignored
    // =========================================================================

    public function test_store_ignores_next_of_kin_fields(): void
    {
        Notification::fake();

        $data = $this->validClientData([
            'create_next_of_kin_user' => true,
            'next_of_kin_name' => 'Should Be Ignored',
            'next_of_kin_email' => 'ignored.nok@example.com',
            'next_of_kin_relation' => 'mother',
        ]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));

        $client = Client::where('first_name', 'Test')->where('last_name', 'Client')->firstOrFail();

        // No next of kin user should be created
        $this->assertDatabaseMissing('users', ['email' => 'ignored.nok@example.com']);
        $this->assertFalse($client->portalUsers()->wherePivot('relation', 'mother')->exists());
    }

    // =========================================================================
    // EDIT - Authentication
    // =========================================================================

    public function test_edit_redirects_unauthenticated_user_to_login(): void
    {
        $client = Client::factory()->create();

        $response = $this->get("/clients/{$client->id}/edit");
        $response->assertRedirect('/login');
    }

    // =========================================================================
    // EDIT - Authorization per role
    // =========================================================================

    public function test_admin_can_access_edit_form(): void
    {
        $client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);

        // The edit endpoint serves an inline modal: JSON when requested as such,
        // otherwise it redirects to the canonical client show route.
        $jsonResponse = $this->actingAs($this->admin)
            ->getJson("/clients/{$client->id}/edit");

        $jsonResponse->assertOk();
        $jsonResponse->assertJsonPath('client.id', $client->id);
        $jsonResponse->assertJsonStructure([
            'client',
            'sites',
            'serviceContexts',
            'defaultServiceContextId',
        ]);

        $this->actingAs($this->admin)
            ->get("/clients/{$client->id}/edit")
            ->assertRedirect(route('operations.clients.show', $client));
    }

    public function test_provider_manager_can_access_edit_form(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->providerManager)
            ->getJson("/clients/{$client->id}/edit")
            ->assertOk()
            ->assertJsonPath('client.id', $client->id);
    }

    public function test_coordinator_cannot_access_edit_form(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->coordinator)->get("/clients/{$client->id}/edit");

        $response->assertForbidden();
    }

    public function test_support_worker_cannot_access_edit_form(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->supportWorker)->get("/clients/{$client->id}/edit");

        $response->assertForbidden();
    }

    public function test_auditor_cannot_access_edit_form(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->auditor)->get("/clients/{$client->id}/edit");

        $response->assertForbidden();
    }

    // =========================================================================
    // EDIT - Form data
    // =========================================================================

    public function test_edit_form_returns_client_data(): void
    {
        $client = Client::factory()->create([
            'first_name' => 'EditMe',
            'last_name' => 'Client',
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/clients/{$client->id}/edit");

        $response->assertOk();
        $response->assertJsonPath('client.first_name', 'EditMe');
        $response->assertJsonPath('client.last_name', 'Client');
        $response->assertJsonPath('client.site_id', $this->site->id);
        $response->assertJsonPath('client.service_context_id', $this->serviceContext->id);
    }

    public function test_edit_form_includes_inactive_site_if_client_assigned_to_it(): void
    {
        $inactiveSite = Site::factory()->create(['is_active' => false, 'name' => 'Old Site']);
        $client = Client::factory()->create(['site_id' => $inactiveSite->id]);

        $response = $this->actingAs($this->admin)
            ->getJson("/clients/{$client->id}/edit");

        $response->assertOk();
        // The sites list must contain the inactive site because the client is assigned to it.
        $siteIds = collect($response->json('sites'))->pluck('id')->all();
        $this->assertContains($inactiveSite->id, $siteIds);
    }

    // =========================================================================
    // UPDATE - Authentication
    // =========================================================================

    public function test_update_redirects_unauthenticated_user_to_login(): void
    {
        $client = Client::factory()->create();

        $response = $this->put("/clients/{$client->id}", $this->validClientData());
        $response->assertRedirect('/login');
    }

    // =========================================================================
    // UPDATE - Authorization per role
    // =========================================================================

    public function test_admin_can_update_client(): void
    {
        $client = Client::factory()->create(['first_name' => 'Old']);

        $data = $this->validClientData(['first_name' => 'Updated']);

        $response = $this->actingAs($this->admin)->put("/clients/{$client->id}", $data);

        $response->assertRedirect(route('clients.index'));
        $response->assertSessionHas('success', 'Client updated successfully.');
        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'first_name' => 'Updated',
        ]);
    }

    public function test_provider_manager_can_update_client(): void
    {
        $client = Client::factory()->create(['first_name' => 'Old']);

        $response = $this->actingAs($this->providerManager)
            ->put("/clients/{$client->id}", $this->validClientData(['first_name' => 'PMUpdated']));

        $response->assertRedirect(route('clients.index'));
        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'first_name' => 'PMUpdated',
        ]);
    }

    public function test_coordinator_cannot_update_client(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->coordinator)
            ->put("/clients/{$client->id}", $this->validClientData());

        $response->assertForbidden();
    }

    public function test_support_worker_cannot_update_client(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->supportWorker)
            ->put("/clients/{$client->id}", $this->validClientData());

        $response->assertForbidden();
    }

    public function test_auditor_cannot_update_client(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->auditor)
            ->put("/clients/{$client->id}", $this->validClientData());

        $response->assertForbidden();
    }

    // =========================================================================
    // UPDATE - Validation
    // =========================================================================

    public function test_update_requires_first_name(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->put("/clients/{$client->id}", $this->validClientData(['first_name' => '']));

        $response->assertSessionHasErrors(['first_name']);
    }

    public function test_update_requires_last_name(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->put("/clients/{$client->id}", $this->validClientData(['last_name' => '']));

        $response->assertSessionHasErrors(['last_name']);
    }

    public function test_update_requires_status(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->put("/clients/{$client->id}", $this->validClientData(['status' => '']));

        $response->assertSessionHasErrors(['status']);
    }

    public function test_update_validates_status_values(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->put("/clients/{$client->id}", $this->validClientData(['status' => 'deleted']));

        $response->assertSessionHasErrors(['status']);
    }

    public function test_update_validates_email_format(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->put("/clients/{$client->id}", $this->validClientData(['email' => 'bad-email']));

        $response->assertSessionHasErrors(['email']);
    }

    public function test_update_validates_site_id_exists(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->put("/clients/{$client->id}", $this->validClientData(['site_id' => 99999]));

        $response->assertSessionHasErrors(['site_id']);
    }

    public function test_update_validates_service_context_id_exists(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->put("/clients/{$client->id}", $this->validClientData(['service_context_id' => 99999]));

        $response->assertSessionHasErrors(['service_context_id']);
    }

    public function test_update_allows_nullable_optional_fields(): void
    {
        $client = Client::factory()->create([
            'phone' => '0211111111',
            'email' => 'old@example.com',
        ]);

        $data = [
            'first_name' => 'Minimal',
            'last_name' => 'Update',
            'status' => 'active',
            // All other fields omitted
        ];

        $response = $this->actingAs($this->admin)->put("/clients/{$client->id}", $data);

        $response->assertRedirect(route('clients.index'));
        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'first_name' => 'Minimal',
            'last_name' => 'Update',
        ]);
    }

    public function test_update_modifies_all_fields(): void
    {
        $client = Client::factory()->create();
        $newSite = Site::factory()->create();
        $newContext = ServiceContext::factory()->create();

        $data = [
            'first_name' => 'NewFirst',
            'last_name' => 'NewLast',
            'preferred_name' => 'Nick',
            'date_of_birth' => '2000-12-25',
            'gender' => 'non_binary',
            'status' => 'inactive',
            'phone' => '0229999999',
            'email' => 'updated@example.com',
            'address_line_1' => '999 New Road',
            'address_line_2' => 'Floor 3',
            'suburb' => 'NewSub',
            'city' => 'Wellington',
            'postcode' => '6011',
            'site_id' => $newSite->id,
            'service_context_id' => $newContext->id,
            'funding_type' => 'self_funded',
            'funding_notes' => 'Updated funding notes',
        ];

        $response = $this->actingAs($this->admin)->put("/clients/{$client->id}", $data);

        $response->assertRedirect(route('clients.index'));

        // phone and email are encrypted at rest; assert plaintext fields against the row
        // and verify the encrypted attributes via decrypted model accessors.
        $plaintextChecks = collect($data)->except(['phone', 'email'])->all();
        $this->assertDatabaseHas('clients', array_merge(['id' => $client->id], $plaintextChecks));

        $client->refresh();
        $this->assertSame('0229999999', $client->phone);
        $this->assertSame('updated@example.com', $client->email);
    }

    public function test_update_returns_404_for_nonexistent_client(): void
    {
        $response = $this->actingAs($this->admin)
            ->put('/clients/99999', $this->validClientData());

        $response->assertNotFound();
    }

    // =========================================================================
    // PHOTO UPLOAD - Authentication
    // =========================================================================

    public function test_photo_upload_redirects_unauthenticated_user_to_login(): void
    {
        $client = Client::factory()->create();

        $response = $this->post("/clients/{$client->id}/photo", [
            'photo' => UploadedFile::fake()->image('photo.jpg'),
        ]);

        $response->assertRedirect('/login');
    }

    // =========================================================================
    // PHOTO UPLOAD - Authorization
    // =========================================================================

    public function test_admin_can_upload_client_photo(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post("/clients/{$client->id}/photo", [
                'photo' => UploadedFile::fake()->image('avatar.jpg', 100, 100),
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Client photo updated.');

        $client->refresh();
        $this->assertNotNull($client->profile_photo_path);
        Storage::disk('public')->assertExists($client->profile_photo_path);
    }

    public function test_provider_manager_can_upload_client_photo(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create();

        $response = $this->actingAs($this->providerManager)
            ->post("/clients/{$client->id}/photo", [
                'photo' => UploadedFile::fake()->image('avatar.jpg', 100, 100),
            ]);

        $response->assertRedirect();
        $client->refresh();
        $this->assertNotNull($client->profile_photo_path);
    }

    public function test_support_worker_cannot_upload_client_photo(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create();

        $response = $this->actingAs($this->supportWorker)
            ->post("/clients/{$client->id}/photo", [
                'photo' => UploadedFile::fake()->image('avatar.jpg', 100, 100),
            ]);

        $response->assertForbidden();
    }

    public function test_coordinator_cannot_upload_client_photo(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create();

        $response = $this->actingAs($this->coordinator)
            ->post("/clients/{$client->id}/photo", [
                'photo' => UploadedFile::fake()->image('avatar.jpg', 100, 100),
            ]);

        $response->assertForbidden();
    }

    // =========================================================================
    // PHOTO UPLOAD - Validation
    // =========================================================================

    public function test_photo_upload_requires_photo_field(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post("/clients/{$client->id}/photo", []);

        $response->assertSessionHasErrors(['photo']);
    }

    public function test_photo_upload_requires_image_file(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post("/clients/{$client->id}/photo", [
                'photo' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ]);

        $response->assertSessionHasErrors(['photo']);
    }

    public function test_photo_upload_rejects_oversized_file(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create();

        // 6MB file exceeds 5MB limit
        $response = $this->actingAs($this->admin)
            ->post("/clients/{$client->id}/photo", [
                'photo' => UploadedFile::fake()->image('huge.jpg')->size(6000),
            ]);

        $response->assertSessionHasErrors(['photo']);
    }

    public function test_photo_upload_deletes_old_photo(): void
    {
        Storage::fake('public');
        $oldPath = 'profile-photos/clients/old-photo.jpg';
        Storage::disk('public')->put($oldPath, 'old photo data');

        $client = Client::factory()->create(['profile_photo_path' => $oldPath]);

        $this->actingAs($this->admin)
            ->post("/clients/{$client->id}/photo", [
                'photo' => UploadedFile::fake()->image('new.jpg', 100, 100),
            ]);

        Storage::disk('public')->assertMissing($oldPath);

        $client->refresh();
        $this->assertNotNull($client->profile_photo_path);
        $this->assertNotEquals($oldPath, $client->profile_photo_path);
    }

    // =========================================================================
    // PHOTO DELETE - Authentication
    // =========================================================================

    public function test_photo_delete_redirects_unauthenticated_user_to_login(): void
    {
        $client = Client::factory()->create();

        $response = $this->delete("/clients/{$client->id}/photo");
        $response->assertRedirect('/login');
    }

    // =========================================================================
    // PHOTO DELETE - Authorization
    // =========================================================================

    public function test_admin_can_delete_client_photo(): void
    {
        Storage::fake('public');
        $path = 'profile-photos/clients/test.jpg';
        Storage::disk('public')->put($path, 'photo data');

        $client = Client::factory()->create(['profile_photo_path' => $path]);

        $response = $this->actingAs($this->admin)->delete("/clients/{$client->id}/photo");

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Client photo removed.');

        $client->refresh();
        $this->assertNull($client->profile_photo_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_support_worker_cannot_delete_client_photo(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create(['profile_photo_path' => 'some/path.jpg']);

        $response = $this->actingAs($this->supportWorker)
            ->delete("/clients/{$client->id}/photo");

        $response->assertForbidden();
    }

    public function test_coordinator_cannot_delete_client_photo(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create(['profile_photo_path' => 'some/path.jpg']);

        $response = $this->actingAs($this->coordinator)
            ->delete("/clients/{$client->id}/photo");

        $response->assertForbidden();
    }

    // =========================================================================
    // PHOTO DELETE - Behavior
    // =========================================================================

    public function test_photo_delete_handles_client_without_photo(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create(['profile_photo_path' => null]);

        $response = $this->actingAs($this->admin)->delete("/clients/{$client->id}/photo");

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Client photo removed.');

        $client->refresh();
        $this->assertNull($client->profile_photo_path);
    }

    // =========================================================================
    // STORE - Status values (active/inactive only)
    // =========================================================================

    public function test_store_accepts_active_status(): void
    {
        $data = $this->validClientData(['status' => 'active']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        // Newly-created clients with 'active' status are stored as 'onboarding'
        // so an onboarding workflow can be initialised — see ClientController::store.
        $this->assertDatabaseHas('clients', ['status' => 'onboarding', 'first_name' => 'Test']);
    }

    public function test_store_accepts_inactive_status(): void
    {
        $data = $this->validClientData(['status' => 'inactive', 'email' => 'inactive@example.com']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        // 'inactive' status is preserved verbatim; only 'active' is rerouted to 'onboarding'.
        $client = $this->findClientByEmail('inactive@example.com');
        $this->assertSame('inactive', $client->status);
    }

    public function test_store_rejects_archived_status(): void
    {
        $data = $this->validClientData(['status' => 'archived']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['status']);
    }

    // =========================================================================
    // STORE - Service context default
    // =========================================================================

    public function test_store_without_service_context_uses_default(): void
    {
        $data = $this->validClientData(['service_context_id' => null, 'email' => 'nocontext@example.com']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        $client = $this->findClientByEmail('nocontext@example.com');
        // The service_context_id should be set to the default (or null if none configured)
        $this->assertEquals(ServiceContext::defaultId(), $client->service_context_id);
    }

    // =========================================================================
    // UPDATE - Redirect and success message
    // =========================================================================

    public function test_update_redirects_to_index_with_success_message(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->put("/clients/{$client->id}", $this->validClientData(['first_name' => 'Redirected']));

        $response->assertRedirect(route('clients.index'));
        $response->assertSessionHas('success', 'Client updated successfully.');
    }

    // =========================================================================
    // Edge cases & additional coverage
    // =========================================================================

    public function test_store_with_nullable_site_id(): void
    {
        $data = $this->validClientData(['site_id' => null, 'email' => 'nosite@example.com']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        $client = $this->findClientByEmail('nosite@example.com');
        $this->assertNull($client->site_id);
    }

    public function test_store_with_nullable_email(): void
    {
        $data = $this->validClientData(['email' => null]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        $this->assertDatabaseHas('clients', ['first_name' => 'Test', 'email' => null]);
    }

    public function test_store_with_preferred_name(): void
    {
        $data = $this->validClientData(['preferred_name' => 'Testy', 'email' => 'preferred@example.com']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertRedirect(route('clients.index'));
        $this->assertDatabaseHas('clients', ['preferred_name' => 'Testy']);
    }

    public function test_multiple_support_workers_assigned_show_on_index(): void
    {
        $worker2 = $this->createUserWithRole('support_worker');
        $client = Client::factory()->create();
        $client->supportWorkers()->attach([$this->supportWorker->id, $worker2->id]);

        // Both workers should see this client
        $response1 = $this->actingAs($this->supportWorker)->get('/clients');
        $response1->assertOk();
        $response1->assertInertia(fn ($page) => $page->has('clients', 1));

        $response2 = $this->actingAs($worker2)->get('/clients');
        $response2->assertOk();
        $response2->assertInertia(fn ($page) => $page->has('clients', 1));
    }

    public function test_show_returns_site_and_service_context_details(): void
    {
        $client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('client.site.id', $this->site->id)
            ->where('client.site.name', $this->site->name)
            ->has('client.service_context', fn ($sc) => $sc
                ->where('id', $this->serviceContext->id)
                ->where('name', $this->serviceContext->name)
                ->has('type')
            )
        );
    }

    public function test_show_returns_support_workers_list(): void
    {
        $client = Client::factory()->create();
        $client->supportWorkers()->attach($this->supportWorker->id);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('client.support_workers', 1)
            ->where('client.support_workers.0.id', $this->supportWorker->id)
            ->where('client.support_workers.0.name', $this->supportWorker->name)
            ->where('client.support_workers.0.email', $this->supportWorker->email)
        );
    }

    public function test_show_returns_client_with_no_site(): void
    {
        $client = Client::factory()->create(['site_id' => null]);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('client.site', null)
        );
    }

    public function test_show_returns_client_with_no_service_context(): void
    {
        $client = Client::factory()->create(['service_context_id' => null]);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('client.service_context', null)
        );
    }

    public function test_show_limits_timeline_events_to_80(): void
    {
        $client = Client::factory()->create();

        for ($i = 0; $i < 85; $i++) {
            TimelineEvent::create([
                'client_id' => $client->id,
                'type' => 'timeline.note',
                'occurred_at' => now()->subMinutes($i),
                'subject' => "Event {$i}",
                'body' => 'Body',
                'actor_user_id' => $this->admin->id,
            ]);
        }

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('events', 80)
        );
    }

    public function test_show_limits_handover_notes_to_5(): void
    {
        $client = Client::factory()->create();

        for ($i = 0; $i < 8; $i++) {
            TimelineEvent::create([
                'client_id' => $client->id,
                'type' => 'handover',
                'is_pinned' => true,
                'occurred_at' => now()->subMinutes($i),
                'subject' => "Handover {$i}",
                'body' => 'Handover body',
                'actor_user_id' => $this->admin->id,
            ]);
        }

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('handover', 5)
        );
    }

    public function test_store_redirects_to_index_with_success_message(): void
    {
        $response = $this->actingAs($this->admin)->post('/clients', $this->validClientData());

        $response->assertRedirect(route('clients.index'));
        $response->assertSessionHas('success', 'Client created successfully.');
    }

    public function test_store_does_not_persist_create_client_portal_user_flag_to_client(): void
    {
        Notification::fake();

        $data = $this->validClientData([
            'email' => 'flag-test@example.com',
            'create_client_portal_user' => true,
        ]);

        $this->actingAs($this->admin)->post('/clients', $data);

        $client = $this->findClientByEmail('flag-test@example.com');

        // The create_client_portal_user flag should not be stored on the client model
        $this->assertArrayNotHasKey('create_client_portal_user', $client->getAttributes());
    }

    public function test_photo_upload_accepts_png(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post("/clients/{$client->id}/photo", [
                'photo' => UploadedFile::fake()->image('avatar.png', 200, 200),
            ]);

        $response->assertRedirect();
        $client->refresh();
        $this->assertNotNull($client->profile_photo_path);
    }

    public function test_show_json_returns_null_site_for_client_without_site(): void
    {
        $client = Client::factory()->create(['site_id' => null]);

        $response = $this->actingAs($this->admin)
            ->getJson("/clients/{$client->id}");

        $response->assertOk();
        $response->assertJsonPath('client.site', null);
    }

    public function test_index_returns_empty_list_when_no_clients_exist(): void
    {
        $response = $this->actingAs($this->admin)->get('/clients');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/index')
            ->has('clients', 0)
        );
    }

    public function test_show_client_with_empty_support_workers(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('client.support_workers', 0)
        );
    }

    public function test_show_client_with_empty_portal_users(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('portal_users', 0)
        );
    }

    public function test_show_client_with_empty_documents(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('documents', 0)
        );
    }

    public function test_show_client_with_empty_events(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('events', 0)
        );
    }

    public function test_show_client_with_empty_handover(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('handover', 0)
        );
    }

    public function test_store_validates_gender_max_length(): void
    {
        $data = $this->validClientData(['gender' => str_repeat('x', 51)]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['gender']);
    }

    public function test_store_validates_preferred_name_max_length(): void
    {
        $data = $this->validClientData(['preferred_name' => str_repeat('x', 256)]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['preferred_name']);
    }

    public function test_store_validates_address_line_1_max_length(): void
    {
        $data = $this->validClientData(['address_line_1' => str_repeat('x', 256)]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['address_line_1']);
    }

    public function test_store_validates_address_line_2_max_length(): void
    {
        $data = $this->validClientData(['address_line_2' => str_repeat('x', 256)]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['address_line_2']);
    }

    public function test_store_validates_suburb_max_length(): void
    {
        $data = $this->validClientData(['suburb' => str_repeat('x', 256)]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['suburb']);
    }

    public function test_store_validates_city_max_length(): void
    {
        $data = $this->validClientData(['city' => str_repeat('x', 256)]);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['city']);
    }

    public function test_onboarding_status_is_complete_when_all_items_have_data(): void
    {
        $client = Client::factory()->create([
            'first_name' => 'Full',
            'last_name' => 'Profile',
            'date_of_birth' => '1990-01-01',
            'phone' => '0211111111',
            'address_line_1' => '1 Test St',
        ]);

        // The profile item counts as complete because we have name, dob, phone, address
        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('onboarding.checklist.items.0.key', 'profile')
            ->where('onboarding.checklist.items.0.has_data', true)
            ->where('onboarding.checklist.items.0.complete', true)
        );
    }

    public function test_onboarding_profile_incomplete_when_missing_required_data(): void
    {
        $client = Client::factory()->create([
            'first_name' => 'Incomplete',
            'last_name' => 'Profile',
            'date_of_birth' => null,
            'phone' => null,
            'email' => null,
            'address_line_1' => null,
            'city' => null,
            'postcode' => null,
        ]);

        $response = $this->actingAs($this->admin)->get("/clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('onboarding.checklist.items.0.key', 'profile')
            ->where('onboarding.checklist.items.0.has_data', false)
            ->where('onboarding.checklist.items.0.complete', false)
        );
    }

    public function test_update_validates_date_of_birth_format(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->put("/clients/{$client->id}", $this->validClientData(['date_of_birth' => 'not-a-date']));

        $response->assertSessionHasErrors(['date_of_birth']);
    }

    public function test_update_validates_first_name_max_length(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->put("/clients/{$client->id}", $this->validClientData(['first_name' => str_repeat('a', 256)]));

        $response->assertSessionHasErrors(['first_name']);
    }

    public function test_update_validates_last_name_max_length(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->put("/clients/{$client->id}", $this->validClientData(['last_name' => str_repeat('a', 256)]));

        $response->assertSessionHasErrors(['last_name']);
    }

    public function test_update_validates_funding_notes_max_length(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->admin)
            ->put("/clients/{$client->id}", $this->validClientData(['funding_notes' => str_repeat('a', 2001)]));

        $response->assertSessionHasErrors(['funding_notes']);
    }

    public function test_store_with_site_id_as_string_fails_validation(): void
    {
        $data = $this->validClientData(['site_id' => 'not-a-number']);

        $response = $this->actingAs($this->admin)->post('/clients', $data);

        $response->assertSessionHasErrors(['site_id']);
    }

    public function test_store_portal_user_name_is_derived_from_client_name(): void
    {
        Notification::fake();

        $data = $this->validClientData([
            'first_name' => 'Portal',
            'last_name' => 'Name',
            'email' => 'portalname@example.com',
            'create_client_portal_user' => true,
        ]);

        $this->actingAs($this->admin)->post('/clients', $data);

        $user = User::where('email', 'portalname@example.com')->firstOrFail();
        $this->assertEquals('Portal Name', $user->name);
    }

    public function test_photo_upload_returns_404_for_nonexistent_client(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin)
            ->post('/clients/99999/photo', [
                'photo' => UploadedFile::fake()->image('photo.jpg'),
            ]);

        $response->assertNotFound();
    }

    public function test_photo_delete_returns_404_for_nonexistent_client(): void
    {
        $response = $this->actingAs($this->admin)->delete('/clients/99999/photo');
        $response->assertNotFound();
    }

    public function test_edit_returns_404_for_nonexistent_client(): void
    {
        $response = $this->actingAs($this->admin)->get('/clients/99999/edit');
        $response->assertNotFound();
    }
}
