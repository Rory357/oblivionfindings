<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\FleetShiftHandover;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class UserSiteAccessCanonicalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_query_and_direct_access_require_matching_site_client_and_current_worker_relationships(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->currentSiteUser($site, permissions: ['reports.viewAny']);
        $worker = $this->currentSiteUser($site);
        $otherSiteWorker = $this->currentSiteUser($otherSite);
        $client = $this->clientAt($site);
        $clientWithoutSite = $this->clientAt(null);
        $validShift = $this->shiftAt($site, $client, $worker);
        $clientFallbackShift = $this->shiftAt(null, $client, $worker);
        $siteConflict = $this->shiftAt($site, $client, $worker);
        $workerConflict = $this->shiftAt($site, $client, $otherSiteWorker);
        $missingProvenance = $this->shiftAt(null, $clientWithoutSite, $worker);
        Shift::query()->whereKey($siteConflict->id)->update(['site_id' => $otherSite->id]);
        $service = app(UserSiteAccessService::class);
        $bypass = ['reports.viewAny'];

        $query = Shift::query()->whereIn('id', [
            $validShift->id,
            $clientFallbackShift->id,
            $siteConflict->id,
            $workerConflict->id,
            $missingProvenance->id,
        ])->orderBy('id');
        $service->applyShiftScope($query, $viewer, $bypass);

        $this->assertSame([$validShift->id, $clientFallbackShift->id], $query->pluck('id')->all());
        $service->assertCanAccessShift($viewer, $validShift, $bypass);
        $service->assertCanAccessShift($viewer, $clientFallbackShift, $bypass);
        foreach ([$siteConflict, $workerConflict, $missingProvenance] as $shift) {
            $this->assertAccessDenied(
                fn () => $service->assertCanAccessShift($viewer, $shift->fresh(), $bypass),
            );
        }
    }

    public function test_timesheet_query_and_direct_access_require_one_agreed_site_and_matching_links(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->currentSiteUser($site, permissions: ['reports.viewAny']);
        $worker = $this->currentSiteUser($site);
        $client = $this->clientAt($site);
        $validShift = $this->shiftAt($site, $client, $worker);
        $fallbackShift = $this->shiftAt($site, $client, $worker);
        $conflictedShift = $this->shiftAt($site, $client, $worker);
        $validTimesheet = $this->timesheetFor($validShift, $client, $worker, $site);
        $linkedShiftFallback = Timesheet::factory()->create([
            'shift_id' => $fallbackShift->id,
            'client_id' => $client->id,
            'user_id' => $worker->id,
            'shift_site_id' => null,
            'site_id' => null,
        ]);
        $snapshotConflict = $this->timesheetFor($conflictedShift, $client, $worker, $site);
        Timesheet::query()->whereKey($snapshotConflict->id)->update(['site_id' => $otherSite->id]);
        $missingProvenance = Timesheet::factory()->create([
            'shift_id' => null,
            'client_id' => null,
            'user_id' => $worker->id,
            'shift_site_id' => null,
            'site_id' => null,
        ]);
        $service = app(UserSiteAccessService::class);
        $bypass = ['reports.viewAny'];

        $query = Timesheet::query()->whereIn('id', [
            $validTimesheet->id,
            $linkedShiftFallback->id,
            $snapshotConflict->id,
            $missingProvenance->id,
        ])->orderBy('id');
        $service->applyTimesheetScope($query, $viewer, $bypass);

        $this->assertSame(
            [$validTimesheet->id, $linkedShiftFallback->id],
            $query->pluck('id')->all(),
        );
        $service->assertCanAccessTimesheet($viewer, $validTimesheet, $bypass);
        $service->assertCanAccessTimesheet($viewer, $linkedShiftFallback, $bypass);
        foreach ([$snapshotConflict, $missingProvenance] as $timesheet) {
            $this->assertAccessDenied(
                fn () => $service->assertCanAccessTimesheet($viewer, $timesheet->fresh(), $bypass),
            );
        }
    }

    public function test_shift_handover_query_and_direct_access_require_all_sources_to_share_one_site(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->currentSiteUser($site, permissions: ['reports.viewAny']);
        $outgoing = $this->currentSiteUser($site);
        $incoming = $this->currentSiteUser($site);
        $otherIncoming = $this->currentSiteUser($otherSite);
        $client = $this->clientAt($site);
        $outgoingShift = $this->shiftAt($site, $client, $outgoing);
        $incomingShift = $this->shiftAt($site, $client, $incoming);
        $otherIncomingShift = $this->shiftAt($otherSite, $this->clientAt($otherSite), $otherIncoming);
        $validHandover = $this->shiftHandover(
            $outgoingShift,
            $incomingShift,
            $client,
            $outgoing,
            $incoming,
        );
        $conflictedOutgoingShift = $this->shiftAt($site, $client, $outgoing);
        $conflictedHandover = $this->shiftHandover(
            $conflictedOutgoingShift,
            $incomingShift,
            $client,
            $outgoing,
            $incoming,
        );
        ShiftHandover::query()->whereKey($conflictedHandover->id)->update([
            'incoming_shift_id' => $otherIncomingShift->id,
            'incoming_staff_id' => $otherIncoming->id,
        ]);
        $clientWithoutSite = $this->clientAt($site);
        $shiftWithoutSite = $this->shiftAt($site, $clientWithoutSite, $outgoing);
        $missingProvenance = $this->shiftHandover(
            $shiftWithoutSite,
            null,
            $clientWithoutSite,
            $outgoing,
            null,
        );
        Client::query()->whereKey($clientWithoutSite->id)->update(['site_id' => null]);
        Shift::query()->whereKey($shiftWithoutSite->id)->update(['site_id' => null]);
        $service = app(UserSiteAccessService::class);
        $bypass = ['reports.viewAny'];

        $query = ShiftHandover::query()->whereIn('id', [
            $validHandover->id,
            $conflictedHandover->id,
            $missingProvenance->id,
        ])->orderBy('id');
        $service->applyHandoverScope($query, $viewer, $bypass);

        $this->assertSame([$validHandover->id], $query->pluck('id')->all());
        $service->assertCanAccessHandover($viewer, $validHandover, $bypass);
        foreach ([$conflictedHandover, $missingProvenance] as $handover) {
            $this->assertAccessDenied(
                fn () => $service->assertCanAccessHandover($viewer, $handover->fresh(), $bypass),
            );
        }
    }

    public function test_control_room_alert_query_and_direct_access_share_immutable_site_precedence(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->currentSiteUser($site);
        $broadViewer = $this->currentSiteUser($otherSite, permissions: ['reports.viewAny']);
        $siteClient = $this->clientAt($site);
        $otherSiteClient = $this->clientAt($otherSite);
        $directSiteAlert = ControlRoomAlert::factory()->create([
            'site_id' => $site->id,
            'client_id' => $otherSiteClient->id,
            'context' => ['site_id' => $otherSite->id],
        ]);
        $clientSiteAlert = ControlRoomAlert::factory()->create([
            'site_id' => null,
            'client_id' => $siteClient->id,
            'context' => ['site_id' => $otherSite->id],
        ]);
        $contextSiteAlert = ControlRoomAlert::factory()->create([
            'site_id' => null,
            'client_id' => null,
            'context' => ['site_id' => $site->id],
        ]);
        $missingProvenance = ControlRoomAlert::factory()->create([
            'site_id' => null,
            'client_id' => null,
            'context' => [],
        ]);
        $service = app(UserSiteAccessService::class);

        $query = ControlRoomAlert::query()->whereIn('id', [
            $directSiteAlert->id,
            $clientSiteAlert->id,
            $contextSiteAlert->id,
            $missingProvenance->id,
        ])->orderBy('id');
        $service->applyAlertScope($query, $viewer);

        $this->assertSame(
            [$directSiteAlert->id, $clientSiteAlert->id, $contextSiteAlert->id],
            $query->pluck('id')->all(),
        );
        foreach ([$directSiteAlert, $clientSiteAlert, $contextSiteAlert] as $alert) {
            $service->assertCanAccessAlert($viewer, $alert);
        }

        $broadQuery = ControlRoomAlert::query()->whereKey($missingProvenance->id);
        $service->applyAlertScope($broadQuery, $broadViewer, ['reports.viewAny']);
        $this->assertFalse($broadQuery->exists());
        $this->assertAccessDenied(fn () => $service->assertCanAccessAlert(
            $broadViewer,
            $missingProvenance,
            ['reports.viewAny'],
        ));
    }

    public function test_fleet_recipient_eligibility_requires_current_staff_assigned_to_the_asset_site(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $eligible = $this->currentSiteUser($site);
        $otherSiteStaff = $this->currentSiteUser($otherSite);
        $endedStaff = $this->currentSiteUser($site, profileOverrides: [
            'end_date' => now()->subDay()->toDateString(),
        ]);
        $service = app(UserSiteAccessService::class);

        $query = User::query()->whereIn('id', [
            $eligible->id,
            $otherSiteStaff->id,
            $endedStaff->id,
        ])->orderBy('id');
        $service->applyFleetRecipientEligibility($query, $site->id);

        $this->assertSame([$eligible->id], $query->pluck('id')->all());
    }

    public function test_fleet_handover_query_and_direct_access_keep_asset_and_current_staff_integrity_under_bypass(): void
    {
        $site = Site::factory()->create();
        $homeSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->currentSiteUser($site, permissions: ['fleet.manage']);
        $outgoing = $this->currentSiteUser($site);
        $incoming = $this->currentSiteUser($site);
        $homeOutgoing = $this->currentSiteUser($homeSite);
        $homeIncoming = $this->currentSiteUser($homeSite);
        $otherSiteStaff = $this->currentSiteUser($otherSite);
        $vehicle = Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $homeSite->id,
        ]);
        $validHandover = $this->fleetHandover($vehicle, $outgoing, $incoming);
        $homeOnlyVehicle = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $homeSite->id,
        ]);
        $homeFallbackHandover = $this->fleetHandover($homeOnlyVehicle, $homeOutgoing, $homeIncoming);
        $incomingConflict = $this->fleetHandover($vehicle, $outgoing, $otherSiteStaff);
        $outgoingConflict = $this->fleetHandover($vehicle, $otherSiteStaff, $incoming);
        $unattributedVehicle = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => null,
        ]);
        $missingProvenance = $this->fleetHandover($unattributedVehicle, $outgoing, $incoming);
        $service = app(UserSiteAccessService::class);
        $bypass = ['fleet.manage'];

        $query = FleetShiftHandover::query()->whereIn('id', [
            $validHandover->id,
            $homeFallbackHandover->id,
            $incomingConflict->id,
            $outgoingConflict->id,
            $missingProvenance->id,
        ])->orderBy('id');
        $service->applyFleetHandoverScope($query, $viewer, $bypass);

        $this->assertSame(
            [$validHandover->id, $homeFallbackHandover->id],
            $query->pluck('id')->all(),
        );
        $service->assertCanAccessFleetHandover($viewer, $validHandover, $bypass);
        $service->assertCanAccessFleetHandover($viewer, $homeFallbackHandover, $bypass);
        foreach ([$incomingConflict, $outgoingConflict, $missingProvenance] as $handover) {
            $this->assertAccessDenied(
                fn () => $service->assertCanAccessFleetHandover($viewer, $handover, $bypass),
            );
        }
    }

    public function test_client_incident_query_and_direct_access_share_snapshot_shift_client_precedence(): void
    {
        $snapshotSite = Site::factory()->create();
        $currentSite = Site::factory()->create();
        $snapshotViewer = $this->currentSiteUser($snapshotSite);
        $currentViewer = $this->currentSiteUser($currentSite);
        $broadViewer = $this->currentSiteUser($snapshotSite, permissions: ['reports.viewAny']);
        $worker = $this->currentSiteUser($snapshotSite);
        $client = $this->clientAt($snapshotSite);
        $capturedShift = $this->shiftAt($snapshotSite, $client, $worker);
        $directSnapshot = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'shift_id' => $capturedShift->id,
            'site_id' => $snapshotSite->id,
            'reported_by' => $worker->id,
        ]);
        $shiftSnapshot = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'shift_id' => $capturedShift->id,
            'site_id' => null,
            'reported_by' => $worker->id,
        ]);
        $client->update(['site_id' => $currentSite->id]);
        $clientFallback = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'shift_id' => null,
            'site_id' => null,
            'reported_by' => $worker->id,
        ]);
        $clientWithoutSite = $this->clientAt(null);
        $missingProvenance = ClientIncident::factory()->create([
            'client_id' => $clientWithoutSite->id,
            'shift_id' => null,
            'site_id' => null,
            'reported_by' => $worker->id,
        ]);
        $service = app(UserSiteAccessService::class);

        foreach ([$directSnapshot, $shiftSnapshot] as $incident) {
            $query = ClientIncident::query()->whereKey($incident->id);
            $service->applyClientIncidentScope($query, $snapshotViewer);
            $this->assertTrue($query->exists());
            $service->assertCanAccessClientIncident($snapshotViewer, $incident->fresh());
        }

        $clientQuery = ClientIncident::query()->whereKey($clientFallback->id);
        $service->applyClientIncidentScope($clientQuery, $currentViewer);
        $this->assertTrue($clientQuery->exists());
        $service->assertCanAccessClientIncident($currentViewer, $clientFallback->fresh());

        $missingQuery = ClientIncident::query()->whereKey($missingProvenance->id);
        $service->applyClientIncidentScope($missingQuery, $broadViewer, ['reports.viewAny']);
        $this->assertFalse($missingQuery->exists());
        $this->assertAccessDenied(fn () => $service->assertCanAccessClientIncident(
            $broadViewer,
            $missingProvenance->fresh(),
            ['reports.viewAny'],
        ));
    }

    /**
     * @param  array<int, string>  $permissions
     * @param  array<string, mixed>  $profileOverrides
     */
    private function currentSiteUser(
        Site $site,
        array $permissions = [],
        array $profileOverrides = [],
    ): User {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
        HrEmployeeProfile::query()->create([
            'user_id' => $user->id,
            'employee_number' => 'EMP-USACI-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            ...$profileOverrides,
        ]);

        foreach ($permissions as $permission) {
            $this->grantPermission($user, $permission);
        }

        return $user;
    }

    private function grantPermission(User $user, string $key): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            [
                'description' => $key,
                'group' => 'test',
                'module' => 'Test',
            ],
        );
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    private function clientAt(?Site $site): Client
    {
        return Client::factory()->create([
            'site_id' => $site?->id,
            'status' => 'active',
        ]);
    }

    private function shiftAt(?Site $site, Client $client, User $worker): Shift
    {
        return Shift::factory()->create([
            'site_id' => $site?->id,
            'client_id' => $client->id,
            'user_id' => $worker->id,
        ]);
    }

    private function timesheetFor(Shift $shift, Client $client, User $worker, Site $site): Timesheet
    {
        return Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'client_id' => $client->id,
            'user_id' => $worker->id,
            'shift_site_id' => $site->id,
            'site_id' => $site->id,
        ]);
    }

    private function shiftHandover(
        Shift $outgoingShift,
        ?Shift $incomingShift,
        Client $client,
        User $outgoing,
        ?User $incoming,
    ): ShiftHandover {
        return ShiftHandover::query()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift?->id,
            'client_id' => $client->id,
            'outgoing_staff_id' => $outgoing->id,
            'incoming_staff_id' => $incoming?->id,
            'status' => 'submitted',
            'handover_notes' => 'Canonical Site relationship handover.',
            'tasks_pending' => [],
            'medications_due' => [],
            'incidents_to_note' => [],
            'submitted_at' => now(),
            'submitted_by' => $outgoing->id,
        ]);
    }

    private function fleetHandover(
        Asset $asset,
        User $outgoing,
        User $incoming,
    ): FleetShiftHandover {
        return FleetShiftHandover::query()->create([
            'asset_id' => $asset->id,
            'outgoing_user_id' => $outgoing->id,
            'incoming_user_id' => $incoming->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);
    }

    private function assertAccessDenied(callable $assertion): void
    {
        try {
            $assertion();
            $this->fail('A conflicting or missing canonical Site relationship must not be authorized.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
