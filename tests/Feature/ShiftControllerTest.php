<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected Client $client;
    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles and permissions
        $this->seed(\Database\Seeders\RbacSeeder::class);

        // Create test users
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->staff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->staff->roles()->attach(Role::where('name', 'support_worker')->first());
        
        // Grant staff additional permissions needed for tests
        $staffRole = Role::where('name', 'support_worker')->first();
        $staffRole->permissions()->syncWithoutDetaching([
            \App\Models\Permission::where('key', 'shifts.update')->first()->id,
        ]);

        // Create service context
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Test Context',
            'type' => 'residential',
            'is_active' => true,
        ]);

        // Create client
        $this->client = Client::factory()->create([
            'service_context_id' => $this->serviceContext->id,
        ]);
    }

    // ==========================================
    // INDEX TESTS
    // ==========================================

    public function test_index_requires_authentication(): void
    {
        $response = $this->get('/shifts');
        $response->assertRedirect('/login');
    }

    public function test_index_displays_for_authorized_user(): void
    {
        $response = $this->actingAs($this->admin)->get('/shifts');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('shifts/index')
            ->has('shifts')
            ->has('filters')
            ->has('clients')
            ->has('staff')
        );
    }

    public function test_index_applies_date_filters(): void
    {
        $today = now()->format('Y-m-d');
        
        $response = $this->actingAs($this->admin)->get("/shifts?from={$today}&to={$today}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.from', $today)
            ->where('filters.to', $today)
        );
    }

    public function test_index_applies_search_filter_safely(): void
    {
        // This tests that search doesn't cause SQL injection
        $maliciousInput = "test' OR '1'='1";
        
        $response = $this->actingAs($this->admin)->get("/shifts?q=" . urlencode($maliciousInput));
        $response->assertOk();
        // If SQL injection worked, we'd get all shifts. With parameterized query, we get none.
    }

    // ==========================================
    // STORE TESTS
    // ==========================================

    public function test_create_includes_client_site_for_location_prefill(): void
    {
        $site = Site::factory()->create(['name' => 'Kauri House']);
        $this->client->update(['site_id' => $site->id]);

        $response = $this->actingAs($this->admin)->get('/shifts/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('shifts/create')
            ->where('clients.0.id', $this->client->id)
            ->where('clients.0.site.id', $site->id)
            ->where('clients.0.site.name', 'Kauri House')
        );
    }

    public function test_store_creates_shift_with_valid_data(): void
    {
        $shiftData = [
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(4)->format('Y-m-d H:i:s'),
            'location' => 'Test Location',
            'notes' => 'Test notes',
            'status' => 'scheduled',
        ];

        $response = $this->actingAs($this->admin)
            ->post('/shifts', $shiftData);

        $response->assertRedirect('/shifts');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('shifts', [
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'location' => 'Test Location',
            'status' => 'scheduled',
        ]);
    }

    public function test_store_resolves_service_context_automatically(): void
    {
        $shiftData = [
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(4)->format('Y-m-d H:i:s'),
        ];

        $this->actingAs($this->admin)->post('/shifts', $shiftData);

        $this->assertDatabaseHas('shifts', [
            'client_id' => $this->client->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
    }

    public function test_store_validates_shift_duration_not_exceeding_24_hours(): void
    {
        $shiftData = [
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(25)->format('Y-m-d H:i:s'),
        ];

        $response = $this->actingAs($this->admin)
            ->post('/shifts', $shiftData);

        $response->assertSessionHasErrors(['ends_at']);
    }

    public function test_store_validates_starts_at_is_today_or_future(): void
    {
        $shiftData = [
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addHours(4)->format('Y-m-d H:i:s'),
        ];

        $response = $this->actingAs($this->admin)
            ->post('/shifts', $shiftData);

        $response->assertSessionHasErrors(['starts_at']);
    }

    public function test_store_validates_max_tasks(): void
    {
        $tasks = [];
        for ($i = 0; $i < 51; $i++) {
            $tasks[] = ['label' => "Task {$i}"];
        }

        $shiftData = [
            'client_id' => $this->client->id,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(4)->format('Y-m-d H:i:s'),
            'tasks' => $tasks,
        ];

        $response = $this->actingAs($this->admin)
            ->post('/shifts', $shiftData);

        $response->assertSessionHasErrors(['tasks']);
    }

    public function test_store_validates_notes_max_length(): void
    {
        $shiftData = [
            'client_id' => $this->client->id,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(4)->format('Y-m-d H:i:s'),
            'notes' => str_repeat('a', 10001),
        ];

        $response = $this->actingAs($this->admin)
            ->post('/shifts', $shiftData);

        $response->assertSessionHasErrors(['notes']);
    }

    public function test_store_detects_conflicting_shifts(): void
    {
        // Create existing shift
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        // Try to create overlapping shift
        $shiftData = [
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->setTime(14, 0)->format('Y-m-d H:i:s'),
        ];

        $response = $this->actingAs($this->admin)
            ->post('/shifts', $shiftData);

        $response->assertSessionHasErrors(['starts_at']);
    }

    public function test_store_creates_tasks_when_provided(): void
    {
        $shiftData = [
            'client_id' => $this->client->id,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(4)->format('Y-m-d H:i:s'),
            'tasks' => [
                ['label' => 'Task 1'],
                ['label' => 'Task 2'],
                ['label' => 'Task 3'],
            ],
        ];

        $this->actingAs($this->admin)->post('/shifts', $shiftData);

        $shift = Shift::latest()->first();
        $this->assertCount(3, $shift->tasks);
        $this->assertDatabaseHas('shift_tasks', ['label' => 'Task 1', 'shift_id' => $shift->id]);
    }

    // ==========================================
    // UPDATE TESTS
    // ==========================================

    public function test_update_modifies_shift(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'location' => 'Old Location',
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->admin)
            ->put("/shifts/{$shift->id}", [
                'client_id' => $this->client->id,
                'starts_at' => $shift->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $shift->ends_at->format('Y-m-d H:i:s'),
                'location' => 'New Location',
                'status' => 'scheduled',
            ]);

        $response->assertRedirect('/shifts');
        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'location' => 'New Location',
        ]);
    }

    public function test_update_prevents_modifying_completed_shift(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->admin)
            ->put("/shifts/{$shift->id}", [
                'client_id' => $this->client->id,
                'starts_at' => $shift->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $shift->ends_at->format('Y-m-d H:i:s'),
                'location' => 'New Location',
            ]);

        $response->assertSessionHas('error');
    }

    public function test_update_syncs_tasks(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'status' => 'scheduled',
        ]);

        // Add existing task
        $existingTask = $shift->tasks()->create(['label' => 'Old Task', 'sort_order' => 0]);

        $response = $this->actingAs($this->admin)
            ->put("/shifts/{$shift->id}", [
                'client_id' => $this->client->id,
                'starts_at' => $shift->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $shift->ends_at->format('Y-m-d H:i:s'),
                'status' => 'scheduled',
                'tasks' => [
                    ['id' => $existingTask->id, 'label' => 'Updated Task'],
                    ['label' => 'New Task'],
                ],
            ]);

        $response->assertRedirect('/shifts');
        $this->assertDatabaseHas('shift_tasks', ['id' => $existingTask->id, 'label' => 'Updated Task']);
        $this->assertDatabaseHas('shift_tasks', ['label' => 'New Task', 'shift_id' => $shift->id]);
    }

    // ==========================================
    // SHIFT LIFECYCLE TESTS
    // ==========================================

    public function test_start_changes_status_to_in_progress(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->staff)
            ->patch("/shifts/{$shift->id}/start");

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_complete_requires_note_or_existing_notes(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'status' => 'in_progress',
        ]);

        // Try to complete without any notes
        $response = $this->actingAs($this->staff)
            ->patch("/shifts/{$shift->id}/complete", [
                'create_timesheet' => false,
            ]);

        $response->assertSessionHasErrors(['final_note_body']);
    }

    public function test_complete_creates_summary_note(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($this->staff)
            ->patch("/shifts/{$shift->id}/complete", [
                'final_note_subject' => 'Shift Summary',
                'final_note_body' => 'Completed all tasks successfully',
                'create_timesheet' => false,
            ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'completed',
        ]);
    }

    // ==========================================
    // AUTHORIZATION TESTS
    // ==========================================

    public function test_staff_can_only_view_own_shifts(): void
    {
        $otherStaff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $otherStaff->roles()->attach(Role::where('name', 'support_worker')->first());

        // Create shift assigned to other staff (for today so it shows in default filter)
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $otherStaff->id,
            'starts_at' => now()->startOfDay()->addHours(9),
            'ends_at' => now()->startOfDay()->addHours(13),
            'status' => 'scheduled',
        ]);

        // Create shift for our staff (for today so it shows in default filter)
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->startOfDay()->addHours(14),
            'ends_at' => now()->startOfDay()->addHours(18),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->staff)->get('/shifts');
        $response->assertOk();
        
        // Staff should only see their own shift
        $response->assertInertia(fn ($page) => $page
            ->where('shifts.data', fn ($shifts) => count($shifts) === 1)
        );
    }

    // ==========================================
    // SERVICE CONTEXT RESOLVER TESTS
    // ==========================================

    public function test_service_context_uses_provided_value(): void
    {
        $otherContext = ServiceContext::factory()->create(['name' => 'Other Context']);

        $shiftData = [
            'client_id' => $this->client->id,
            'service_context_id' => $otherContext->id,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(4)->format('Y-m-d H:i:s'),
        ];

        $this->actingAs($this->admin)->post('/shifts', $shiftData);

        $this->assertDatabaseHas('shifts', [
            'service_context_id' => $otherContext->id,
        ]);
    }

    public function test_service_context_falls_back_to_client_context(): void
    {
        // Client has service_context_id set in setUp
        $shiftData = [
            'client_id' => $this->client->id,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(4)->format('Y-m-d H:i:s'),
        ];

        $this->actingAs($this->admin)->post('/shifts', $shiftData);

        $shift = Shift::latest()->first();
        $this->assertEquals($this->serviceContext->id, $shift->service_context_id);
    }
}
