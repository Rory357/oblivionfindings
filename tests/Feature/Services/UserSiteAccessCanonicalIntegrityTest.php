<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FleetShiftHandover;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UserSiteAccessCanonicalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_shift_query_and_assert_reject_a_rostered_worker_from_another_tenant(): void
    {
        $site = Site::factory()->create(['tenant_id' => 501]);
        $viewer = $this->siteUser(501, $site);
        $foreignWorker = User::factory()->create(['organization_id' => 502]);
        $client = Client::factory()->create([
            'organization_id' => 501,
            'site_id' => $site->id,
        ]);
        $shift = Shift::factory()->create([
            'organization_id' => 501,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => $foreignWorker->id,
        ]);
        $service = app(UserSiteAccessService::class);

        $query = Shift::query()->whereKey($shift->id);
        $service->applyShiftScope($query, $viewer);
        $this->assertFalse($query->exists());
        $this->assertAccessDenied(fn () => $service->assertCanAccessShift($viewer, $shift));
    }

    public function test_shift_query_and_assert_reject_a_direct_site_from_another_tenant_even_for_platform_admin(): void
    {
        $poisonedSite = Site::factory()->create(['tenant_id' => 503]);
        $worker = User::factory()->create(['organization_id' => 504]);
        $client = Client::factory()->create([
            'organization_id' => 504,
            'site_id' => $poisonedSite->id,
        ]);
        $shift = Shift::factory()->create([
            'organization_id' => 504,
            'site_id' => $poisonedSite->id,
            'client_id' => $client->id,
            'user_id' => $worker->id,
        ]);
        $platformAdmin = User::factory()->create([
            'organization_id' => null,
            'approved_at' => now(),
            'role' => 'admin',
        ]);
        $platformAdmin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $service = app(UserSiteAccessService::class);

        $query = Shift::query()->whereKey($shift->id);
        $service->applyShiftScope($query, $platformAdmin, ['healthSafety.viewAllSites']);

        $this->assertFalse($query->exists());
        $this->assertAccessDenied(fn () => $service->assertCanAccessShift(
            $platformAdmin,
            $shift,
            ['healthSafety.viewAllSites'],
        ));
    }

    public function test_timesheet_query_and_assert_reject_snapshot_shift_and_client_contradictions(): void
    {
        $site = Site::factory()->create(['tenant_id' => 511]);
        $otherSite = Site::factory()->create(['tenant_id' => 511]);
        $viewer = $this->siteUser(511, $site);
        $worker = $this->siteUser(511, $site);
        $client = Client::factory()->create([
            'organization_id' => 511,
            'site_id' => $site->id,
        ]);
        $shift = Shift::factory()->create([
            'organization_id' => 511,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => $worker->id,
        ]);
        $timesheet = Timesheet::factory()->create([
            'user_id' => $worker->id,
            'client_id' => $client->id,
            'shift_id' => $shift->id,
            'shift_site_id' => $site->id,
        ]);
        Shift::query()->whereKey($shift->id)->update(['site_id' => $otherSite->id]);
        $service = app(UserSiteAccessService::class);

        $query = Timesheet::query()->whereKey($timesheet->id);
        $service->applyTimesheetScope($query, $viewer);
        $this->assertFalse($query->exists());
        $this->assertAccessDenied(fn () => $service->assertCanAccessTimesheet($viewer, $timesheet->fresh()));
    }

    public function test_timesheet_query_and_assert_both_reject_contradictory_direct_site_snapshots(): void
    {
        $site = Site::factory()->create(['tenant_id' => 512]);
        $otherSite = Site::factory()->create(['tenant_id' => 512]);
        $viewer = $this->siteUser(512, $site);
        $worker = $this->siteUser(512, $site);
        $timesheet = Timesheet::factory()->create([
            'user_id' => $worker->id,
            'client_id' => null,
            'shift_id' => null,
            'shift_site_id' => $site->id,
            'site_id' => $otherSite->id,
        ]);
        $service = app(UserSiteAccessService::class);

        $query = Timesheet::query()->whereKey($timesheet->id);
        $service->applyTimesheetScope($query, $viewer);

        $this->assertFalse($query->exists());
        $this->assertAccessDenied(fn () => $service->assertCanAccessTimesheet($viewer, $timesheet));
    }

    public function test_shift_handover_query_and_assert_require_all_relations_to_share_one_authoritative_site_and_tenant(): void
    {
        $site = Site::factory()->create(['tenant_id' => 521]);
        $otherSite = Site::factory()->create(['tenant_id' => 521]);
        $viewer = $this->siteUser(521, $site);
        $outgoing = $this->siteUser(521, $site);
        $incoming = $this->siteUser(521, $site);
        $client = Client::factory()->create([
            'organization_id' => 521,
            'site_id' => $site->id,
        ]);
        $outgoingShift = Shift::factory()->create([
            'organization_id' => 521,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => $outgoing->id,
        ]);
        $incomingShift = Shift::factory()->create([
            'organization_id' => 521,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => $incoming->id,
        ]);
        $handover = ShiftHandover::factory()->create([
            'organization_id' => 521,
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $client->id,
            'outgoing_staff_id' => $outgoing->id,
            'incoming_staff_id' => $incoming->id,
        ]);
        Shift::query()->whereKey($incomingShift->id)->update(['site_id' => $otherSite->id]);
        $service = app(UserSiteAccessService::class);

        $query = ShiftHandover::query()->whereKey($handover->id);
        $service->applyHandoverScope($query, $viewer);
        $this->assertFalse($query->exists());
        $this->assertAccessDenied(fn () => $service->assertCanAccessHandover($viewer, $handover->fresh()));
    }

    public function test_fleet_query_and_assert_apply_recipient_tenant_and_current_site_integrity_even_to_broad_viewers(): void
    {
        $site = Site::factory()->create(['tenant_id' => 531]);
        $otherSite = Site::factory()->create(['tenant_id' => 531]);
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $outgoing = $this->siteUser(531, $site);
        $wrongSiteIncoming = $this->siteUser(531, $otherSite);
        $handover = FleetShiftHandover::query()->create([
            'tenant_id' => 531,
            'asset_id' => $vehicle->id,
            'outgoing_user_id' => $outgoing->id,
            'incoming_user_id' => $wrongSiteIncoming->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);
        $platformAdmin = User::factory()->create([
            'organization_id' => null,
            'approved_at' => now(),
            'role' => 'admin',
        ]);
        $platformAdmin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $service = app(UserSiteAccessService::class);

        $query = FleetShiftHandover::query()->whereKey($handover->id);
        $service->applyFleetHandoverScope($query, $platformAdmin, ['fleet.manage']);
        $this->assertFalse($query->exists());
        $this->assertAccessDenied(fn () => $service->assertCanAccessFleetHandover(
            $wrongSiteIncoming,
            $handover,
            ['fleet.manage'],
        ));
        $this->assertAccessDenied(fn () => $service->assertCanAccessFleetHandover(
            $platformAdmin,
            $handover,
            ['fleet.manage'],
        ));
    }

    public function test_client_incident_snapshot_site_keeps_list_and_open_authorization_in_parity_after_a_client_moves(): void
    {
        $snapshotSite = Site::factory()->create(['tenant_id' => 541]);
        $currentSite = Site::factory()->create(['tenant_id' => 541]);
        $viewer = $this->siteUser(541, $snapshotSite);
        $client = Client::factory()->create([
            'organization_id' => 541,
            'site_id' => $snapshotSite->id,
        ]);
        $incident = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $snapshotSite->id,
            'reported_by' => $viewer->id,
        ]);
        $legacyFallback = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => null,
            'reported_by' => $viewer->id,
        ]);
        $client->update(['site_id' => $currentSite->id]);
        $service = app(UserSiteAccessService::class);

        $query = ClientIncident::query()->whereIn('id', [$incident->id, $legacyFallback->id]);
        $service->applyClientIncidentScope($query, $viewer);

        $this->assertSame([$incident->id], $query->pluck('id')->all());
        $service->assertCanAccessClientIncident($viewer, $incident->fresh());
        $this->assertAccessDenied(fn () => $service->assertCanAccessClientIncident(
            $viewer,
            $legacyFallback->fresh(),
        ));
    }

    public function test_client_incident_snapshot_site_never_overrides_foreign_client_organization_ownership(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 542]);
        $foreignSite = Site::factory()->create(['tenant_id' => 543]);
        $viewer = $this->siteUser(542, $localSite);
        $foreignClient = Client::factory()->create([
            'organization_id' => 543,
            'site_id' => $foreignSite->id,
        ]);
        $incident = ClientIncident::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $localSite->id,
            'reported_by' => $viewer->id,
        ]);
        $service = app(UserSiteAccessService::class);

        $query = ClientIncident::query()->whereKey($incident->id);
        $service->applyClientIncidentScope($query, $viewer);

        $this->assertFalse($query->exists());
        $this->assertAccessDenied(fn () => $service->assertCanAccessClientIncident(
            $viewer,
            $incident,
        ));
    }

    private function siteUser(int $organizationId, Site $site): User
    {
        $user = User::factory()->create([
            'organization_id' => $organizationId,
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => $organizationId,
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);

        return $user;
    }

    private function assertAccessDenied(callable $assertion): void
    {
        try {
            $assertion();
            $this->fail('A contradictory record tuple must not be authorized.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
