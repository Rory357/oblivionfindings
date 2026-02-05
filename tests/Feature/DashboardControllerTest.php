<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $manager;
    protected User $staff;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->manager = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
        $this->manager->roles()->attach(Role::where('name', 'provider_manager')->first());

        $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->staff->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->client = Client::factory()->create();
        $this->client->supportWorkers()->attach($this->staff->id);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_staff_dashboard_shifts(): void
    {
        Shift::factory()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'starts_at' => now()->startOfDay()->addHours(9),
            'ends_at' => now()->startOfDay()->addHours(17),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->staff)->get('/dashboard');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('mode', 'staff')
            ->has('todayShifts')
            ->has('upcomingShifts')
        );
    }

    public function test_manager_dashboard_shows_summary(): void
    {
        Shift::factory()->count(3)->create([
            'starts_at' => now()->startOfDay()->addHours(9),
        ]);

        Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->manager)->get('/dashboard');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('mode', 'manager')
            ->has('managerSummary')
        );
    }

    public function test_dashboard_filters_by_range(): void
    {
        Shift::factory()->create([
            'user_id' => $this->staff->id,
            'starts_at' => now()->addDays(2),
        ]);

        $response = $this->actingAs($this->staff)
            ->get('/dashboard?range=today');
        
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.range', 'today')
        );
    }

    public function test_dashboard_filters_by_status(): void
    {
        Shift::factory()->create([
            'user_id' => $this->staff->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->staff)
            ->get('/dashboard?status=completed');
        
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.status', 'completed')
        );
    }

    public function test_dashboard_filters_by_client(): void
    {
        Shift::factory()->create([
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->get("/dashboard?client_id={$this->client->id}");
        
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.client_id', $this->client->id)
        );
    }

    public function test_dashboard_shows_incident_kpis_for_managers(): void
    {
        ClientIncident::factory()->highSeverity()->create([
            'occurred_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($this->manager)->get('/dashboard');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('incidentKpis')
        );
    }

    public function test_dashboard_shows_analytics(): void
    {
        Shift::factory()->count(5)->create([
            'starts_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($this->manager)->get('/dashboard');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('analytics.shiftSeries')
            ->has('analytics.shiftSeries30')
        );
    }

    public function test_dashboard_shows_timesheet_status(): void
    {
        Timesheet::factory()->submitted()->create();

        $response = $this->actingAs($this->manager)->get('/dashboard');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('analytics.timesheetByStatus')
        );
    }

    public function test_dashboard_shows_my_day_workstream(): void
    {
        Shift::factory()->create([
            'user_id' => $this->staff->id,
            'starts_at' => now()->addHours(2),
        ]);

        $response = $this->actingAs($this->staff)->get('/dashboard');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('myDayItems')
        );
    }

    public function test_dashboard_shows_upcoming_events(): void
    {
        TimelineEvent::factory()->create([
            'actor_user_id' => $this->staff->id,
            'occurred_at' => now()->addDays(2),
        ]);

        $response = $this->actingAs($this->staff)->get('/dashboard');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('upcomingEvents')
        );
    }

    public function test_today_page_works(): void
    {
        $response = $this->actingAs($this->staff)->get('/today');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('dashboard/today')
        );
    }
}
