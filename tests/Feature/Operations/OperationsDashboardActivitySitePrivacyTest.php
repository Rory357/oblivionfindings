<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\OperationsPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationsDashboardActivitySitePrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(OperationsPermissionsSeeder::class);
    }

    public function test_both_routes_require_the_dashboard_action_even_with_broad_actions_or_global_scope(): void
    {
        $this->assertDatabaseHas('permissions', ['key' => 'operations.dashboard.viewAllSites']);
        $site = $this->site('OPS ACTION SITE');
        $broadOnly = $this->siteUser($site, [
            'clients.viewAny',
            'shifts.viewAny',
            'timesheets.manageAny',
        ]);
        $globalOnly = $this->siteUser($site, [
            'operations.dashboard.viewAllSites',
            'timesheets.manageAny',
        ]);

        foreach ([$broadOnly, $globalOnly] as $actor) {
            $this->actingAs($actor)->get('/operations')->assertForbidden();
            $this->actingAs($actor)->get('/operations/activity')->assertForbidden();
        }
    }

    public function test_no_current_site_returns_empty_non_disclosing_dashboard_and_activity_payloads(): void
    {
        $fixture = $this->twoSiteFixture();
        $actor = $this->userWithPermissions([
            'operations.dashboard.view',
            'clients.viewAny',
            'shifts.viewAny',
            'timesheets.manageAny',
        ]);

        $dashboard = $this->actingAs($actor)->get('/operations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/Index')
                ->where('stats.total_clients', 0)
                ->where('stats.shifts_today_total', 0)
                ->where('stats.hours_this_week', 0)
                ->where('stats.timesheets_pending', 0)
                ->where('hero.coverage_pct', 0)
                ->where('hero.staff_on_shift', 0)
                ->where('hero.sites_count', 0)
                ->where('hero.regions_count', 0)
                ->has('top_sites', 0)
                ->has('site_options', 0)
                ->has('recent_activity', 0)
                ->where('attention.incidents.count', 0)
                ->where('metrics.compliance.current', 0));
        $this->assertForeignSentinelsAbsent($dashboard, $fixture);

        $activity = $this->actingAs($actor)->get('/operations/activity')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/activity/Index')
                ->where('filter', 'all')
                ->has('activities', 0));
        $this->assertForeignSentinelsAbsent($activity, $fixture);
    }

    public function test_omitted_filters_and_broad_actions_remain_strictly_site_bound(): void
    {
        $fixture = $this->twoSiteFixture();
        $actor = $this->siteUser($fixture['siteA'], [
            'operations.dashboard.view',
            'clients.viewAny',
            'shifts.viewAny',
            'timesheets.manageAny',
            'incidents.viewAny',
        ]);
        $localActivityIds = collect([
            ...$fixture['shiftsA']->map(fn (Shift $shift): string => 'shift-'.$shift->id)->all(),
            ...$fixture['timesheetsA']->map(fn (Timesheet $timesheet): string => 'ts-'.$timesheet->id)->all(),
            'inc-'.$fixture['incidentA']->id,
        ])->sort()->values()->all();

        $dashboard = $this->actingAs($actor)->get('/operations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total_clients', 1)
                ->where('stats.active_clients', 1)
                ->where('stats.shifts_today_total', 2)
                ->where('stats.hours_this_week', fn ($hours): bool => (float) $hours === 4.0)
                ->where('stats.timesheets_pending', 1)
                ->where('attention.incidents.count', 1)
                ->where('metrics.compliance.current', 2)
                ->where('hero.sites_count', 1)
                ->where('top_sites.0.id', $fixture['siteA']->id)
                ->where('top_sites.0.name', $fixture['siteA']->name)
                ->where('site_options.0.id', $fixture['siteA']->id)
                ->where('recent_activity', fn ($rows): bool => collect($rows)
                    ->pluck('id')->sort()->values()->all() === $localActivityIds));
        $this->assertForeignSentinelsAbsent($dashboard, $fixture);

        // No filter parameter: the default all-feed must still be Site-scoped.
        $activity = $this->actingAs($actor)->get('/operations/activity')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter', 'all')
                ->where('activities', function ($rows) use ($fixture): bool {
                    $ids = collect($rows)->pluck('id');

                    return $ids->contains('client-'.$fixture['clientA']->id)
                        && $fixture['shiftsA']->every(fn (Shift $shift): bool => $ids->contains('shift-'.$shift->id))
                        && $fixture['timesheetsA']->every(fn (Timesheet $timesheet): bool => $ids->contains('timesheet-'.$timesheet->id))
                        && ! $ids->contains('client-'.$fixture['clientB']->id)
                        && $fixture['shiftsB']->every(fn (Shift $shift): bool => ! $ids->contains('shift-'.$shift->id))
                        && $fixture['timesheetsB']->every(fn (Timesheet $timesheet): bool => ! $ids->contains('timesheet-'.$timesheet->id));
                }));
        $this->assertForeignSentinelsAbsent($activity, $fixture);

        $filterExpectations = [
            'shifts' => [
                'ids' => $fixture['shiftsA']->map(fn (Shift $shift): string => 'shift-'.$shift->id),
                'links' => $fixture['shiftsA']->map(fn (Shift $shift): string => '/operations/shifts/'.$shift->id),
            ],
            'timesheets' => [
                'ids' => $fixture['timesheetsA']->map(fn (Timesheet $timesheet): string => 'timesheet-'.$timesheet->id),
                'links' => $fixture['timesheetsA']->map(fn (Timesheet $timesheet): string => '/operations/timesheets/'.$timesheet->id),
            ],
            'clients' => [
                'ids' => collect(['client-'.$fixture['clientA']->id]),
                'links' => collect(['/operations/clients/'.$fixture['clientA']->id]),
            ],
        ];

        foreach ($filterExpectations as $filter => $expected) {
            $filteredActivity = $this->actingAs($actor)
                ->get('/operations/activity?filter='.$filter)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('filter', $filter)
                    ->where('activities', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                        === $expected['ids']->sort()->values()->all()
                        && collect($rows)->pluck('link')->sort()->values()->all()
                        === $expected['links']->sort()->values()->all()));

            $this->assertForeignSentinelsAbsent($filteredActivity, $fixture);
        }
    }

    public function test_seeded_global_role_with_both_dashboard_permissions_can_see_all_sites(): void
    {
        $fixture = $this->twoSiteFixture();
        $actor = $this->userWithPermissions([]);
        $actor->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $this->assertTrue($actor->canDo('operations.dashboard.view'));
        $this->assertTrue($actor->canDo('operations.dashboard.viewAllSites'));

        $dashboard = $this->actingAs($actor)->get('/operations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total_clients', 2)
                ->where('stats.active_clients', 2)
                ->where('stats.shifts_today_total', 4)
                ->where('stats.hours_this_week', fn ($hours): bool => (float) $hours === 8.0)
                ->where('stats.timesheets_pending', 2)
                ->where('attention.incidents.count', 2)
                ->where('metrics.compliance.current', 2)
                ->where('hero.sites_count', 2)
                ->where('top_sites', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$fixture['siteA']->id, $fixture['siteB']->id])->sort()->values()->all())
                ->where('site_options', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$fixture['siteA']->id, $fixture['siteB']->id])->sort()->values()->all()));
        $dashboard->assertSee($fixture['siteA']->name)->assertSee($fixture['siteB']->name);

        $activity = $this->actingAs($actor)->get('/operations/activity')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter', 'all')
                ->where('activities', function ($rows) use ($fixture): bool {
                    $ids = collect($rows)->pluck('id');

                    return $ids->contains('client-'.$fixture['clientA']->id)
                        && $ids->contains('client-'.$fixture['clientB']->id)
                        && $fixture['shiftsA']->concat($fixture['shiftsB'])
                            ->every(fn (Shift $shift): bool => $ids->contains('shift-'.$shift->id))
                        && $fixture['timesheetsA']->concat($fixture['timesheetsB'])
                            ->every(fn (Timesheet $timesheet): bool => $ids->contains('timesheet-'.$timesheet->id));
                }));
        $activity->assertSee($fixture['clientA']->first_name)->assertSee($fixture['clientB']->first_name);
    }

    /** @return array<string, mixed> */
    private function twoSiteFixture(): array
    {
        $siteA = $this->site('OPS SITE A VISIBLE SENTINEL');
        $siteB = $this->site('OPS SITE B PRIVATE SENTINEL');
        $staffA = $this->siteUser($siteA, [], 'OPS STAFF A VISIBLE');
        $staffB = $this->siteUser($siteB, [], 'OPS STAFF B PRIVATE');
        $siteLessStaff = $this->userWithPermissions([], 'OPS STAFF WITHOUT CURRENT SITE');
        $this->currentProfile($siteLessStaff, null);
        $clientA = Client::factory()->create([
            'site_id' => $siteA->id,
            'status' => 'active',
            'first_name' => 'OPSCLIENTAVISIBLE',
            'last_name' => 'SENTINEL',
            'created_at' => now(),
        ]);
        $clientB = Client::factory()->create([
            'site_id' => $siteB->id,
            'status' => 'active',
            'first_name' => 'OPSCLIENTBPRIVATE',
            'last_name' => 'SENTINEL',
            'created_at' => now(),
        ]);

        $shiftsA = collect([
            $this->completedShift($siteA, $clientA, $staffA, 8),
            $this->completedShift($siteA, $clientA, $staffA, 13),
        ]);
        $shiftsB = collect([
            $this->completedShift($siteB, $clientB, $staffB, 8),
            $this->completedShift($siteB, $clientB, $staffB, 13),
        ]);
        $timesheetsA = collect([
            $this->timesheet($shiftsA[0], $siteA, $clientA, $staffA, 'approved'),
            $this->timesheet($shiftsA[1], $siteA, $clientA, $staffA, 'submitted'),
        ]);
        $timesheetsB = collect([
            $this->timesheet($shiftsB[0], $siteB, $clientB, $staffB, 'approved'),
            $this->timesheet($shiftsB[1], $siteB, $clientB, $staffB, 'submitted'),
        ]);
        $incidentA = ClientIncident::factory()->submitted()->create([
            'client_id' => $clientA->id,
            'site_id' => $siteA->id,
            'shift_id' => $shiftsA[0]->id,
            'title' => 'OPS INCIDENT A VISIBLE',
            'updated_at' => now(),
        ]);
        $incidentB = ClientIncident::factory()->submitted()->create([
            'client_id' => $clientB->id,
            'site_id' => $siteB->id,
            'shift_id' => $shiftsB[0]->id,
            'title' => 'OPS INCIDENT B PRIVATE',
            'updated_at' => now(),
        ]);
        $forgedIncident = ClientIncident::factory()->submitted()->create([
            'client_id' => $clientB->id,
            'site_id' => $siteA->id,
            'shift_id' => null,
            'title' => 'OPS FORGED CROSS SITE INCIDENT',
            'updated_at' => now(),
        ]);

        return compact(
            'siteA',
            'siteB',
            'staffA',
            'staffB',
            'siteLessStaff',
            'clientA',
            'clientB',
            'shiftsA',
            'shiftsB',
            'timesheetsA',
            'timesheetsB',
            'incidentA',
            'incidentB',
            'forgedIncident',
        );
    }

    private function completedShift(Site $site, Client $client, User $staff, int $hour): Shift
    {
        $startsAt = today()->setTime($hour, 0);
        $endsAt = $startsAt->copy()->addHours(4);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $staff->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'actual_starts_at' => $startsAt,
            'actual_ends_at' => $endsAt,
            'expected_break_minutes' => 0,
            'status' => 'completed',
            'created_by' => $staff->id,
            'started_by' => $staff->id,
            'completed_by' => $staff->id,
            'updated_at' => now(),
        ]);

        HrAttendanceSession::query()->create([
            'user_id' => $staff->id,
            'shift_id' => $shift->id,
            'site_id' => $site->id,
            'clock_in_at' => $startsAt,
            'clock_out_at' => $endsAt,
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $staff->id,
            'closed_by' => $staff->id,
        ]);

        return $shift;
    }

    private function timesheet(
        Shift $shift,
        Site $site,
        Client $client,
        User $staff,
        string $status,
    ): Timesheet {
        $factory = Timesheet::factory();
        $factory = $status === 'approved' ? $factory->approved() : $factory->submitted();

        return $factory->create([
            'shift_id' => $shift->id,
            'attendance_session_id' => HrAttendanceSession::query()
                ->where('shift_id', $shift->id)
                ->where('user_id', $staff->id)
                ->sole()
                ->id,
            'user_id' => $staff->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'site_id' => $site->id,
            'work_date' => today(),
            'starts_at' => $shift->starts_at,
            'ends_at' => $shift->ends_at,
            'break_minutes' => 0,
            'submitted_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function site(string $name): Site
    {
        return Site::factory()->create([
            'name' => $name,
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
    }

    /** @param list<string> $permissions */
    private function siteUser(Site $site, array $permissions, ?string $name = null): User
    {
        $user = $this->userWithPermissions($permissions, $name);
        $this->currentProfile($user, $site);

        return $user;
    }

    private function currentProfile(User $user, ?Site $site): void
    {
        HrEmployeeProfile::query()->create([
            'user_id' => $user->id,
            'employee_number' => 'EMP-OPS-DASH-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Operations Dashboard Test',
            'position_role' => 'operations',
            'employment_type' => 'full_time',
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
            'primary_site_id' => $site?->id,
            'secondary_site_ids' => [],
        ]);
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions, ?string $name = null): User
    {
        $user = User::factory()->create([
            'name' => $name ?? 'Operations Dashboard Viewer',
            'role' => 'staff',
            'approved_at' => now(),
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertSame(count($permissions), $permissionIds->count());
        $user->permissionOverrides()->sync($permissionIds->mapWithKeys(
            fn (int $permissionId): array => [$permissionId => ['allowed' => true]],
        )->all());

        return $user;
    }

    /** @param array<string, mixed> $fixture */
    private function assertForeignSentinelsAbsent($response, array $fixture): void
    {
        $response
            ->assertDontSee($fixture['siteB']->name)
            ->assertDontSee($fixture['staffB']->name)
            ->assertDontSee($fixture['clientB']->first_name)
            ->assertDontSee('shift-'.$fixture['shiftsB'][0]->id)
            ->assertDontSee('shift-'.$fixture['shiftsB'][1]->id)
            ->assertDontSee('timesheet-'.$fixture['timesheetsB'][0]->id)
            ->assertDontSee('timesheet-'.$fixture['timesheetsB'][1]->id)
            ->assertDontSee('ts-'.$fixture['timesheetsB'][0]->id)
            ->assertDontSee('ts-'.$fixture['timesheetsB'][1]->id)
            ->assertDontSee('inc-'.$fixture['incidentB']->id)
            ->assertDontSee('inc-'.$fixture['forgedIncident']->id)
            ->assertDontSee('/sites/'.$fixture['siteB']->id);
    }
}
