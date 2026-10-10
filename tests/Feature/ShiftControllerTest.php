<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\CoverageGapController;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CoverageGapAcknowledgement;
use App\Models\CoverageReservation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterPeriod;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftEligibilityOverride;
use App\Models\ShiftSeries;
use App\Models\ShiftSignalOutbox;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteCoverageRequirement;
use App\Models\StaffAvailability;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Eligibility\AssignmentEligibilityDecision;
use App\Services\Eligibility\EligibilityResult;
use App\Services\ShiftCoverageService;
use App\Services\ShiftSignalService;
use App\Services\ShiftStaffEligibilityService;
use App\Services\UserSiteAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
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
        $startsAt = now((string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC')))->addDay()->setTime(9, 0);
        $endsAt = $startsAt->copy()->setTime(10, 0);
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

    public function test_coverage_review_root_results_bind_the_recorded_window_without_resolving_staffing(): void
    {
        Queue::fake();
        [$key, $payload, $rule] = $this->coverageReviewWindow();
        $coverage = app(ShiftCoverageService::class);
        $beforeWindows = $coverage->buildRangeCoverage(Carbon::parse($payload['window_starts_at']), Carbon::parse($payload['window_ends_at']), $this->site->id);
        $this->assertCount(1, $beforeWindows);
        $this->assertSame(1, $beforeWindows[0]['missing_staff']);
        $this->withCommittedCoverageReview(function () use ($key, $payload, $rule, $coverage, $beforeWindows): void {
            $queue = Queue::getFacadeRoot()->pushedJobs();
            $ruleBefore = $rule->fresh()->getRawOriginal();
            $prior = $this->coverageReviewState();
            $previous = null;
            foreach (['ack', 'dismiss', 'clear', 'clear'] as $index => $action) {
                $requestId = (string) Str::uuid();
                $body = $payload + ['request_id' => $requestId, 'reason' => 'Review recorded; staffing still needs attention.'];
                $response = $action === 'clear'
                    ? $this->actingAs($this->admin)->deleteJson(route('operations.rostering.coverage.clear', $key), $body)
                    : $this->actingAs($this->admin)->postJson(route('operations.rostering.coverage.'.$action, $key), $body);
                $response->assertOk();
                $result = $response->json('result');
                $this->assertIsArray($result);
                $this->assertSame(1, $result['version']);
                $this->assertSame('coverage_gap', $result['scope']);
                $this->assertSame($this->admin->id, $result['actor_id']);
                $this->assertSame($action, $result['action']);
                $this->assertSame($requestId, $result['request_id']);
                $this->assertSame(['site_id' => $this->site->id, 'coverage_requirement_id' => $rule->id, 'coverage_window_key' => $key,
                    'window_starts_at' => Carbon::parse($payload['window_starts_at'])->utc()->toISOString(),
                    'window_ends_at' => Carbon::parse($payload['window_ends_at'])->utc()->toISOString()], $result['window']);
                $this->assertSame(['window_starts_at' => Carbon::parse($payload['window_starts_at'])->toDateTimeString(),
                    'window_ends_at' => Carbon::parse($payload['window_ends_at'])->toDateTimeString()], $result['stored_window']);
                $this->assertSame($index < 2 ? 'recorded' : ($index === 2 ? 'cleared' : 'unchanged'), $result['outcome']);
                $this->assertSame($index < 3, $result['changed']);
                $this->assertFalse($result['staffing_resolved']);
                $this->assertSame($previous ? [$previous->id] : [], $result['cleared_ids']);
                if ($previous) {
                    $this->assertNotNull($previous->fresh()->cleared_at);
                }
                $row = $index < 2 ? CoverageGapAcknowledgement::findOrFail($result['acknowledgement_id']) : null;
                $this->assertSame($row?->id, $result['acknowledgement_id']);
                $this->assertSame($row?->state, $result['state']);
                $this->assertSame($row?->reason, $result['reason']);
                $this->assertSame($index < 2 ? 1 : 0, CoverageGapAcknowledgement::where('coverage_window_key', $key)->whereNull('cleared_at')->count());
                if ($row) {
                    $this->assertSame($result['stored_window']['window_starts_at'], $row->getRawOriginal('window_starts_at'));
                    $this->assertSame($result['stored_window']['window_ends_at'], $row->getRawOriginal('window_ends_at'));
                }
                $audit = AuditLog::findOrFail($result['audit_id']);
                $this->assertSame($this->admin->id, $audit->user_id);
                $this->assertSame('rostering.coverage.'.$action, $audit->action);
                $this->assertSame($key, $audit->meta['coverage_window_key']);
                $this->assertSame(count($prior['audits']) + $index + 1, AuditLog::where('action', 'like', 'rostering.coverage.%')->count());
                foreach ($prior['reviews'] as $original) {
                    $this->assertSame($original, CoverageGapAcknowledgement::findOrFail($original['id'])->getRawOriginal());
                }
                foreach ($prior['audits'] as $original) {
                    $this->assertSame($original, AuditLog::findOrFail($original['id'])->getRawOriginal());
                }
                $this->assertSame($result, session('coverage_gap_result'));
                $windows = $coverage->buildRangeCoverage(Carbon::parse($payload['window_starts_at']), Carbon::parse($payload['window_ends_at']), $this->site->id);
                $this->assertCount(1, $windows);
                $this->assertSame($beforeWindows[0]['missing_staff'], $windows[0]['missing_staff']);
                $this->assertSame($beforeWindows[0]['has_actionable_gap'], $windows[0]['has_actionable_gap']);
                $this->assertSame($row?->state, $windows[0]['acknowledgement']['state'] ?? null);
                $this->assertSame($ruleBefore, $rule->fresh()->getRawOriginal());
                $this->assertSame($queue, Queue::getFacadeRoot()->pushedJobs());
                $previous = $row;
            }
        });
    }

    public static function coverageReviewVetoes(): array
    {
        return ['ack creation refused' => ['ack', 'create_false'], 'ack values altered' => ['ack', 'create_altered'],
            'dismiss clears then creation refused' => ['dismiss', 'create_false'], 'dismiss prior clear refused' => ['dismiss', 'clear_false'],
            'clear refused' => ['clear', 'clear_false'], 'audit creation refused' => ['ack', 'audit_false'],
            'audit metadata altered' => ['dismiss', 'audit_altered'], 'late audit changes prior row' => ['clear', 'late_row_altered']];
    }

    #[DataProvider('coverageReviewVetoes')]
    public function test_coverage_review_veto_or_changed_readback_rolls_back_the_complete_action(string $action, string $fault): void
    {
        Queue::fake();
        [$key, $payload] = $this->coverageReviewWindow();
        $old = CoverageGapAcknowledgement::create($payload + ['coverage_window_key' => $key, 'state' => 'acked',
            'reason' => 'Prior review', 'actor_user_id' => $this->admin->id, 'created_at' => now()->subMinute()]);
        $before = $this->coverageReviewState();
        $queue = Queue::getFacadeRoot()->pushedJobs();
        $events = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $events);
        try {
            CoverageGapAcknowledgement::saving(function ($row) use ($fault, $key) {
                if ($row->coverage_window_key !== $key) {
                    return;
                }
                if ($fault === 'clear_false' && $row->exists && $row->isDirty('cleared_at')) {
                    return false;
                }
                if (! $row->exists && $fault === 'create_false') {
                    return false;
                }
                if (! $row->exists && $fault === 'create_altered') {
                    $row->reason = 'Unexpected alteration';
                }
            });
            AuditLog::saving(function ($audit) use ($fault, $key, $old) {
                if (($audit->meta['coverage_window_key'] ?? null) !== $key) {
                    return;
                }
                if ($fault === 'audit_false') {
                    return false;
                }
                if ($fault === 'audit_altered') {
                    $audit->meta = [...$audit->meta, 'site_id' => 999999];
                }
                if ($fault === 'late_row_altered') {
                    DB::table('coverage_gap_acknowledgements')->where('id', $old->id)->update(['reason' => 'Changed after clear']);
                }
            });
            $this->withSession(['coverage_gap_result' => ['actor_id' => $this->admin->id, 'request_id' => 'stale']]);
            $body = $payload + ['reason' => 'Current review', 'request_id' => (string) Str::uuid()];
            ($action === 'clear' ? $this->actingAs($this->admin)->deleteJson(route('operations.rostering.coverage.clear', $key), $body)
                : $this->actingAs($this->admin)->postJson(route('operations.rostering.coverage.'.$action, $key), $body))
                ->assertUnprocessable()->assertJsonValidationErrors('coverage_window_key');
            $this->assertSame($before, $this->coverageReviewState());
            $this->assertSame($queue, Queue::getFacadeRoot()->pushedJobs());
            $this->assertNull(session('coverage_gap_result'));
        } finally {
            Model::setEventDispatcher($events);
        }
    }

    public static function coverageReviewCurrentChanges(): array
    {
        return ['grant revoked' => ['grant'], 'account no longer approved' => ['approval'], 'site archived' => ['site'],
            'rule moved to another site' => ['rule'], 'site profile moved with no reports bypass' => ['profile']];
    }

    #[DataProvider('coverageReviewCurrentChanges')]
    public function test_coverage_review_uses_current_authority_and_source_over_a_primed_snapshot(string $change): void
    {
        Queue::fake();
        [$key, $payload, $rule] = $this->coverageReviewWindow();
        $foreign = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $actor = $this->admin;
        $grant = Permission::where('key', 'rostering.viewAny')->firstOrFail();
        $actor->permissionOverrides()->syncWithoutDetaching([$grant->id => ['allowed' => true]]);
        if ($change === 'profile') {
            $this->giveAdminCurrentHrProfile();
            $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'reports.viewAny')->firstOrFail()->id => ['allowed' => false]]);
        }
        $this->withCommittedCoverageReview(function () use ($change, $key, $payload, $rule, $foreign, $actor, $grant): void {
            $primary = DB::connection();
            config(['database.connections.coverage_review_writer' => [...$primary->getConfig(), 'name' => 'coverage_review_writer']]);
            $writer = DB::connection('coverage_review_writer');
            $this->assertNotSame($primary->getPdo(), $writer->getPdo());
            $this->assertSame($primary->selectOne('SELECT DATABASE() AS db')->db, $writer->selectOne('SELECT DATABASE() AS db')->db);
            $primary->beginTransaction();
            try {
                $stale = User::findOrFail($actor->id)->load(['permissionOverrides', 'roles.permissions', 'hrEmployeeProfile']);
                $this->assertTrue($stale->isApproved());
                $this->assertTrue($stale->canDo('rostering.viewAny'));
                $this->assertContains($this->site->id, app(UserSiteAccessService::class)->accessibleSiteIds($stale, ['reports.viewAny']));
                $this->assertSame($this->site->id, SiteCoverageRequirement::findOrFail($rule->id)->site_id);
                $writer->transaction(function () use ($writer, $change, $actor, $grant, $rule, $foreign): void {
                    $affected = match ($change) {
                        'grant' => $writer->table('permission_user')->where('user_id', $actor->id)->where('permission_id', $grant->id)->update(['allowed' => false]),
                        'approval' => $writer->table('users')->where('id', $actor->id)->update(['approved_at' => null]),
                        'site' => $writer->table('sites')->where('id', $this->site->id)->update(['archived' => true, 'archived_at' => now()->toDateTimeString()]),
                        'rule' => $writer->table('site_coverage_requirements')->where('id', $rule->id)->update(['site_id' => $foreign->id]),
                        'profile' => $writer->table('hr_employee_profiles')->where('user_id', $actor->id)->update(['primary_site_id' => $foreign->id]),
                    };
                    $this->assertSame(1, $affected);
                });
                $this->assertSame(0, $writer->transactionLevel());
                $this->assertFalse($writer->getPdo()->inTransaction());
                $currentValue = match ($change) {
                    'grant' => (bool) $writer->table('permission_user')->where('user_id', $actor->id)->where('permission_id', $grant->id)->value('allowed'),
                    'approval' => $writer->table('users')->where('id', $actor->id)->value('approved_at'),
                    'site' => (bool) $writer->table('sites')->where('id', $this->site->id)->value('archived'),
                    'rule' => (int) $writer->table('site_coverage_requirements')->where('id', $rule->id)->value('site_id'),
                    'profile' => (int) $writer->table('hr_employee_profiles')->where('user_id', $actor->id)->value('primary_site_id'),
                };
                $this->assertSame(match ($change) {
                    'grant' => false, 'approval' => null, 'site' => true, default => $foreign->id
                }, $currentValue);
                $this->assertTrue(User::findOrFail($actor->id)->canDo('rostering.viewAny'));
                $this->assertTrue(User::findOrFail($actor->id)->isApproved());
                $this->assertFalse(Site::findOrFail($this->site->id)->archived);
                $this->assertSame($this->site->id, SiteCoverageRequirement::findOrFail($rule->id)->site_id);
                if ($change === 'profile') {
                    $this->assertSame($this->site->id, HrEmployeeProfile::where('user_id', $actor->id)->firstOrFail()->primary_site_id);
                }
                $before = $this->coverageReviewState();
                $queue = Queue::getFacadeRoot()->pushedJobs();
                $this->actingAs($stale)->postJson(route('operations.rostering.coverage.ack', $key), $payload)->assertForbidden();
                $this->assertSame($before, $this->coverageReviewState());
                $this->assertSame($queue, Queue::getFacadeRoot()->pushedJobs());
                $this->assertNull(session('coverage_gap_result'));
                if ($change === 'grant' || $change === 'approval') {
                    $unknown = [...$payload, 'site_id' => 999999];
                    $unknownKey = app(ShiftSignalService::class)->buildCoverageWindowKey(['site_id' => 999999, 'rule_id' => $rule->id,
                        'starts_at' => $payload['window_starts_at'], 'ends_at' => $payload['window_ends_at']]);
                    $this->actingAs($stale)->postJson(route('operations.rostering.coverage.ack', $unknownKey), $unknown)->assertForbidden();
                    $this->assertSame($before, $this->coverageReviewState());
                    $this->assertSame($queue, Queue::getFacadeRoot()->pushedJobs());
                }
            } finally {
                $primary->rollBack();
                DB::purge('coverage_review_writer');
            }
        });
    }

    public function test_nested_coverage_review_has_no_committed_result_and_outer_rollback_preserves_rows(): void
    {
        Queue::fake();
        [$key, $payload] = $this->coverageReviewWindow();
        $before = $this->coverageReviewState();
        $queue = Queue::getFacadeRoot()->pushedJobs();
        DB::beginTransaction();
        try {
            $this->actingAs($this->admin)->postJson(route('operations.rostering.coverage.ack', $key), $payload)
                ->assertOk()->assertJsonPath('status', 'acked')->assertJsonPath('result', null);
            $this->assertNull(session('coverage_gap_result'));
            $this->assertSame(count($before['reviews']) + 1, CoverageGapAcknowledgement::count());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->coverageReviewState());
        $this->assertSame($queue, Queue::getFacadeRoot()->pushedJobs());
    }

    public function test_coverage_review_raw_pdo_boundary_refuses_without_a_false_result(): void
    {
        Queue::fake();
        [$key, $payload] = $this->coverageReviewWindow();
        $this->withCommittedCoverageReview(function () use ($key, $payload): void {
            $before = $this->coverageReviewState();
            $queue = Queue::getFacadeRoot()->pushedJobs();
            $pdo = DB::connection()->getPdo();
            $pdo->beginTransaction();
            try {
                $this->actingAs($this->admin)->postJson(route('operations.rostering.coverage.ack', $key), $payload)->assertConflict();
                $this->assertTrue($pdo->inTransaction());
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame($before, $this->coverageReviewState());
                $this->assertSame($queue, Queue::getFacadeRoot()->pushedJobs());
                $this->assertNull(session('coverage_gap_result'));
            } finally {
                $pdo->rollBack();
            }
        });
    }

    public function test_coverage_review_invalid_input_clears_stale_result_and_window_tuple_must_match(): void
    {
        Queue::fake();
        [$key, $payload] = $this->coverageReviewWindow();
        $before = $this->coverageReviewState();
        $queue = Queue::getFacadeRoot()->pushedJobs();
        foreach ([['request_id' => 'not-a-uuid'], ['window_ends_at' => Carbon::parse($payload['window_ends_at'])->addMinute()->toIso8601String()]] as $change) {
            $this->withSession(['coverage_gap_result' => ['actor_id' => $this->admin->id, 'request_id' => 'stale']]);
            $this->actingAs($this->admin)->postJson(route('operations.rostering.coverage.ack', $key), [...$payload, ...$change])->assertUnprocessable();
            $this->assertNull(session('coverage_gap_result'));
            $this->assertSame($before, $this->coverageReviewState());
            $this->assertSame($queue, Queue::getFacadeRoot()->pushedJobs());
        }
        $this->actingAs($this->admin)->postJson(route('operations.rostering.coverage.dismiss', $key), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame($before, $this->coverageReviewState());
        $this->assertSame($queue, Queue::getFacadeRoot()->pushedJobs());
    }

    public function test_coverage_review_result_projection_failure_keeps_saved_rows_without_claiming_success(): void
    {
        Queue::fake();
        [$key, $payload] = $this->coverageReviewWindow();
        $this->app->instance(CoverageGapController::class, new class extends CoverageGapController
        {
            protected function flashCommittedResult(Request $request, array $result): void
            {
                throw new \RuntimeException('DEVELOPMENT ONLY result projection unavailable');
            }
        });
        $this->withCommittedCoverageReview(function () use ($key, $payload): void {
            $queue = Queue::getFacadeRoot()->pushedJobs();
            $this->actingAs($this->admin)->postJson(route('operations.rostering.coverage.ack', $key), $payload)
                ->assertOk()->assertJsonPath('status', 'acked')->assertJsonPath('result', null);
            $this->assertSame(1, CoverageGapAcknowledgement::where('coverage_window_key', $key)->whereNull('cleared_at')->count());
            $this->assertSame(1, AuditLog::where('action', 'rostering.coverage.ack')->count());
            $this->assertNull(session('coverage_gap_result'));
            $this->assertSame($queue, Queue::getFacadeRoot()->pushedJobs());
        });
    }

    public function test_coverage_review_flash_is_same_actor_only_and_malformed_results_are_withheld(): void
    {
        $receipt = ['version' => 1, 'scope' => 'coverage_gap', 'actor_id' => $this->admin->id, 'action' => 'ack',
            'request_id' => (string) Str::uuid(), 'staffing_resolved' => false];
        $this->actingAs($this->admin)->withSession(['coverage_gap_result' => $receipt])->get('/operations/shifts')
            ->assertOk()->assertInertia(fn ($page) => $page->where('flash.coverage_gap_result', $receipt));
        $other = User::factory()->create(['approved_at' => now(), 'role' => 'admin']);
        $other->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $this->actingAs($other)->withSession(['coverage_gap_result' => $receipt])->get('/operations/shifts')
            ->assertOk()->assertInertia(fn ($page) => $page->where('flash.coverage_gap_result', null));
        foreach (['bad-value', [...$receipt, 'version' => 2], [...$receipt, 'scope' => 'another_scope']] as $malformed) {
            $this->actingAs($this->admin)->withSession(['coverage_gap_result' => $malformed])->get('/operations/shifts')
                ->assertOk()->assertInertia(fn ($page) => $page->where('flash.coverage_gap_result', null));
        }
    }

    public static function coverageReviewAuditErrors(): array
    {
        return ['lock timeout is not a duplicate' => ['HY000', 1205], 'integrity error is not swallowed' => ['23000', 1452]];
    }

    #[DataProvider('coverageReviewAuditErrors')]
    public function test_coverage_review_propagates_genuine_audit_query_errors_and_rolls_back(string $state, int $code): void
    {
        Queue::fake();
        [$key, $payload] = $this->coverageReviewWindow();
        $before = $this->coverageReviewState();
        $queue = Queue::getFacadeRoot()->pushedJobs();
        $cause = new \PDOException('DEVELOPMENT ONLY coverage audit failure');
        $cause->errorInfo = [$state, $code, $cause->getMessage()];
        $failure = new QueryException(DB::getDefaultConnection(), 'insert coverage audit probe', [], $cause);
        $events = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $events);
        $this->withoutExceptionHandling();
        try {
            AuditLog::saving(function ($audit) use ($key, $failure): void {
                if (($audit->meta['coverage_window_key'] ?? null) === $key) {
                    throw $failure;
                }
            });
            try {
                $this->actingAs($this->admin)->postJson(route('operations.rostering.coverage.ack', $key), $payload);
                $this->fail('The genuine audit error must propagate.');
            } catch (QueryException $actual) {
                $this->assertSame($failure, $actual);
            }
            $this->assertSame($before, $this->coverageReviewState());
            $this->assertSame($queue, Queue::getFacadeRoot()->pushedJobs());
            $this->assertNull(session('coverage_gap_result'));
        } finally {
            Model::setEventDispatcher($events);
        }
    }

    public static function coverageReviewLegacyRules(): array
    {
        return ['nullable rule' => [true], 'inactive rule retains review support' => [false]];
    }

    #[DataProvider('coverageReviewLegacyRules')]
    public function test_coverage_review_does_not_add_a_new_active_rule_or_schedule_policy(bool $nullable): void
    {
        Queue::fake();
        [$key, $payload, $rule] = $this->coverageReviewWindow();
        $rule->update(['is_active' => false]);
        if ($nullable) {
            $payload['coverage_requirement_id'] = null;
            $key = app(ShiftSignalService::class)->buildCoverageWindowKey(['site_id' => $this->site->id, 'rule_id' => null,
                'starts_at' => $payload['window_starts_at'], 'ends_at' => $payload['window_ends_at']]);
        }
        $this->withCommittedCoverageReview(function () use ($key, $payload, $rule): void {
            $ruleBefore = $rule->fresh()->getRawOriginal();
            $queue = Queue::getFacadeRoot()->pushedJobs();
            $this->actingAs($this->admin)->postJson(route('operations.rostering.coverage.ack', $key), $payload)
                ->assertOk()->assertJsonPath('result.outcome', 'recorded')
                ->assertJsonPath('result.window.coverage_requirement_id', $payload['coverage_requirement_id']);
            $this->assertSame($ruleBefore, $rule->fresh()->getRawOriginal());
            $this->assertSame($queue, Queue::getFacadeRoot()->pushedJobs());
        });
    }

    private function coverageReviewWindow(): array
    {
        $starts = now((string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC')))->addDay()->setTime(9, 0, 0)->startOfSecond();
        $ends = $starts->copy()->addHour();
        $rule = SiteCoverageRequirement::create(['site_id' => $this->site->id, 'service_context_id' => $this->serviceContext->id,
            'name' => 'DEVELOPMENT ONLY coverage review', 'coverage_type' => 'custom', 'day_of_week' => strtolower($starts->format('D')),
            'starts_time' => $starts->format('H:i'), 'ends_time' => $ends->format('H:i'), 'minimum_staff' => 1,
            'role_requirements' => [], 'allow_overstaffing' => true, 'is_active' => true]);
        $key = app(ShiftSignalService::class)->buildCoverageWindowKey(['site_id' => $this->site->id, 'rule_id' => $rule->id,
            'starts_at' => $starts->toIso8601String(), 'ends_at' => $ends->toIso8601String()]);

        return [$key, ['site_id' => $this->site->id, 'coverage_requirement_id' => $rule->id,
            'window_starts_at' => $starts->toIso8601String(), 'window_ends_at' => $ends->toIso8601String()], $rule];
    }

    private function coverageReviewState(): array
    {
        return ['reviews' => CoverageGapAcknowledgement::orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all(),
            'audits' => AuditLog::where('action', 'like', 'rostering.coverage.%')->orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all()];
    }

    private function withCommittedCoverageReview(callable $proof): void
    {
        $connection = DB::connection();
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy((string) $connection->getDatabaseName(), getmypid()));
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $connection->commit();
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        try {
            $proof();
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $this->assertFalse($connection->getPdo()->inTransaction());
            $connection->beginTransaction();
        }
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

    public function test_store_fails_closed_without_side_effects_when_assignment_eligibility_is_unavailable(): void
    {
        $baseline = [
            'shifts' => Shift::query()->count(),
            'reservations' => CoverageReservation::query()->count(),
            'timeline' => TimelineEvent::query()->count(),
            'outbox' => ShiftSignalOutbox::query()->count(),
        ];
        $this->mock(ShiftStaffEligibilityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('evaluate')
                ->once()
                ->andThrow(new \RuntimeException('private eligibility infrastructure detail'));
        });

        $this->actingAs($this->admin)
            ->postJson(route('operations.shifts.store'), [
                'client_id' => $this->client->id,
                'user_id' => $this->staff->id,
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDay()->addHours(4)->format('Y-m-d H:i:s'),
                'status' => 'scheduled',
            ])
            ->assertStatus(503)
            ->assertJsonPath('errors.user_id.0', AssignmentEligibilityDecision::UNAVAILABLE_MESSAGE)
            ->assertJsonMissing(['private eligibility infrastructure detail']);

        $this->assertSame($baseline['shifts'], Shift::query()->count());
        $this->assertSame($baseline['reservations'], CoverageReservation::query()->count());
        $this->assertSame($baseline['timeline'], TimelineEvent::query()->count());
        $this->assertSame($baseline['outbox'], ShiftSignalOutbox::query()->count());
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

    public function test_direct_assignment_returns_safe_503_without_side_effects_and_succeeds_on_retry(): void
    {
        Notification::fake();
        $this->giveAdminCurrentHrProfile();
        $shift = $this->assignmentBoundaryShift();
        $baseline = [
            'audit' => AuditLog::query()->count(),
            'timeline' => TimelineEvent::query()->count(),
            'reservations' => CoverageReservation::query()->count(),
            'overrides' => ShiftEligibilityOverride::query()->count(),
            'outbox' => ShiftSignalOutbox::query()->count(),
        ];
        $attempt = 0;
        // Failed preview, then the retry's preview, locked re-decision, and committed Workforce observation.
        $this->mock(ShiftStaffEligibilityService::class, function (MockInterface $mock) use (&$attempt): void {
            $mock->shouldReceive('evaluate')->times(4)->andReturnUsing(function () use (&$attempt) {
                if (++$attempt === 1) {
                    throw new \RuntimeException('private eligibility infrastructure detail');
                }

                return $this->assignmentEligibilityResult();
            });
        });

        $this->actingAs($this->admin)
            ->postJson(route('operations.shifts.assign', $shift), ['user_id' => $this->staff->id])
            ->assertStatus(503)
            ->assertJsonPath('errors.user_id.0', AssignmentEligibilityDecision::UNAVAILABLE_MESSAGE)
            ->assertJsonMissing(['private eligibility infrastructure detail']);

        $this->assertNull($shift->fresh()->user_id);
        $this->assertSame($baseline['audit'], AuditLog::query()->count());
        $this->assertSame($baseline['timeline'], TimelineEvent::query()->count());
        $this->assertSame($baseline['reservations'], CoverageReservation::query()->count());
        $this->assertSame($baseline['overrides'], ShiftEligibilityOverride::query()->count());
        $this->assertSame($baseline['outbox'], ShiftSignalOutbox::query()->count());
        Notification::assertNothingSent();

        $this->actingAs($this->admin)
            ->post(route('operations.shifts.assign', $shift), ['user_id' => $this->staff->id])
            ->assertSessionHas('success');

        $this->assertSame($this->staff->id, $shift->fresh()->user_id);
        $this->assertSame(1, TimelineEvent::query()
            ->where('source_type', Shift::class)
            ->where('source_id', $shift->id)
            ->where('type', 'shift_assigned')
            ->count());
    }

    public function test_direct_assignment_hard_block_is_422_and_never_mutates_assignment_state(): void
    {
        $shift = $this->assignmentBoundaryShift();
        $baseline = [
            'timeline' => TimelineEvent::query()->count(),
            'reservations' => CoverageReservation::query()->count(),
            'overrides' => ShiftEligibilityOverride::query()->count(),
        ];
        $this->mock(ShiftStaffEligibilityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('evaluate')->once()->andReturn($this->assignmentEligibilityResult(
                blocks: ['Current credential is missing.'],
            ));
        });

        $this->actingAs($this->admin)
            ->postJson(route('operations.shifts.assign', $shift), ['user_id' => $this->staff->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.user_id.0', 'Current credential is missing.');

        $this->assertNull($shift->fresh()->user_id);
        $this->assertSame($baseline['overrides'], ShiftEligibilityOverride::query()->count());
        $this->assertSame($baseline['timeline'], TimelineEvent::query()->count());
        $this->assertSame($baseline['reservations'], CoverageReservation::query()->count());
    }

    public function test_direct_warning_requires_the_governed_override_and_persists_immutable_evidence(): void
    {
        $this->giveAdminCurrentHrProfile();
        $shift = $this->assignmentBoundaryShift();
        $warning = 'Would exceed the weekly fatigue warning threshold.';
        // Warning preview, then preview plus locked re-decision for both overrides,
        // followed by the committed Workforce observation after the admin succeeds.
        $this->mock(ShiftStaffEligibilityService::class, function (MockInterface $mock) use ($warning): void {
            $mock->shouldReceive('evaluate')->times(6)->andReturn(
                $this->assignmentEligibilityResult(warnings: [$warning]),
            );
        });

        $this->actingAs($this->admin)
            ->from('/operations/rostering')
            ->post(route('operations.shifts.assign', $shift), ['user_id' => $this->staff->id])
            ->assertRedirect('/operations/rostering')
            ->assertSessionHas('assignment_warnings', [$warning]);

        $this->assertNull($shift->fresh()->user_id);
        $this->assertDatabaseCount('shift_eligibility_overrides', 0);

        $reason = 'Duty manager reviewed the warning and confirmed safe cover arrangements.';
        $scheduler = User::factory()->create([
            'role' => 'scheduler',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $scheduler->id,
            'employee_number' => 'EMP-SCHEDULER-'.$scheduler->id,
            'work_email' => $scheduler->email,
            'position_title' => 'Scheduler',
            'position_role' => 'scheduler',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);
        $scheduler->permissionOverrides()->syncWithoutDetaching([
            Permission::query()->where('key', 'shifts.manageAny')->firstOrFail()->id => ['allowed' => true],
        ]);

        $this->actingAs($scheduler)
            ->post(route('operations.shifts.assign', $shift), [
                'user_id' => $this->staff->id,
                'override_acknowledged' => true,
                'override_reason' => $reason,
            ])
            ->assertForbidden();

        $this->assertNull($shift->fresh()->user_id);
        $this->assertDatabaseCount('shift_eligibility_overrides', 0);

        $this->actingAs($this->admin)
            ->post(route('operations.shifts.assign', $shift), [
                'user_id' => $this->staff->id,
                'override_acknowledged' => true,
                'override_reason' => $reason,
            ])
            ->assertSessionHas('success');

        $override = ShiftEligibilityOverride::query()->sole();
        $this->assertSame($this->admin->id, $override->overridden_by);
        $this->assertSame($reason, $override->override_reason);
        $this->assertNotNull($override->created_at);
        $this->assertSame(['fatigue_weekly'], $override->rules_overridden);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shifteligibilityoverride.create',
            'auditable_id' => $override->id,
        ]);

        try {
            $override->update(['override_reason' => 'Rewritten evidence']);
            $this->fail('Override evidence must not be updateable.');
        } catch (\LogicException $exception) {
            $this->assertSame('Shift eligibility override evidence is immutable.', $exception->getMessage());
        }

        try {
            $override->fresh()->delete();
            $this->fail('Override evidence must not be deleteable.');
        } catch (\LogicException $exception) {
            $this->assertSame('Shift eligibility override evidence is immutable.', $exception->getMessage());
        }

        $this->assertDatabaseHas('shift_eligibility_overrides', [
            'id' => $override->id,
            'override_reason' => $reason,
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

    public function test_update_fails_closed_and_releases_coverage_when_assignment_eligibility_is_unavailable(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'location' => 'Original location',
            'status' => 'scheduled',
        ]);
        $originalStartsAt = $shift->starts_at->copy();
        $originalEndsAt = $shift->ends_at->copy();
        $baselineReservations = CoverageReservation::query()->count();
        $this->mock(ShiftStaffEligibilityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('evaluate')
                ->once()
                ->andThrow(new \RuntimeException('private eligibility infrastructure detail'));
        });

        $this->actingAs($this->admin)
            ->putJson(route('operations.shifts.update', $shift), [
                'client_id' => $this->client->id,
                'starts_at' => $originalStartsAt->copy()->addHour()->format('Y-m-d H:i:s'),
                'ends_at' => $originalEndsAt->copy()->addHour()->format('Y-m-d H:i:s'),
                'location' => 'Unsafe changed location',
                'status' => 'scheduled',
            ])
            ->assertStatus(503)
            ->assertJsonPath('errors.user_id.0', AssignmentEligibilityDecision::UNAVAILABLE_MESSAGE)
            ->assertJsonMissing(['private eligibility infrastructure detail']);

        $shift->refresh();
        $this->assertTrue($shift->starts_at->equalTo($originalStartsAt));
        $this->assertTrue($shift->ends_at->equalTo($originalEndsAt));
        $this->assertSame('Original location', $shift->location);
        $this->assertSame($this->staff->id, $shift->user_id);
        $this->assertSame($baselineReservations, CoverageReservation::query()->count());
    }

    public function test_update_warning_returns_actionable_inertia_errors_without_saving_the_draft(): void
    {
        $start = now()->addWeek()->setTime(9, 0);
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(4),
            'location' => 'Original location',
            'status' => 'scheduled',
        ]);
        $task = $shift->tasks()->create(['label' => 'Original task', 'sort_order' => 0]);
        $payload = [
            'client_id' => $this->client->id,
            'starts_at' => $start->copy()->addHour()->toIso8601String(),
            'ends_at' => $start->copy()->addHours(5)->toIso8601String(),
            'location' => 'Unsaved draft location',
            'status' => 'scheduled',
            'tasks' => [['id' => $task->id, 'label' => 'Unsaved task', 'scheduled_time' => '11:00']],
        ];
        $reservation = CoverageReservation::create([
            'site_id' => $this->site->id,
            'shift_id' => $shift->id,
            'reserved_by_user_id' => $this->admin->id,
            'reservation_token' => 'update-warning-'.$shift->id,
            'status' => 'active',
            'reason' => 'shift_update',
            'window_starts_at' => $payload['starts_at'],
            'window_ends_at' => $payload['ends_at'],
            'expires_at' => now()->addMinutes(5),
        ]);
        $payload['coverage_reservation_token'] = $reservation->reservation_token;
        $shiftBefore = $shift->fresh()->getRawOriginal();
        $taskBefore = $task->fresh()->getRawOriginal();
        $timelineBefore = TimelineEvent::query()->count();
        $outboxBefore = ShiftSignalOutbox::query()->count();
        $overridesBefore = ShiftEligibilityOverride::query()->count();
        $warnings = ['Would exceed the weekly fatigue warning threshold.', 'Minimum rest between shifts is below the preferred interval.'];
        $eligibility = $this->assignmentEligibilityResult(warnings: $warnings);
        $message = 'Review and acknowledge the eligibility warnings before saving this shift. '.implode(' ', $warnings);
        Notification::fake();
        $this->mock(ShiftStaffEligibilityService::class, function (MockInterface $mock) use ($eligibility): void {
            $mock->shouldReceive('evaluate')->once()->andReturn($eligibility);
        });

        $this->actingAs($this->admin)
            ->from('/operations/shifts')
            ->withHeader('X-Inertia', 'true')
            ->put(route('operations.shifts.update', $shift), $payload)
            ->assertStatus(303)
            ->assertRedirect('/operations/shifts')
            ->assertSessionHasErrors(['user_id' => $message])
            ->assertSessionHas('eligibility_result', $eligibility->toArray())
            ->assertSessionHas('assignment_warnings', $warnings)
            ->assertSessionHasInput('location', 'Unsaved draft location')
            ->assertSessionHasInput('tasks.0.label', 'Unsaved task')
            ->assertSessionMissing('success');

        $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
        $this->assertSame($taskBefore, $task->fresh()->getRawOriginal());
        $this->assertSame($timelineBefore, TimelineEvent::query()->count());
        $this->assertSame($outboxBefore, ShiftSignalOutbox::query()->count());
        $this->assertSame($overridesBefore, ShiftEligibilityOverride::query()->count());
        $this->assertSame('released', $reservation->fresh()->status);
        Notification::assertNothingSent();

        $this->withoutHeader('X-Inertia')->get('/operations/shifts')->assertInertia(fn ($page) => $page
            ->where('errors.user_id', $message)
        );
    }

    public function test_update_warning_acknowledgement_still_requires_authority_and_a_reason(): void
    {
        $start = now()->addWeek()->setTime(9, 0);
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(4),
            'location' => 'Original location',
            'status' => 'scheduled',
        ]);
        $this->staff->permissionOverrides()->syncWithoutDetaching([
            Permission::where('key', 'shifts.manageAny')->firstOrFail()->id => ['allowed' => true],
            Permission::where('key', 'shifts.overrideEligibility')->firstOrFail()->id => ['allowed' => false],
        ]);
        $payload = [
            'client_id' => $this->client->id,
            'starts_at' => $start->copy()->addHour()->toIso8601String(),
            'ends_at' => $start->copy()->addHours(5)->toIso8601String(),
            'location' => 'Reviewed location',
            'status' => 'scheduled',
            'override_acknowledged' => true,
        ];
        $shiftBefore = $shift->fresh()->getRawOriginal();
        $overridesBefore = ShiftEligibilityOverride::query()->count();
        Notification::fake();
        $warning = 'Would exceed the weekly fatigue warning threshold.';
        $this->mock(ShiftStaffEligibilityService::class, function (MockInterface $mock) use ($warning): void {
            // Three update decisions, then the committed observation of the successful update.
            $mock->shouldReceive('evaluate')->times(4)->andReturn($this->assignmentEligibilityResult(warnings: [$warning]));
        });

        $this->actingAs($this->admin)
            ->from('/operations/shifts')
            ->put(route('operations.shifts.update', $shift), $payload)
            ->assertRedirect('/operations/shifts')
            ->assertSessionHasErrors(['override_reason' => 'A reason is required when overriding eligibility warnings.']);
        $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
        $this->assertSame($overridesBefore, ShiftEligibilityOverride::query()->count());
        Notification::assertNothingSent();

        $reason = 'Duty manager reviewed fatigue and confirmed safe cover arrangements.';
        $payload['override_reason'] = $reason;
        $this->actingAs($this->staff->fresh())
            ->put(route('operations.shifts.update', $shift), $payload)
            ->assertForbidden();
        $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
        $this->assertSame($overridesBefore, ShiftEligibilityOverride::query()->count());
        Notification::assertNothingSent();

        $this->actingAs($this->admin)
            ->put(route('operations.shifts.update', $shift), $payload)
            ->assertSessionHas('success');
        $this->assertSame('Reviewed location', $shift->fresh()->location);
        $this->assertTrue($shift->fresh()->starts_at->equalTo($start->copy()->addHour()));
        $this->assertSame($overridesBefore + 1, ShiftEligibilityOverride::query()->count());
        $override = ShiftEligibilityOverride::query()->where('shift_id', $shift->id)->sole();
        $this->assertSame($this->admin->id, $override->overridden_by);
        $this->assertSame($this->staff->id, $override->user_id);
        $this->assertSame($reason, $override->override_reason);
        $this->assertSame(['fatigue_weekly'], $override->rules_overridden);
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
            'site_id' => $this->site->id,
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
            'site_id' => $this->site->id,
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
            'site_id' => $this->site->id,
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

    public function test_assignment_route_segments_sunday_overnight_hours_across_local_iso_weeks(): void
    {
        Notification::fake();
        $this->giveAdminCurrentHrProfile();
        config([
            'app.worker_timezone' => 'Pacific/Auckland',
            'hr.fatigue.max_hours_per_day' => 24,
            'hr.fatigue.max_hours_per_week' => 10,
            'hr.fatigue.warning_threshold_weekly' => 10,
            'hr.fatigue.min_rest_between_shifts_hours' => 0,
            'hr.fatigue.max_consecutive_days' => 7,
        ]);

        $nextWeekMonday = Carbon::now('Pacific/Auckland')
            ->addWeeks(3)
            ->startOfWeek(Carbon::MONDAY)
            ->startOfDay();
        $previousWeekTuesday = $nextWeekMonday->copy()->subWeek()->addDay();
        $overnightStart = $nextWeekMonday->copy()->subDay()->setTime(23, 0);
        $overnightEnd = $nextWeekMonday->copy()->setTime(3, 0);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => $previousWeekTuesday->copy()->setTime(8, 0)->utc(),
            'actual_starts_at' => $previousWeekTuesday->copy()->setTime(8, 0)->utc(),
            'ends_at' => $previousWeekTuesday->copy()->setTime(16, 0)->utc(),
            'status' => 'completed',
            'created_by' => $this->admin->id,
        ]);
        StaffAvailability::query()->create([
            'user_id' => $this->staff->id,
            'day_of_week' => Carbon::SUNDAY,
            'starts_at' => '23:00:00',
            'ends_at' => '03:00:00',
            'ends_next_day' => true,
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => null,
            'starts_at' => $overnightStart->utc(),
            'ends_at' => $overnightEnd->utc(),
            'status' => 'draft',
            'coverage_roles' => [],
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('operations.shifts.assign', $shift), ['user_id' => $this->staff->id])
            ->assertSessionHas('success');

        $this->assertSame($this->staff->id, $shift->fresh()->user_id);
    }

    public function test_assignment_route_blocks_when_monday_segment_exceeds_the_next_local_iso_week(): void
    {
        Notification::fake();
        config([
            'app.worker_timezone' => 'Pacific/Auckland',
            'hr.fatigue.max_hours_per_day' => 24,
            'hr.fatigue.max_hours_per_week' => 10,
            'hr.fatigue.warning_threshold_weekly' => 10,
            'hr.fatigue.min_rest_between_shifts_hours' => 0,
            'hr.fatigue.max_consecutive_days' => 7,
        ]);

        $nextWeekMonday = Carbon::now('Pacific/Auckland')
            ->addWeeks(3)
            ->startOfWeek(Carbon::MONDAY)
            ->startOfDay();
        $nextWeekTuesday = $nextWeekMonday->copy()->addDay();
        $overnightStart = $nextWeekMonday->copy()->subDay()->setTime(23, 0);
        $overnightEnd = $nextWeekMonday->copy()->setTime(3, 0);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => $nextWeekTuesday->copy()->setTime(8, 0)->utc(),
            'actual_starts_at' => $nextWeekTuesday->copy()->setTime(8, 0)->utc(),
            'ends_at' => $nextWeekTuesday->copy()->setTime(16, 0)->utc(),
            'status' => 'completed',
            'created_by' => $this->admin->id,
        ]);
        StaffAvailability::query()->create([
            'user_id' => $this->staff->id,
            'day_of_week' => Carbon::SUNDAY,
            'starts_at' => '00:00:00',
            'ends_at' => '23:59:59',
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => null,
            'starts_at' => $overnightStart->utc(),
            'ends_at' => $overnightEnd->utc(),
            'status' => 'draft',
            'coverage_roles' => [],
            'created_by' => $this->admin->id,
        ]);
        $timelineCount = TimelineEvent::query()->count();
        $reservationCount = CoverageReservation::query()->count();

        $this->actingAs($this->admin)
            ->postJson(route('operations.shifts.assign', $shift), ['user_id' => $this->staff->id])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.user_id.0',
                "Would exceed 10h weekly maximum (11.0h total in the week starting {$nextWeekMonday->toDateString()}).",
            );

        $this->assertNull($shift->fresh()->user_id);
        $this->assertSame($timelineCount, TimelineEvent::query()->count());
        $this->assertSame($reservationCount, CoverageReservation::query()->count());
        Notification::assertNothingSent();
    }

    /**
     * Shift lifecycle writes lock the actor's current HR profile as employment
     * evidence, so the admin needs one before an assignment can land.
     */
    private function giveAdminCurrentHrProfile(): void
    {
        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->admin->id,
            'employee_number' => 'EMP-ADMIN-'.$this->admin->id,
            'work_email' => $this->admin->email,
            'position_title' => 'Operations Manager',
            'position_role' => 'admin',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);
    }

    private function assignmentBoundaryShift(): Shift
    {
        return Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => null,
            'starts_at' => now()->addWeek()->setTime(9, 0),
            'ends_at' => now()->addWeek()->setTime(13, 0),
            'status' => 'draft',
            'coverage_roles' => [],
            'created_by' => $this->admin->id,
        ]);
    }

    private function assignmentEligibilityResult(array $blocks = [], array $warnings = []): EligibilityResult
    {
        return new EligibilityResult(
            is_allowed: $blocks === [],
            blocking_reasons: $blocks,
            warnings: $warnings,
            checked_rules: [],
            overrideable_warnings: $warnings === [] ? [] : [[
                'rule' => 'fatigue_weekly',
                'message' => $warnings[0],
                'overrideable' => true,
            ]],
        );
    }
}
