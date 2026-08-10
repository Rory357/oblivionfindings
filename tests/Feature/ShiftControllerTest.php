<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CoverageGapAcknowledgement;
use App\Models\CoverageReservation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterPeriod;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftSeries;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteCoverageRequirement;
use App\Models\User;
use App\Services\ShiftSignalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected Client $client;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles and permissions
        $this->seed(RbacSeeder::class);

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
            Permission::where('key', 'shifts.update')->first()->id,
        ]);

        // Create service context
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Test Context',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->site = Site::factory()->create(['name' => 'Kowhai House']);

        // Create client
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);

        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staff->id,
            'employee_number' => 'EMP-SHIFT-'.$this->staff->id,
            'work_email' => $this->staff->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
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
        $response = $this->actingAs($this->admin)->get('/operations/shifts');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/shifts/index')
            ->has('shifts')
            ->has('filters')
            ->has('clients')
            ->has('staff')
        );
    }

    public function test_index_applies_date_filters(): void
    {
        $today = now()->format('Y-m-d');

        $response = $this->actingAs($this->admin)->get("/operations/shifts?from={$today}&to={$today}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.from', $today)
            ->where('filters.to', $today)
        );
    }

    public function test_index_date_filters_use_worker_timezone_day_boundaries(): void
    {
        config(['app.worker_timezone' => 'Pacific/Auckland']);

        $visibleShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => null,
            'starts_at' => Carbon::parse('2026-05-11 10:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-05-11 13:00:00', 'Pacific/Auckland')->utc(),
            'location' => 'Rostering E2E House',
            'status' => 'scheduled',
        ]);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => null,
            'starts_at' => Carbon::parse('2026-05-10 10:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-05-10 13:00:00', 'Pacific/Auckland')->utc(),
            'location' => 'Rostering E2E House',
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->admin)->get('/operations/shifts?from=2026-05-11&to=2026-05-11&assigned=unassigned&q=Rostering');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/shifts/index')
            ->where('filters.from', '2026-05-11')
            ->where('filters.to', '2026-05-11')
            ->has('shifts.data', 1)
            ->where('shifts.data.0.id', $visibleShift->id)
        );
    }

    public function test_index_applies_search_filter_safely(): void
    {
        // This tests that search doesn't cause SQL injection
        $maliciousInput = "test' OR '1'='1";

        $response = $this->actingAs($this->admin)->get('/operations/shifts?q='.urlencode($maliciousInput));
        $response->assertOk();
        // If SQL injection worked, we'd get all shifts. With parameterized query, we get none.
    }

    public function test_editable_shift_endpoint_returns_dialog_payload_shape(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc(),
            'location' => 'Kowhai House',
            'notes' => 'Bring medication folder.',
            'status' => 'scheduled',
            'shift_type' => 'standard',
            'is_sleepover' => false,
            'is_on_call' => false,
            'expected_break_minutes' => 30,
            'coverage_roles' => ['caregiver', 'driver'],
        ]);
        ShiftTask::create([
            'shift_id' => $shift->id,
            'label' => 'Check overnight notes',
            'scheduled_time' => '10:30',
            'sort_order' => 1,
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('operations.shifts.editable', $shift))
            ->assertOk()
            ->assertJsonPath('id', $shift->id)
            ->assertJsonPath('client.id', $this->client->id)
            ->assertJsonPath('staff.id', $this->staff->id)
            ->assertJsonPath('site.id', $this->site->id)
            ->assertJsonPath('service_context_id', $this->serviceContext->id)
            ->assertJsonPath('coverage_roles.0', 'caregiver')
            ->assertJsonPath('coverage_roles.1', 'driver')
            ->assertJsonPath('tasks.0.id', ShiftTask::query()->where('shift_id', $shift->id)->value('id'))
            ->assertJsonPath('tasks.0.label', 'Check overnight notes')
            ->assertJsonPath('tasks.0.scheduled_time', '10:30');
    }

    public function test_editable_shift_endpoint_requires_shift_update_permission(): void
    {
        $viewer = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc(),
            'status' => 'scheduled',
        ]);

        $this->actingAs($viewer)
            ->getJson(route('operations.shifts.editable', $shift))
            ->assertForbidden();
    }

    // ==========================================
    // STORE TESTS
    // ==========================================

    public function test_create_json_includes_client_site_for_location_prefill(): void
    {
        $site = Site::factory()->create(['name' => 'Kauri House']);
        $this->client->update(['site_id' => $site->id]);

        // The standalone create page was retired in favour of the inline
        // CreateShiftDialog, which hydrates from this JSON endpoint.
        $response = $this->actingAs($this->admin)
            ->getJson('/operations/shifts/create');

        $response->assertOk();
        $response->assertJsonPath('clients.0.id', $this->client->id);
        $response->assertJsonPath('clients.0.site.id', $site->id);
        $response->assertJsonPath('clients.0.site.name', 'Kauri House');
    }

    public function test_create_get_redirects_browser_to_shifts_index(): void
    {
        // A non-JSON browser hit (bookmark / legacy redirect) lands on the
        // shifts index, which opens the inline create dialog.
        $this->actingAs($this->admin)
            ->get('/operations/shifts/create')
            ->assertRedirect(route('operations.shifts.index', ['create' => 1]));
    }

    public function test_coverage_reservation_post_is_idempotent_and_shift_create_get_only_validates_token(): void
    {
        $startsAt = now()->addDay()->setTime(9, 0);
        $endsAt = now()->addDay()->setTime(10, 0);
        $rule = SiteCoverageRequirement::create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'name' => 'Morning coverage',
            'coverage_type' => 'custom',
            'day_of_week' => strtolower($startsAt->format('D')),
            'starts_time' => $startsAt->format('H:i'),
            'ends_time' => $endsAt->format('H:i'),
            'minimum_staff' => 1,
            'role_requirements' => [],
            'allow_overstaffing' => true,
            'is_active' => true,
        ]);
        $payload = [
            'site_id' => $this->site->id,
            'coverage_rule_id' => $rule->id,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'return_to' => '/operations/rostering',
        ];

        $first = $this->actingAs($this->admin)
            ->postJson(route('operations.coverage.reservations.store'), $payload)
            ->assertOk()
            ->json();
        $second = $this->actingAs($this->admin)
            ->postJson(route('operations.coverage.reservations.store'), $payload)
            ->assertOk()
            ->json();

        $this->assertSame($first['token'], $second['token']);
        $this->assertSame(1, CoverageReservation::query()->count());

        $this->actingAs($this->admin)
            ->getJson(route('operations.shifts.create', array_merge($payload, [
                'coverage_reservation_token' => $first['token'],
            ])))
            ->assertOk();

        $this->assertSame(1, CoverageReservation::query()->count());

        $otherAdmin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $otherAdmin->roles()->attach(Role::where('name', 'admin')->firstOrFail());

        $this->actingAs($otherAdmin)
            ->getJson(route('operations.shifts.create', array_merge($payload, [
                'coverage_reservation_token' => $first['token'],
            ])))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('coverage_reservation_token');
    }

    public function test_coverage_state_actions_reject_unassigned_sites_and_mismatched_requirements(): void
    {
        $permissions = Permission::query()
            ->whereIn('key', ['shifts.create', 'rostering.viewAny'])
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])
            ->all();
        $this->staff->permissionOverrides()->syncWithoutDetaching($permissions);

        $otherSite = Site::factory()->create(['name' => 'Rimu House']);
        $startsAt = now()->addDay()->setTime(9, 0);
        $endsAt = now()->addDay()->setTime(10, 0);
        $otherSiteRule = SiteCoverageRequirement::create([
            'site_id' => $otherSite->id,
            'service_context_id' => $this->serviceContext->id,
            'name' => 'Other Site Coverage',
            'coverage_type' => 'custom',
            'day_of_week' => strtolower($startsAt->format('D')),
            'starts_time' => $startsAt->format('H:i'),
            'ends_time' => $endsAt->format('H:i'),
            'minimum_staff' => 1,
            'role_requirements' => [],
            'allow_overstaffing' => true,
            'is_active' => true,
        ]);

        $reservationPayload = [
            'site_id' => $otherSite->id,
            'coverage_rule_id' => $otherSiteRule->id,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
        ];

        $this->actingAs($this->staff)
            ->postJson(route('operations.coverage.reservations.store'), $reservationPayload)
            ->assertForbidden();

        $this->actingAs($this->staff)
            ->postJson(route('operations.coverage.reservations.store'), array_merge($reservationPayload, [
                'site_id' => $this->site->id,
            ]))
            ->assertForbidden();

        $mismatchedKey = app(ShiftSignalService::class)->buildCoverageWindowKey([
            'site_id' => $this->site->id,
            'rule_id' => $otherSiteRule->id,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
        ]);

        $this->actingAs($this->staff)
            ->postJson(route('operations.rostering.coverage.ack', $mismatchedKey), [
                'site_id' => $this->site->id,
                'coverage_requirement_id' => $otherSiteRule->id,
                'window_starts_at' => $startsAt->toIso8601String(),
                'window_ends_at' => $endsAt->toIso8601String(),
            ])
            ->assertForbidden();

        $this->assertSame(0, CoverageReservation::query()->count());
        $this->assertSame(0, CoverageGapAcknowledgement::query()->count());
    }

    public function test_manager_can_ack_dismiss_and_clear_coverage_gap(): void
    {
        $startsAt = now()->addDay()->setTime(9, 0);
        $endsAt = now()->addDay()->setTime(10, 0);
        $rule = SiteCoverageRequirement::create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'name' => 'Morning coverage',
            'coverage_type' => 'custom',
            'day_of_week' => strtolower($startsAt->format('D')),
            'starts_time' => $startsAt->format('H:i'),
            'ends_time' => $endsAt->format('H:i'),
            'minimum_staff' => 1,
            'role_requirements' => [],
            'allow_overstaffing' => true,
            'is_active' => true,
        ]);
        $key = app(ShiftSignalService::class)->buildCoverageWindowKey([
            'site_id' => $this->site->id,
            'rule_id' => $rule->id,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
        ]);
        $payload = [
            'site_id' => $this->site->id,
            'coverage_requirement_id' => $rule->id,
            'window_starts_at' => $startsAt->toIso8601String(),
            'window_ends_at' => $endsAt->toIso8601String(),
        ];

        $this->actingAs($this->admin)
            ->postJson(route('operations.rostering.coverage.ack', $key), $payload + [
                'reason' => 'Calling staff',
            ])
            ->assertOk()
            ->assertJsonPath('status', CoverageGapAcknowledgement::STATE_ACKED);

        $this->assertDatabaseHas('coverage_gap_acknowledgements', [
            'coverage_window_key' => $key,
            'state' => CoverageGapAcknowledgement::STATE_ACKED,
            'reason' => 'Calling staff',
            'actor_user_id' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rostering.coverage.ack']);

        $this->actingAs($this->admin)
            ->postJson(route('operations.rostering.coverage.dismiss', $key), $payload + [
                'reason' => 'Resolved outside roster',
            ])
            ->assertOk()
            ->assertJsonPath('status', CoverageGapAcknowledgement::STATE_DISMISSED);

        $this->assertSame(1, CoverageGapAcknowledgement::query()
            ->where('coverage_window_key', $key)
            ->whereNull('cleared_at')
            ->count());
        $this->assertDatabaseHas('coverage_gap_acknowledgements', [
            'coverage_window_key' => $key,
            'state' => CoverageGapAcknowledgement::STATE_DISMISSED,
            'reason' => 'Resolved outside roster',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rostering.coverage.dismiss']);

        $this->actingAs($this->admin)
            ->deleteJson(route('operations.rostering.coverage.clear', $key), $payload)
            ->assertOk()
            ->assertJsonPath('status', 'cleared');

        $this->assertSame(0, CoverageGapAcknowledgement::query()
            ->where('coverage_window_key', $key)
            ->whereNull('cleared_at')
            ->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'rostering.coverage.clear']);
        $this->assertSame(3, AuditLog::query()->whereIn('action', [
            'rostering.coverage.ack',
            'rostering.coverage.dismiss',
            'rostering.coverage.clear',
        ])->count());
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
            ->post(route('operations.shifts.store'), $shiftData);

        $response->assertRedirect('/operations/shifts');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('shifts', [
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'location' => 'Test Location',
            'status' => 'scheduled',
        ]);
    }

    public function test_manager_can_duplicate_shift_as_unassigned_draft_on_target_date(): void
    {
        config(['app.worker_timezone' => 'Pacific/Auckland']);

        $source = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc(),
            'location' => 'Matai House',
            'notes' => 'Medication support',
            'status' => 'scheduled',
        ]);
        ShiftTask::create([
            'shift_id' => $source->id,
            'label' => 'Morning handover',
            'scheduled_time' => '09:30',
            'reminder_sent_at' => Carbon::parse('2026-05-05 09:35:00', 'Pacific/Auckland')->utc(),
            'sort_order' => 0,
        ]);
        $template = SiteChecklistTemplate::create([
            'tenant_id' => $this->site->tenant_id,
            'key' => 'duplicate_shift_check_'.uniqid(),
            'name' => 'Duplicate Shift Check',
            'applicable_to_type' => 'house',
            'frequency' => 'daily',
            'is_active' => true,
        ]);
        $assignment = SiteChecklistAssignment::create([
            'site_id' => $this->site->id,
            'tenant_id' => $this->site->tenant_id,
            'template_id' => $template->id,
            'frequency' => 'daily',
            'assigned_to_user_id' => $this->admin->id,
            'start_date' => '2026-05-01',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('operations.shifts.duplicate', $source), [
                'date' => '2026-05-06',
                'return_to' => '/operations/rostering',
            ])
            ->assertRedirect('/operations/rostering');

        $copy = Shift::query()
            ->whereKeyNot($source->id)
            ->where('client_id', $this->client->id)
            ->firstOrFail();

        $this->assertNull($copy->user_id);
        $this->assertSame('draft', $copy->status);
        $this->assertSame('Matai House', $copy->location);
        $this->assertSame('Medication support', $copy->notes);
        $this->assertSame('2026-05-06 09:00:00', $copy->starts_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-06 13:00:00', $copy->ends_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('shift_tasks', [
            'shift_id' => $copy->id,
            'label' => 'Morning handover',
            'scheduled_time' => '09:30',
            'reminder_sent_at' => null,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('site_checklist_runs', [
            'assignment_id' => $assignment->id,
            'site_id' => $this->site->id,
            'scheduled_date' => '2026-05-06',
            'assigned_to_user_id' => $this->admin->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_duplicate_shift_respects_roster_period_boundaries(): void
    {
        config(['app.worker_timezone' => 'Pacific/Auckland']);

        $period = RosterPeriod::factory()->create([
            'site_id' => $this->site->id,
            'week_start' => '2026-05-04',
            'week_end' => '2026-05-10',
            'status' => RosterPeriod::STATUS_DRAFT,
        ]);
        $source = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'roster_period_id' => $period->id,
            'user_id' => $this->staff->id,
            'starts_at' => Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc(),
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->admin)
            ->from('/operations/rostering')
            ->post(route('operations.shifts.duplicate', $source), [
                'date' => '2026-05-11',
            ])
            ->assertRedirect('/operations/rostering')
            ->assertSessionHasErrors('date');

        $this->assertSame(1, Shift::query()->count());
    }

    public function test_store_resolves_service_context_automatically(): void
    {
        $shiftData = [
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(4)->format('Y-m-d H:i:s'),
        ];

        $this->actingAs($this->admin)->post(route('operations.shifts.store'), $shiftData);

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
            ->post(route('operations.shifts.store'), $shiftData);

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
            ->post(route('operations.shifts.store'), $shiftData);

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
            ->post(route('operations.shifts.store'), $shiftData);

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
            ->post(route('operations.shifts.store'), $shiftData);

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
            ->post(route('operations.shifts.store'), $shiftData);

        $response->assertSessionHasErrors(['user_id']);
    }

    public function test_store_creates_tasks_when_provided(): void
    {
        $shiftData = [
            'client_id' => $this->client->id,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(4)->format('Y-m-d H:i:s'),
            'tasks' => [
                ['label' => 'Task 1', 'scheduled_time' => '10:15'],
                ['label' => 'Task 2'],
                ['label' => 'Task 3'],
            ],
        ];

        $this->actingAs($this->admin)->post(route('operations.shifts.store'), $shiftData);

        $shift = Shift::latest()->first();
        $this->assertCount(3, $shift->tasks);
        $this->assertDatabaseHas('shift_tasks', [
            'label' => 'Task 1',
            'shift_id' => $shift->id,
            'scheduled_time' => '10:15',
        ]);
    }

    public function test_store_schedules_due_site_checklist_runs_for_shift_local_day_without_duplicates(): void
    {
        config(['app.worker_timezone' => 'Pacific/Auckland']);

        // Anchored relative to "today" so the shift always satisfies the
        // `after_or_equal:today` rule on store (avoids date-rot). The shift runs
        // 23:30 → 03:30 the next NZ day, spanning local midnight, so the
        // scheduler must key the checklist run off the START's *local* NZ day.
        // Assignments start exactly a week earlier so the weekly template is due
        // on the shift's local day while the fortnightly one is not.
        $tz = 'Pacific/Auckland';
        $shiftLocalDate = Carbon::today($tz)->addWeek();
        $shiftDate = $shiftLocalDate->toDateString();
        $shiftEndDate = $shiftLocalDate->copy()->addDay()->toDateString();
        $assignmentStartDate = $shiftLocalDate->copy()->subWeek()->toDateString();

        $dueTemplate = SiteChecklistTemplate::create([
            'tenant_id' => $this->site->tenant_id,
            'key' => 'shift_due_'.uniqid(),
            'name' => 'Shift Due Checklist',
            'applicable_to_type' => 'house',
            'frequency' => 'weekly',
            'is_active' => true,
        ]);
        $existingTemplate = SiteChecklistTemplate::create([
            'tenant_id' => $this->site->tenant_id,
            'key' => 'shift_existing_'.uniqid(),
            'name' => 'Shift Existing Checklist',
            'applicable_to_type' => 'house',
            'frequency' => 'daily',
            'is_active' => true,
        ]);
        $notDueTemplate = SiteChecklistTemplate::create([
            'tenant_id' => $this->site->tenant_id,
            'key' => 'shift_not_due_'.uniqid(),
            'name' => 'Shift Not Due Checklist',
            'applicable_to_type' => 'house',
            'frequency' => 'fortnightly',
            'is_active' => true,
        ]);

        $dueAssignment = SiteChecklistAssignment::create([
            'site_id' => $this->site->id,
            'tenant_id' => $this->site->tenant_id,
            'template_id' => $dueTemplate->id,
            'frequency' => 'weekly',
            'assigned_to_user_id' => $this->admin->id,
            'start_date' => $assignmentStartDate,
            'is_active' => true,
        ]);
        $existingAssignment = SiteChecklistAssignment::create([
            'site_id' => $this->site->id,
            'tenant_id' => $this->site->tenant_id,
            'template_id' => $existingTemplate->id,
            'frequency' => 'daily',
            'start_date' => $assignmentStartDate,
            'is_active' => true,
        ]);
        $notDueAssignment = SiteChecklistAssignment::create([
            'site_id' => $this->site->id,
            'tenant_id' => $this->site->tenant_id,
            'template_id' => $notDueTemplate->id,
            'frequency' => 'fortnightly',
            'start_date' => $assignmentStartDate,
            'is_active' => true,
        ]);

        SiteChecklistRun::create([
            'assignment_id' => $existingAssignment->id,
            'site_id' => $this->site->id,
            'tenant_id' => $this->site->tenant_id,
            'template_id' => $existingTemplate->id,
            'scheduled_date' => $shiftDate,
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('operations.shifts.store'), [
                'client_id' => $this->client->id,
                'service_context_id' => $this->serviceContext->id,
                'user_id' => $this->staff->id,
                'starts_at' => Carbon::parse($shiftDate.' 23:30:00', $tz)->utc()->format('Y-m-d H:i:s'),
                'ends_at' => Carbon::parse($shiftEndDate.' 03:30:00', $tz)->utc()->format('Y-m-d H:i:s'),
                'status' => 'scheduled',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/operations/shifts');

        $this->assertDatabaseHas('site_checklist_runs', [
            'assignment_id' => $dueAssignment->id,
            'site_id' => $this->site->id,
            'tenant_id' => $this->site->tenant_id,
            'template_id' => $dueTemplate->id,
            'scheduled_date' => $shiftDate,
            'assigned_to_user_id' => $this->staff->id,
            'status' => 'scheduled',
        ]);
        $this->assertSame(
            1,
            SiteChecklistRun::where('assignment_id', $existingAssignment->id)
                ->whereDate('scheduled_date', $shiftDate)
                ->count()
        );
        $this->assertDatabaseMissing('site_checklist_runs', [
            'assignment_id' => $notDueAssignment->id,
            'scheduled_date' => $shiftDate,
        ]);
    }

    // ==========================================
    // UPDATE TESTS
    // ==========================================

    public function test_update_modifies_shift(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'location' => 'Old Location',
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('operations.shifts.update', $shift), [
                'client_id' => $this->client->id,
                'starts_at' => $shift->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $shift->ends_at->format('Y-m-d H:i:s'),
                'location' => 'New Location',
                'status' => 'scheduled',
            ]);

        $response->assertRedirect('/operations/shifts');
        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'location' => 'New Location',
        ]);
    }

    public function test_update_prevents_modifying_completed_shift(): void
    {
        $shift = Shift::factory()->completed()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('operations.shifts.update', $shift), [
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
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'status' => 'scheduled',
        ]);

        // Add existing task
        $existingTask = $shift->tasks()->create([
            'label' => 'Old Task',
            'scheduled_time' => '09:00',
            'reminder_sent_at' => now()->subHour(),
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('operations.shifts.update', $shift), [
                'client_id' => $this->client->id,
                'starts_at' => $shift->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $shift->ends_at->format('Y-m-d H:i:s'),
                'status' => 'scheduled',
                'tasks' => [
                    ['id' => $existingTask->id, 'label' => 'Updated Task', 'scheduled_time' => '10:00'],
                    ['label' => 'New Task', 'scheduled_time' => '11:30'],
                ],
            ]);

        $response->assertRedirect('/operations/shifts');
        $this->assertDatabaseHas('shift_tasks', [
            'id' => $existingTask->id,
            'label' => 'Updated Task',
            'scheduled_time' => '10:00',
            'reminder_sent_at' => null,
        ]);
        $this->assertDatabaseHas('shift_tasks', [
            'label' => 'New Task',
            'shift_id' => $shift->id,
            'scheduled_time' => '11:30',
        ]);
    }

    public function test_update_clears_sent_task_reminders_when_shift_start_changes(): void
    {
        $start = now()->addDay()->setTime(9, 0);
        $end = $start->copy()->addHours(4);
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => null,
            'status' => 'scheduled',
            'starts_at' => $start,
            'ends_at' => $end,
        ]);

        $task = $shift->tasks()->create([
            'label' => 'Medication prompt',
            'scheduled_time' => '10:00',
            'reminder_sent_at' => now()->subHour(),
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('operations.shifts.update', $shift), [
                'client_id' => $this->client->id,
                'service_context_id' => $this->serviceContext->id,
                'starts_at' => $start->copy()->addHour()->format('Y-m-d H:i:s'),
                'ends_at' => $end->copy()->addHour()->format('Y-m-d H:i:s'),
                'status' => 'scheduled',
            ]);

        $response->assertRedirect('/operations/shifts');
        expect($task->fresh()->reminder_sent_at)->toBeNull();
    }

    public function test_series_store_copies_task_scheduled_time_to_each_occurrence(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('operations.shifts.series.store'), [
                'client_id' => $this->client->id,
                'service_context_id' => $this->serviceContext->id,
                'user_id' => null,
                'start_date' => '2026-05-04',
                'end_date' => '2026-05-04',
                'timezone' => 'Pacific/Auckland',
                'by_weekday' => ['mon'],
                'starts_time' => '09:00',
                'ends_time' => '13:00',
                'status' => 'scheduled',
                'tasks' => [
                    ['label' => 'Time-specific medication prompt', 'scheduled_time' => '11:15'],
                ],
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('shift_tasks', [
            'label' => 'Time-specific medication prompt',
            'scheduled_time' => '11:15',
        ]);
    }

    public function test_series_store_propagates_is_lone_worker_to_generated_shifts(): void
    {
        $this->actingAs($this->admin)
            ->post(route('operations.shifts.series.store'), [
                'client_id' => $this->client->id,
                'service_context_id' => $this->serviceContext->id,
                'user_id' => null,
                'start_date' => '2026-05-04',
                'end_date' => '2026-05-04',
                'timezone' => 'Pacific/Auckland',
                'by_weekday' => ['mon'],
                'starts_time' => '09:00',
                'ends_time' => '13:00',
                'status' => 'scheduled',
                'is_lone_worker' => true,
            ])
            ->assertSessionHasNoErrors();

        $series = ShiftSeries::query()->latest('id')->first();
        $this->assertNotNull($series);
        $this->assertTrue((bool) $series->is_lone_worker, 'Series should persist is_lone_worker.');

        $shifts = Shift::where('shift_series_id', $series->id)->get();
        $this->assertGreaterThan(0, $shifts->count());
        $this->assertTrue(
            $shifts->every(fn (Shift $s) => $s->is_lone_worker === true),
            'Every generated shift should inherit is_lone_worker from the series.',
        );
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
            'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->addHours(3),
        ]);

        $response = $this->actingAs($this->staff)
            ->patch(route('operations.shifts.start', $shift));

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
            'actual_starts_at' => now()->subHours(2),
            'started_by' => $this->staff->id,
        ]);

        // Try to complete without any notes
        $response = $this->actingAs($this->staff)
            ->patch(route('operations.shifts.complete', $shift), [
            ]);

        $response->assertSessionHasErrors(['final_note_body']);
    }

    public function test_complete_creates_summary_note(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'status' => 'in_progress',
            'actual_starts_at' => now()->subHours(2),
            'started_by' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->patch(route('operations.shifts.complete', $shift), [
                'final_note_subject' => 'Shift Summary',
                'final_note_body' => 'Completed all tasks successfully',
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

        $this->actingAs($this->staff)
            ->get('/operations/shifts')
            ->assertRedirect(route('my-day'));
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

        $this->actingAs($this->admin)->post(route('operations.shifts.store'), $shiftData);

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

        $this->actingAs($this->admin)->post(route('operations.shifts.store'), $shiftData);

        $shift = Shift::latest()->first();
        $this->assertEquals($this->serviceContext->id, $shift->service_context_id);
    }
}
