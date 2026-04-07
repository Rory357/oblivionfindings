<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftHandoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Site $siteA;

    protected Site $siteB;

    protected Client $clientA;

    protected Client $clientB;

    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-04-06 10:00:00'));

        $this->siteA = Site::factory()->create(['name' => 'Harbour House']);
        $this->siteB = Site::factory()->create(['name' => 'Forest House']);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Residential Support',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->clientA = Client::factory()->create([
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
        $this->clientB = Client::factory()->create([
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
    }

    public function test_user_sees_only_shifts_from_accessible_sites(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['shifts.viewAssigned']);

        $visibleShift = Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $user->id,
            'starts_at' => now()->copy()->setTime(9, 0),
            'ends_at' => now()->copy()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $user->id,
            'starts_at' => now()->copy()->setTime(14, 0),
            'ends_at' => now()->copy()->setTime(18, 0),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($user)
            ->get('/operations/shifts?from=2026-04-06&to=2026-04-06');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/shifts/index')
            ->has('shifts.data', 1)
            ->where('shifts.data.0.id', $visibleShift->id)
        );
    }

    public function test_user_cannot_access_shift_from_another_site(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['shifts.viewAssigned']);

        $foreignShift = Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $user->id,
            'starts_at' => now()->copy()->setTime(9, 0),
            'ends_at' => now()->copy()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        $this->actingAs($user)
            ->get("/operations/shifts/{$foreignShift->id}")
            ->assertForbidden();
    }

    public function test_user_cannot_approve_timesheet_from_another_site(): void
    {
        $approver = $this->makeSiteScopedUser([$this->siteA], ['timesheets.approve']);
        $staff = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);

        $shift = Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => now()->copy()->subDay()->setTime(9, 0),
            'ends_at' => now()->copy()->subDay()->setTime(17, 0),
            'status' => 'completed',
        ]);

        $timesheet = Timesheet::factory()->submitted()->create([
            'shift_id' => $shift->id,
            'user_id' => $staff->id,
            'client_id' => $this->clientB->id,
            'shift_site_id' => $this->siteB->id,
            'work_date' => '2026-04-05',
            'starts_at' => Carbon::parse('2026-04-05 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-05 17:00:00'),
        ]);

        $this->actingAs($approver)
            ->post("/timesheets/{$timesheet->id}/approve")
            ->assertForbidden();
    }

    public function test_handover_acknowledgement_is_blocked_for_foreign_site(): void
    {
        $incomingUser = $this->makeSiteScopedUser([$this->siteA], ['shifts.update', 'shifts.viewAssigned']);
        $outgoingUser = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);

        $outgoingShift = Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $outgoingUser->id,
            'starts_at' => now()->copy()->subHours(4),
            'ends_at' => now()->copy()->addHour(),
            'status' => 'in_progress',
        ]);

        $incomingShift = Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $incomingUser->id,
            'starts_at' => now()->copy()->addMinutes(30),
            'ends_at' => now()->copy()->addHours(4),
            'status' => 'scheduled',
        ]);

        $handover = ShiftHandover::factory()->create([
            'organization_id' => $incomingUser->organization_id,
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $this->clientB->id,
            'outgoing_staff_id' => $outgoingUser->id,
            'incoming_staff_id' => $incomingUser->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'submitted_at' => now()->subMinutes(15),
            'submitted_by' => $outgoingUser->id,
        ]);

        $this->actingAs($incomingUser)
            ->patch("/operations/handovers/{$handover->id}/acknowledge")
            ->assertForbidden();
    }

    public function test_reporting_only_includes_accessible_site_data(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['operations.reports.view']);
        $staff = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker', 'name' => 'Scoped Staff']);

        Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
            'status' => 'scheduled',
        ]);

        Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-06 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-06 17:00:00'),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($user)
            ->get('/operations/reports/shifts?date_from=2026-04-01&date_to=2026-04-30');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/reports/Shifts')
            ->has('sites', 1)
            ->where('sites.0.id', $this->siteA->id)
            ->where('report.staff_utilisation.total_shifts', 1)
        );
    }

    public function test_csv_export_respects_site_scope(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['operations.reports.view']);
        $staff = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);

        Timesheet::factory()->create([
            'user_id' => $staff->id,
            'client_id' => $this->clientA->id,
            'shift_id' => null,
            'shift_site_id' => $this->siteA->id,
            'work_date' => '2026-04-05',
            'reconciliation_status' => 'blocked',
            'reconciliation_summary' => 'Site A mismatch.',
        ]);

        Timesheet::factory()->create([
            'user_id' => $staff->id,
            'client_id' => $this->clientB->id,
            'shift_id' => null,
            'shift_site_id' => $this->siteB->id,
            'work_date' => '2026-04-05',
            'reconciliation_status' => 'blocked',
            'reconciliation_summary' => 'Site B mismatch.',
        ]);

        $response = $this->actingAs($user)
            ->get('/operations/reports/shifts/export?dataset=reconciliation&date_from=2026-04-01&date_to=2026-04-30');

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString($this->siteA->name, $content);
        $this->assertStringNotContainsString($this->siteB->name, $content);
    }

    public function test_elevated_user_can_bypass_site_scope(): void
    {
        $admin = $this->makeBypassUser([
            'shifts.viewAny',
            'shifts.manageAny',
            'timesheets.manageAny',
            'reports.viewAny',
            'controlRoom.viewAny',
        ]);

        $shiftA = Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => User::factory(),
            'starts_at' => now()->copy()->setTime(9, 0),
            'ends_at' => now()->copy()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        $shiftB = Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => User::factory(),
            'starts_at' => now()->copy()->setTime(14, 0),
            'ends_at' => now()->copy()->setTime(18, 0),
            'status' => 'scheduled',
        ]);

        $this->actingAs($admin)
            ->get('/operations/shifts?from=2026-04-06&to=2026-04-06')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('shifts.data', 2));

        $this->actingAs($admin)
            ->get("/operations/shifts/{$shiftB->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('shift.id', $shiftB->id));

        $this->assertNotSame($shiftA->id, $shiftB->id);
    }

    public function test_control_room_shift_alert_context_is_filtered_by_site(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['controlRoom.viewAny']);

        $alert = ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift No Show',
            'site_id' => $this->siteB->id,
            'client_id' => $this->clientB->id,
            'context' => [
                'shift_context' => [
                    'shift' => ['id' => 901],
                    'site' => ['id' => $this->siteB->id, 'name' => $this->siteB->name],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get("/control-room/alerts/{$alert->id}")
            ->assertForbidden();
    }

    public function test_shift_edit_page_does_not_leak_other_site_clients(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['shifts.update']);

        $shift = Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $user->id,
            'starts_at' => now()->copy()->setTime(9, 0),
            'ends_at' => now()->copy()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($user)->get("/operations/shifts/{$shift->id}/edit");

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('operations/shifts/edit')
            ->has('clients', 1)
            ->where('clients.0.id', $this->clientA->id)
        );
    }

    /**
     * @param  array<int, Site>  $sites
     * @param  array<int, string>  $permissionKeys
     */
    protected function makeSiteScopedUser(array $sites, array $permissionKeys): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);

        $this->grantPermissions($user, $permissionKeys);

        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'employee_number' => 'EMP-SITE-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $sites[0]->id ?? null,
            'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->values()->all(),
        ]);

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function makeBypassUser(array $permissionKeys): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'admin',
        ]);

        $this->grantPermissions($user, $permissionKeys);

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionIds = collect($permissionKeys)
            ->map(function (string $key) {
                $module = str($key)->before('.')->value() ?: 'operations';

                return Permission::query()->firstOrCreate(
                    ['key' => $key],
                    [
                        'description' => $key,
                        'group' => $module,
                        'module' => $module,
                    ],
                )->id;
            })
            ->all();

        $permissionMap = collect($permissionIds)
            ->mapWithKeys(fn ($id) => [(int) $id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }

    // ──────────────────────────────────────────────
    // ShiftReportsController site isolation
    // ──────────────────────────────────────────────

    public function test_shift_reports_controller_scopes_to_accessible_sites(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['reports.viewAny']);

        $visibleShift = Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => User::factory(),
            'starts_at' => now()->copy()->setTime(9, 0),
            'ends_at' => now()->copy()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => User::factory(),
            'starts_at' => now()->copy()->setTime(14, 0),
            'ends_at' => now()->copy()->setTime(18, 0),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($user)
            ->get('/reports/shifts?from=2026-04-06&to=2026-04-06');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('reports/shifts')
            ->has('shifts', 1)
            ->where('shifts.0.id', $visibleShift->id)
        );
    }

    public function test_shift_reports_controller_multi_site_user_sees_both(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA, $this->siteB], ['reports.viewAny']);

        Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => User::factory(),
            'starts_at' => now()->copy()->setTime(9, 0),
            'ends_at' => now()->copy()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => User::factory(),
            'starts_at' => now()->copy()->setTime(14, 0),
            'ends_at' => now()->copy()->setTime(18, 0),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($user)
            ->get('/reports/shifts?from=2026-04-06&to=2026-04-06');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('reports/shifts')
            ->has('shifts', 2)
        );
    }

    public function test_shift_reports_controller_elevated_user_sees_all_sites(): void
    {
        $admin = $this->makeBypassUser(['reports.viewAny', 'shifts.manageAny']);

        Shift::factory()->create([
            'client_id' => $this->clientA->id,
            'site_id' => $this->siteA->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => User::factory(),
            'starts_at' => now()->copy()->setTime(9, 0),
            'ends_at' => now()->copy()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        Shift::factory()->create([
            'client_id' => $this->clientB->id,
            'site_id' => $this->siteB->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => User::factory(),
            'starts_at' => now()->copy()->setTime(14, 0),
            'ends_at' => now()->copy()->setTime(18, 0),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($admin)
            ->get('/reports/shifts?from=2026-04-06&to=2026-04-06');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('reports/shifts')
            ->has('shifts', 2)
        );
    }
}
