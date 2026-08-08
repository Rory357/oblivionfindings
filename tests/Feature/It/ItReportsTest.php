<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function itReportsUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

function reportsProfile(Site $site, ?User $user = null): HrEmployeeProfile
{
    $user ??= User::factory()->create();

    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-RPT-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => $site->id,
        'start_date' => now()->subDays(10)->toDateString(),
        'is_active' => true,
    ]);
}

/** @param list<string> $permissionKeys */
function scopedItReportsUser(Site $site, array $permissionKeys): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'it-reports-'.str()->uuid(),
        'label' => 'IT reports scoped viewer',
        'level' => 40,
        'type' => 'custom',
    ]);
    $role->permissions()->sync(collect($permissionKeys)->map(
        fn (string $key): int => Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'it', 'module' => 'Operations'],
        )->id,
    ));
    $user->roles()->attach($role);
    reportsProfile($site, $user);

    return $user;
}

function assignReportsDevice(Device $device, Site $site, User $actor): void
{
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $actor->id,
    ]);
}

function linkReportsDevice(ItTicket $ticket, Device $device): void
{
    $ticket->links()->create([
        'relationship' => 'affected_device',
        'linkable_type' => $device->getMorphClass(),
        'linkable_id' => $device->id,
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->agent = itReportsUser('hr');            // it.view + it.manage
    $this->worker = itReportsUser('support_worker'); // it.request only
    reportsProfile($this->site, $this->agent);
    reportsProfile($this->site, $this->worker);
});

test('reports are agent-only and an empty installation gets a zeroed well-formed report', function () {
    // A self-service requester (no it.view) is refused the analytics endpoint.
    $this->actingAs($this->worker)->getJson('/it/reports/data')->assertForbidden();

    // An agent with no visible work gets zeros/nulls, never a 500.
    $json = $this->actingAs($this->agent)->getJson('/it/reports/data')->assertOk()->json();

    expect($json['kpis']['open'])->toBe(0);
    expect($json['kpis']['resolved'])->toBe(0);
    expect($json['kpis']['sla_compliance'])->toBeNull();
    expect($json['kpis']['csat_avg'])->toBeNull();
    expect($json['provisioning'])->toBe(['raised' => 0, 'fulfilled' => 0, 'avg_days' => null]);
    // Default window is 30 days, zero-filled.
    expect($json['range']['days'])->toBe(30);
    expect($json['trend'])->toHaveCount(30);
    expect(collect($json['trend'])->sum('created'))->toBe(0);
});

test('the report aggregates tickets and provisioning across the range', function () {
    $mk = fn (array $attrs) => ItTicket::factory()->create(array_merge([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
        'category' => 'hardware',
    ], $attrs));

    // Four OPEN tickets (point-in-time state).
    $mk(['priority' => 'urgent', 'status' => 'open', 'assigned_to_user_id' => null, 'sla_state' => 'at_risk']);
    $mk(['priority' => 'urgent', 'status' => 'open', 'assigned_to_user_id' => null, 'sla_state' => 'breached']);
    $mk(['priority' => 'high', 'status' => 'in_progress', 'assigned_to_user_id' => $this->agent->id, 'sla_state' => 'ok']);
    $mk(['priority' => 'normal', 'status' => 'open', 'assigned_to_user_id' => null, 'sla_state' => 'ok']);

    // Two RESOLVED-in-range tickets carrying SLA verdicts + CSAT.
    $mk([
        'priority' => 'normal', 'status' => 'resolved',
        'created_at' => now()->subHours(3), 'resolved_at' => now()->subHours(1), // 120 min
        'first_responded_at' => now()->subHours(2)->subMinutes(30),              // 30 min to first reply
        'sla_state' => 'met', 'csat_score' => 5, 'csat_submitted_at' => now()->subMinutes(30),
    ]);
    $mk([
        'priority' => 'normal', 'status' => 'resolved',
        'created_at' => now()->subHours(2), 'resolved_at' => now()->subHours(1), // 60 min
        'sla_state' => 'breached', 'csat_score' => 2, 'csat_submitted_at' => now()->subMinutes(20),
    ]);

    // Provisioning: 2 pending raised + 1 done fulfilled 2 days after raising.
    $profile = reportsProfile($this->site);
    ItProvisioningRequest::query()->create(['employee_profile_id' => $profile->id, 'type' => 'account', 'item' => 'Email', 'status' => 'pending']);
    ItProvisioningRequest::query()->create(['employee_profile_id' => $profile->id, 'type' => 'access', 'item' => 'VPN', 'status' => 'pending']);
    $done = ItProvisioningRequest::query()->create(['employee_profile_id' => $profile->id, 'type' => 'account', 'item' => 'AD', 'status' => 'done']);
    $done->forceFill(['created_at' => now()->subDays(2), 'fulfilled_at' => now()])->save();

    $json = $this->actingAs($this->agent)->getJson('/it/reports/data')->assertOk()->json();

    // KPIs — point-in-time state.
    expect($json['kpis']['open'])->toBe(4);
    expect($json['kpis']['unassigned'])->toBe(3);
    expect($json['kpis']['breaching'])->toBe(1);
    expect($json['kpis']['breached'])->toBe(1);
    expect($json['kpis']['resolved'])->toBe(2);

    // KPIs — flow over the range.
    expect($json['kpis']['avg_resolution_mins'])->toBe(90);   // (120 + 60) / 2
    expect($json['kpis']['avg_first_response_mins'])->toBe(30); // only one responded in range
    expect($json['kpis']['sla_compliance'])->toEqual(50.0);    // 1 met of 2 measured
    expect($json['kpis']['sla_met'])->toBe(1);
    expect($json['kpis']['sla_measured'])->toBe(2);
    expect($json['kpis']['csat_avg'])->toEqual(3.5);           // (5 + 2) / 2
    expect($json['kpis']['csat_response_rate'])->toEqual(100.0); // 2 rated of 2 resolved

    // Distributions (open only, canonical order, zero-filled).
    expect(collect($json['by_priority'])->firstWhere('name', 'urgent')['value'])->toBe(2);
    expect(collect($json['by_priority'])->firstWhere('name', 'high')['value'])->toBe(1);
    expect(collect($json['by_priority'])->firstWhere('name', 'low')['value'])->toBe(0);
    expect(collect($json['by_category'])->firstWhere('name', 'hardware')['value'])->toBe(4);

    // Trend zero-fill: the sums match the in-range totals regardless of buckets.
    expect(collect($json['trend'])->sum('created'))->toBe(6);
    expect(collect($json['trend'])->sum('resolved'))->toBe(2);

    // People.
    expect($json['top_requesters'][0])->toBe(['name' => $this->worker->name, 'count' => 6]);
    expect($json['agent_workload'][0])->toBe(['name' => $this->agent->name, 'open' => 1]);

    // Provisioning throughput.
    expect($json['provisioning']['raised'])->toBe(3);
    expect($json['provisioning']['fulfilled'])->toBe(1);
    expect($json['provisioning']['avg_days'])->toEqual(2.0);
});

test('report source projections require their exact permissions and canonical Site visibility', function () {
    $visibleSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $viewer = scopedItReportsUser($visibleSite, [
        'it.view',
        'securityDevices.devices.view',
    ]);
    $requester = User::factory()->create();

    $visibleDevices = Device::factory()->itInfrastructure()->count(2)->create();
    $hiddenDevice = Device::factory()->itInfrastructure()->create();
    foreach ($visibleDevices as $device) {
        assignReportsDevice($device, $visibleSite, $viewer);
    }
    assignReportsDevice($hiddenDevice, $hiddenSite, $viewer);

    $openIncident = ItTicket::factory()->create([
        'site_id' => $visibleSite->id,
        'requester_user_id' => $requester->id,
        'work_type' => 'incident',
        'status' => 'open',
    ]);
    foreach ($visibleDevices as $device) {
        linkReportsDevice($openIncident, $device);
    }

    $recoveredIncident = ItTicket::factory()->create([
        'site_id' => $visibleSite->id,
        'requester_user_id' => $requester->id,
        'work_type' => 'incident',
        'status' => 'resolved',
        'monitoring_recovered_at' => now(),
    ]);
    foreach ($visibleDevices as $device) {
        linkReportsDevice($recoveredIncident, $device);
    }

    $nonIncident = ItTicket::factory()->create([
        'site_id' => $visibleSite->id,
        'requester_user_id' => $requester->id,
        'work_type' => 'service_request',
        'status' => 'open',
        'monitoring_recovered_at' => now(),
    ]);
    linkReportsDevice($nonIncident, $visibleDevices->first());

    $hiddenIncident = ItTicket::factory()->create([
        'site_id' => $hiddenSite->id,
        'requester_user_id' => $requester->id,
        'work_type' => 'incident',
        'status' => 'open',
    ]);
    linkReportsDevice($hiddenIncident, $hiddenDevice);

    $report = $this->actingAs($viewer)->getJson('/it/reports/data')->assertOk()->json();

    expect($report['automation_outcomes'])->toMatchArray([
        'access' => 'restricted',
        'succeeded' => null,
        'failed' => null,
        'skipped' => null,
        'href' => null,
    ])->and($report['device_reliability'])->toMatchArray([
        'access' => 'allowed',
        'affected_devices' => 2,
        'open_incidents' => 1,
        'recovered' => 1,
    ]);

    $withoutDevicePermission = scopedItReportsUser($visibleSite, ['it.view']);
    $restricted = $this->actingAs($withoutDevicePermission)
        ->getJson('/it/reports/data')
        ->assertOk()
        ->json('device_reliability');

    expect($restricted)->toBe([
        'access' => 'restricted',
        'affected_devices' => null,
        'open_incidents' => null,
        'recovered' => null,
        'href' => null,
    ]);
});

test('per-card CSV export is agent-only, correct and injection-guarded', function () {
    // A self-service requester never reaches the export.
    $this->actingAs($this->worker)->get('/it/reports/export?card=trend')->assertForbidden();

    // A requester whose name is a spreadsheet-formula payload.
    $evil = itReportsUser('support_worker');
    $evil->forceFill(['name' => '=cmd|calc'])->save();
    ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $evil->id,
        'status' => 'open',
        'priority' => 'high',
    ]);

    // Top-requesters export: streamed CSV, and the formula cell is neutralised.
    $res = $this->actingAs($this->agent)->get('/it/reports/export?card=top_requesters');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
    $body = $res->streamedContent();
    expect($body)->toContain('Requester'); // header present (space-containing cells get CSV-quoted)
    expect($body)->toContain("'=cmd|calc"); // apostrophe-prefixed by SanitizesCsvOutput

    // By-priority export lists the canonical rows with the live count.
    $priority = $this->actingAs($this->agent)->get('/it/reports/export?card=by_priority')->streamedContent();
    expect($priority)->toContain('High,1');

    // The summary export dumps the KPI metric/value grid.
    $summary = $this->actingAs($this->agent)->get('/it/reports/export?card=summary')->streamedContent();
    expect($summary)->toContain('Open,1');

    // An unknown card falls back to the trend export rather than erroring.
    $this->actingAs($this->agent)->get('/it/reports/export?card=bogus')->assertOk();
});
