<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SafeguardingConcernControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $coordinator;
    protected User $supportWorker;
    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $this->hr->roles()->attach(Role::where('name', 'hr')->first());
    }

    // ──────────────────────────────────────
    // Index - Authentication & Authorization
    // ──────────────────────────────────────

    public function test_safeguarding_index_requires_authentication(): void
    {
        $this->get('/safeguarding')->assertRedirect('/login');
    }

    public function test_safeguarding_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/safeguarding')
            ->assertOk();
    }

    public function test_safeguarding_index_accessible_by_coordinator(): void
    {
        $this->actingAs($this->coordinator)
            ->get('/safeguarding')
            ->assertOk();
    }

    public function test_safeguarding_index_accessible_by_hr(): void
    {
        $this->actingAs($this->hr)
            ->get('/safeguarding')
            ->assertOk();
    }

    public function test_safeguarding_index_blocked_for_support_worker(): void
    {
        // Support workers can create but may not have viewAny
        $this->actingAs($this->supportWorker)
            ->get('/safeguarding')
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Index - Data & Filtering
    // ──────────────────────────────────────

    public function test_safeguarding_index_returns_inertia_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/safeguarding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('safeguarding/index')
                ->has('rows')
                ->has('filters')
                ->has('tab')
                ->has('tabCounts')
                ->has('hero')
            );
    }

    public function test_safeguarding_index_shows_hero_counts(): void
    {
        // Pin severities — the factory randomises severity, which would otherwise
        // make the criticalOpen count non-deterministic.
        SafeguardingConcern::factory()->count(3)->create(['status' => 'reported', 'severity' => 'medium']);
        SafeguardingConcern::factory()->critical()->count(2)->create(['status' => 'investigating']);
        SafeguardingConcern::factory()->closed()->count(1)->create(['severity' => 'low']);

        $this->actingAs($this->admin)
            ->get('/safeguarding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('hero.openWork.awaitingTriage.value', 3)
                ->where('hero.openWork.investigating.value', 2)
                ->where('hero.attention.criticalOpen.value', 2)
                ->has('hero.attention.reviewsDue.value')
            );
    }

    public function test_safeguarding_index_filter_by_tab(): void
    {
        SafeguardingConcern::factory()->count(3)->create(['status' => 'reported']);
        SafeguardingConcern::factory()->count(2)->create(['status' => 'investigating']);

        $this->actingAs($this->admin)
            ->get('/safeguarding?tab=triage')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rowsKind', 'concerns')
                ->has('rows.data', 3)
            );
    }

    public function test_safeguarding_index_filter_by_severity(): void
    {
        SafeguardingConcern::factory()->critical()->count(2)->create();
        SafeguardingConcern::factory()->count(3)->create(['severity' => 'low']);

        $this->actingAs($this->admin)
            ->get('/safeguarding?severity=critical')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 2)
            );
    }

    public function test_safeguarding_index_search(): void
    {
        SafeguardingConcern::factory()->create(['description' => 'Unique safeguarding concern description']);
        SafeguardingConcern::factory()->create(['description' => 'Another concern']);

        $this->actingAs($this->admin)
            ->get('/safeguarding?q=Unique')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
            );
    }

    public function test_safeguarding_index_filter_by_category(): void
    {
        SafeguardingConcern::factory()->count(2)->create(['abuse_category' => 'financial']);
        SafeguardingConcern::factory()->count(3)->create(['abuse_category' => 'physical']);

        $this->actingAs($this->admin)
            ->get('/safeguarding?category=financial')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 2)
            );
    }

    public function test_safeguarding_index_reviews_tab_returns_worklist(): void
    {
        $this->actingAs($this->admin)
            ->get('/safeguarding?tab=reviews')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rowsKind', 'reviews')
                ->has('rows.data')
            );
    }

    public function test_safeguarding_index_paginates(): void
    {
        SafeguardingConcern::factory()->count(25)->create();

        $this->actingAs($this->admin)
            ->get('/safeguarding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 20)
            );
    }

    // ──────────────────────────────────────
    // Create
    // ──────────────────────────────────────

    public function test_safeguarding_create_requires_authentication(): void
    {
        $this->get('/safeguarding/create')->assertRedirect('/login');
    }

    public function test_safeguarding_create_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/safeguarding/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('safeguarding/create')
                ->has('clients')
                ->has('staff')
                ->has('sites')
            );
    }

    public function test_safeguarding_create_accessible_by_support_worker(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/safeguarding/create')
            ->assertOk();
    }

    // ──────────────────────────────────────
    // Store
    // ──────────────────────────────────────

    public function test_safeguarding_store_creates_concern(): void
    {
        $this->actingAs($this->admin)
            ->post('/safeguarding', [
                'concern_type' => 'abuse',
                'severity' => 'high',
                'description' => 'Test safeguarding concern description.',
                'requires_external_referral' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('safeguarding_concerns', [
            'concern_type' => 'abuse',
            'severity' => 'high',
            'reported_by_user_id' => $this->admin->id,
        ]);
    }

    public function test_safeguarding_store_generates_reference_number(): void
    {
        $this->actingAs($this->admin)
            ->post('/safeguarding', [
                'concern_type' => 'neglect',
                'severity' => 'medium',
                'description' => 'Test concern.',
                'requires_external_referral' => false,
            ])
            ->assertRedirect();

        $concern = SafeguardingConcern::latest()->first();
        $this->assertNotNull($concern->reference_number);
        $this->assertStringStartsWith('SG-', $concern->reference_number);
    }

    public function test_safeguarding_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin)
            ->post('/safeguarding', [])
            ->assertSessionHasErrors(['concern_type', 'severity', 'description']);
    }

    public function test_safeguarding_store_validates_severity(): void
    {
        $this->actingAs($this->admin)
            ->post('/safeguarding', [
                'concern_type' => 'abuse',
                'severity' => 'invalid',
                'description' => 'Test',
                'requires_external_referral' => false,
            ])
            ->assertSessionHasErrors(['severity']);
    }

    public function test_safeguarding_store_with_site(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->admin)
            ->post('/safeguarding', [
                'concern_type' => 'abuse',
                'severity' => 'high',
                'description' => 'Concern at site.',
                'site_id' => $site->id,
                'requires_external_referral' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('safeguarding_concerns', [
            'site_id' => $site->id,
        ]);
    }

    public function test_safeguarding_store_with_full_details(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->admin)
            ->post('/safeguarding', [
                'concern_type' => 'exploitation',
                'abuse_category' => 'financial',
                'severity' => 'critical',
                'description' => 'Detailed description of concern.',
                'occurred_at' => now()->subDays(1)->toDateTimeString(),
                'location' => 'Living room',
                'subject_name' => 'Test Subject',
                'alleged_perpetrator_name' => 'Unknown Person',
                'reporter_notes' => 'Observed during shift.',
                'immediate_actions' => 'Separated parties.',
                'requires_external_referral' => true,
                'site_id' => $site->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('safeguarding_concerns', [
            'concern_type' => 'exploitation',
            'abuse_category' => 'financial',
            'severity' => 'critical',
            'requires_external_referral' => true,
        ]);
    }

    // ──────────────────────────────────────
    // Show
    // ──────────────────────────────────────

    public function test_safeguarding_show_requires_authentication(): void
    {
        $concern = SafeguardingConcern::factory()->create();
        $this->get("/safeguarding/{$concern->id}")->assertRedirect('/login');
    }

    public function test_safeguarding_show_accessible_by_admin(): void
    {
        $concern = SafeguardingConcern::factory()->create();

        $this->actingAs($this->admin)
            ->get("/safeguarding/{$concern->id}")
            ->assertOk();
    }

    public function test_safeguarding_show_returns_concern_shell(): void
    {
        $concern = SafeguardingConcern::factory()->create();

        $this->actingAs($this->admin)
            ->get("/safeguarding/{$concern->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('safeguarding/concern')
                ->where('detail.id', $concern->id)
                ->where('detail.restricted', false)
                ->has('detail.can')
            );
    }

    public function test_safeguarding_index_serves_detail_over_list(): void
    {
        $concern = SafeguardingConcern::factory()->create();

        $this->actingAs($this->admin)
            ->get("/safeguarding?concern={$concern->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('safeguarding/index')
                ->where('detail.id', $concern->id)
                ->where('detail.reference_number', $concern->reference_number)
            );
    }

    public function test_safeguarding_show_accessible_by_reporter(): void
    {
        $concern = SafeguardingConcern::factory()->create([
            'reported_by_user_id' => $this->supportWorker->id,
        ]);

        $this->actingAs($this->supportWorker)
            ->get("/safeguarding/{$concern->id}")
            ->assertOk();
    }

    public function test_safeguarding_show_accessible_by_assigned_user(): void
    {
        $concern = SafeguardingConcern::factory()
            ->assignedTo($this->coordinator)
            ->create();

        $this->actingAs($this->coordinator)
            ->get("/safeguarding/{$concern->id}")
            ->assertOk();
    }

    // ──────────────────────────────────────
    // Edit & Update
    // ──────────────────────────────────────

    public function test_safeguarding_edit_accessible_by_admin(): void
    {
        $concern = SafeguardingConcern::factory()->create();

        $this->actingAs($this->admin)
            ->get("/safeguarding/{$concern->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('safeguarding/edit')
                ->has('concern')
                ->has('clients')
                ->has('staff')
                ->has('sites')
            );
    }

    public function test_safeguarding_update_successful(): void
    {
        $concern = SafeguardingConcern::factory()->create([
            'concern_type' => 'abuse',
            'severity' => 'high',
            'description' => 'Original description.',
        ]);

        $this->actingAs($this->admin)
            ->put("/safeguarding/{$concern->id}", [
                'concern_type' => 'neglect',
                'severity' => 'critical',
                'description' => 'Updated description.',
                'requires_external_referral' => true,
            ])
            ->assertRedirect();

        $concern->refresh();
        $this->assertEquals('neglect', $concern->concern_type);
        $this->assertEquals('critical', $concern->severity);
        $this->assertEquals('Updated description.', $concern->description);
    }

    public function test_safeguarding_update_validates_required_fields(): void
    {
        $concern = SafeguardingConcern::factory()->create();

        $this->actingAs($this->admin)
            ->put("/safeguarding/{$concern->id}", [])
            ->assertSessionHasErrors(['concern_type', 'severity', 'description']);
    }

    // ──────────────────────────────────────
    // Assign
    // ──────────────────────────────────────

    public function test_safeguarding_assign_successful(): void
    {
        $concern = SafeguardingConcern::factory()->create();
        $assignee = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($this->admin)
            ->post("/safeguarding/{$concern->id}/assign", [
                'assigned_to_user_id' => $assignee->id,
            ])
            ->assertRedirect();

        $concern->refresh();
        $this->assertEquals($assignee->id, $concern->assigned_to_user_id);
        $this->assertNotNull($concern->assigned_at);
    }

    public function test_safeguarding_assign_validates_user_exists(): void
    {
        $concern = SafeguardingConcern::factory()->create();

        $this->actingAs($this->admin)
            ->post("/safeguarding/{$concern->id}/assign", [
                'assigned_to_user_id' => 99999,
            ])
            ->assertSessionHasErrors(['assigned_to_user_id']);
    }

    // ──────────────────────────────────────
    // Status Update
    // ──────────────────────────────────────

    public function test_safeguarding_update_status(): void
    {
        // action_plan -> monitoring is a legal, ungated forward transition.
        $concern = SafeguardingConcern::factory()->create(['status' => 'action_plan']);

        $this->actingAs($this->admin)
            ->patch("/safeguarding/{$concern->id}/status", [
                'status' => 'monitoring',
            ])
            ->assertRedirect();

        $concern->refresh();
        $this->assertEquals('monitoring', $concern->status);
    }

    public function test_safeguarding_update_status_validates(): void
    {
        $concern = SafeguardingConcern::factory()->create();

        $this->actingAs($this->admin)
            ->patch("/safeguarding/{$concern->id}/status", [
                'status' => 'invalid_status',
            ])
            ->assertSessionHasErrors(['status']);
    }

    public function test_safeguarding_legal_transitions_are_enforced(): void
    {
        // Legal forward transitions succeed.
        foreach ([
            ['action_plan', 'monitoring'],
            ['monitoring', 'action_plan'],
            ['investigating', 'action_plan'],
        ] as [$from, $to]) {
            $concern = SafeguardingConcern::factory()->create(['status' => $from]);

            $this->actingAs($this->admin)
                ->patch("/safeguarding/{$concern->id}/status", ['status' => $to])
                ->assertRedirect();

            $this->assertEquals($to, $concern->fresh()->status);
        }

        // Illegal: a reported concern can't be advanced by updateStatus (triage first).
        $reported = SafeguardingConcern::factory()->create(['status' => 'reported']);

        $this->actingAs($this->admin)
            ->patch("/safeguarding/{$reported->id}/status", ['status' => 'monitoring'])
            ->assertSessionHasErrors('status');

        $this->assertEquals('reported', $reported->fresh()->status);
    }

    // ──────────────────────────────────────
    // Close
    // ──────────────────────────────────────

    public function test_safeguarding_close_successful(): void
    {
        $concern = SafeguardingConcern::factory()->create(['status' => 'monitoring']);

        $this->actingAs($this->admin)
            ->post("/safeguarding/{$concern->id}/close", [
                'closure_summary' => 'Concern resolved after investigation.',
                'lessons_learned' => 'Review staff training.',
            ])
            ->assertRedirect();

        $concern->refresh();
        $this->assertEquals('closed', $concern->status);
        $this->assertEquals('Concern resolved after investigation.', $concern->closure_summary);
        $this->assertEquals('Review staff training.', $concern->lessons_learned);
        $this->assertNotNull($concern->closed_at);
        $this->assertEquals($this->admin->id, $concern->closed_by_user_id);
    }

    public function test_safeguarding_close_requires_closure_summary(): void
    {
        $concern = SafeguardingConcern::factory()->create();

        $this->actingAs($this->admin)
            ->post("/safeguarding/{$concern->id}/close", [])
            ->assertSessionHasErrors(['closure_summary']);
    }

    // ──────────────────────────────────────
    // Mark Subject Informed
    // ──────────────────────────────────────

    public function test_safeguarding_mark_subject_informed(): void
    {
        $concern = SafeguardingConcern::factory()->create(['subject_informed' => false]);

        $this->actingAs($this->admin)
            ->post("/safeguarding/{$concern->id}/subject-informed")
            ->assertRedirect();

        $concern->refresh();
        $this->assertTrue((bool) $concern->subject_informed);
        $this->assertNotNull($concern->subject_informed_at);
    }
}
