<?php

declare(strict_types=1);

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\FleetAssets\HandoverController;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\FleetShiftHandover;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FleetHandoverMutationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_handover_creation_rejects_an_incoming_user_from_another_site(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $manager = $this->siteFleetManager($site);
        $otherSiteWorker = $this->siteUser('support_worker', $otherSite);

        $this->actingAs($manager)
            ->post('/fleet-assets/handovers', $this->handoverPayload($vehicle, $otherSiteWorker))
            ->assertForbidden();

        $this->assertDatabaseCount('fleet_shift_handovers', 0);
    }

    public function test_handover_creation_rejects_an_accessible_non_vehicle_without_writing_audit_history(): void
    {
        $site = Site::factory()->create();
        $nonVehicle = Asset::factory()->create([
            'site_id' => $site->id,
            'category' => 'medical_device',
        ]);
        $manager = $this->siteFleetManager($site);
        $incoming = $this->siteUser('support_worker', $site);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($manager)
            ->post('/fleet-assets/handovers', $this->handoverPayload($nonVehicle, $incoming))
            ->assertForbidden();

        $this->assertDatabaseCount('fleet_shift_handovers', 0);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_handover_creation_rejects_a_client_portal_user_as_incoming_staff(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $manager = $this->siteFleetManager($site);
        $clientPortalUser = User::factory()->create([
            'role' => 'client',
        ]);
        $clientPortalUser->roles()->attach(Role::query()->where('name', 'client')->firstOrFail());

        $this->actingAs($manager)
            ->post('/fleet-assets/handovers', $this->handoverPayload($vehicle, $clientPortalUser))
            ->assertForbidden();

        $this->assertDatabaseCount('fleet_shift_handovers', 0);
    }

    public function test_handover_creation_requires_the_recipient_to_be_currently_eligible_for_the_authoritative_site(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $otherSite->id,
        ]);
        $manager = $this->siteFleetManager($site);
        $wrongSiteWorker = $this->siteUser('support_worker', $otherSite);

        $this->actingAs($manager)
            ->post('/fleet-assets/handovers', $this->handoverPayload($vehicle, $wrongSiteWorker))
            ->assertForbidden();

        $this->assertDatabaseCount('fleet_shift_handovers', 0);
    }

    public function test_handover_creation_rejects_unapproved_inactive_and_profileless_recipients(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $manager = $this->siteFleetManager($site);
        $unapproved = $this->siteUser('support_worker', $site, false);
        $inactive = $this->siteUser('support_worker', $site);
        $inactive->hrEmployeeProfile()->update(['is_active' => false]);
        $profileless = $this->siteUser();

        foreach ([$unapproved, $inactive, $profileless] as $recipient) {
            $this->actingAs($manager)
                ->post('/fleet-assets/handovers', $this->handoverPayload($vehicle, $recipient))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('fleet_shift_handovers', 0);
    }

    public function test_all_sites_manager_cannot_create_a_recipient_tuple_for_another_site(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $applicationManager = $this->applicationFleetManager($site);
        $otherSiteWorker = $this->siteUser('support_worker', $otherSite);

        $this->actingAs($applicationManager)
            ->post('/fleet-assets/handovers', $this->handoverPayload($vehicle, $otherSiteWorker))
            ->assertForbidden();

        $this->assertDatabaseCount('fleet_shift_handovers', 0);
    }

    public function test_all_sites_manager_cannot_originate_without_current_assignment_to_the_vehicle_site(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $incoming = $this->siteUser('support_worker', $site);
        $applicationManager = $this->applicationFleetManager();

        $this->actingAs($applicationManager)
            ->post('/fleet-assets/handovers', $this->handoverPayload($vehicle, $incoming))
            ->assertForbidden();

        $this->assertDatabaseCount('fleet_shift_handovers', 0);
    }

    public function test_handover_creation_rolls_back_when_strict_audit_writing_fails(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $manager = $this->siteFleetManager($site);
        $incoming = $this->siteUser('support_worker', $site);

        $caught = $this->captureStrictAuditFailure(fn () => $this->actingAs($manager)
            ->post('/fleet-assets/handovers', $this->handoverPayload($vehicle, $incoming)));

        $this->assertSame('Simulated Fleet strict audit failure.', $caught?->getMessage());
        $this->assertDatabaseCount('fleet_shift_handovers', 0);
    }

    public function test_accept_reauthorizes_the_locked_handover_instead_of_trusting_a_stale_participant(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $outgoing = $this->siteUser('support_worker', $site);
        $originalIncoming = $this->siteUser('support_worker', $site);
        $replacementIncoming = $this->siteUser('support_worker', $site);
        $handover = $this->makeHandover($vehicle, $outgoing, $originalIncoming);
        $staleHandover = FleetShiftHandover::query()->findOrFail($handover->id);

        FleetShiftHandover::query()->whereKey($handover->id)->update([
            'incoming_user_id' => $replacementIncoming->id,
        ]);

        $request = Request::create("/fleet-assets/handovers/{$handover->id}/accept", 'POST');
        $request->setUserResolver(fn (): User => $originalIncoming);

        try {
            app(HandoverController::class)->accept($request, $staleHandover);
            $this->fail('A stale participant must not accept a handover after its recipient changes.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame('pending_acceptance', $handover->fresh()->status);
        $this->assertNull($handover->fresh()->accepted_at);
    }

    public function test_accept_and_dispute_do_not_overwrite_a_transition_that_won_the_race(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $outgoing = $this->siteUser('support_worker', $site);
        $incoming = $this->siteUser('support_worker', $site);

        $acceptTarget = $this->makeHandover($vehicle, $outgoing, $incoming);
        $staleAcceptTarget = FleetShiftHandover::query()->findOrFail($acceptTarget->id);
        FleetShiftHandover::query()->whereKey($acceptTarget->id)->update([
            'status' => 'disputed',
            'notes' => 'A dispute won the race.',
        ]);

        $acceptRequest = Request::create("/fleet-assets/handovers/{$acceptTarget->id}/accept", 'POST');
        $acceptRequest->setUserResolver(fn (): User => $incoming);
        app(HandoverController::class)->accept($acceptRequest, $staleAcceptTarget);

        $this->assertSame('disputed', $acceptTarget->fresh()->status);
        $this->assertNull($acceptTarget->fresh()->accepted_at);

        $disputeTarget = $this->makeHandover($vehicle, $outgoing, $incoming);
        $staleDisputeTarget = FleetShiftHandover::query()->findOrFail($disputeTarget->id);
        FleetShiftHandover::query()->whereKey($disputeTarget->id)->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $disputeRequest = Request::create(
            "/fleet-assets/handovers/{$disputeTarget->id}/dispute",
            'POST',
            ['dispute_reason' => 'A stale dispute must not replace acceptance.'],
        );
        $disputeRequest->setUserResolver(fn (): User => $incoming);
        app(HandoverController::class)->dispute($disputeRequest, $staleDisputeTarget);

        $this->assertSame('accepted', $disputeTarget->fresh()->status);
        $this->assertStringNotContainsString('stale dispute', strtolower((string) $disputeTarget->fresh()->notes));
    }

    public function test_accept_rejects_poisoned_vehicle_site_client_and_outgoing_user_tuples(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $poisonedVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
        ]);
        $outgoing = $this->siteUser('support_worker', $site);
        $incoming = $this->siteUser('support_worker', $site);

        $wrongVehicleSite = $this->makeHandover($poisonedVehicle, $outgoing, $incoming);
        $poisonedVehicle->update(['site_id' => $otherSite->id]);
        $this->actingAs($incoming)
            ->post("/fleet-assets/handovers/{$wrongVehicleSite->id}/accept")
            ->assertForbidden();
        $this->assertSame('pending_acceptance', $wrongVehicleSite->fresh()->status);

        $vehicle = Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
        ]);
        $otherSiteOutgoing = $this->siteUser('support_worker', $otherSite);
        $wrongOutgoing = $this->makeHandover($vehicle, $otherSiteOutgoing, $incoming);
        $this->actingAs($incoming)
            ->post("/fleet-assets/handovers/{$wrongOutgoing->id}/accept")
            ->assertForbidden();
        $this->assertSame('pending_acceptance', $wrongOutgoing->fresh()->status);
    }

    public function test_accept_and_dispute_reject_a_recipient_who_became_ineligible_after_creation(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $outgoing = $this->siteUser('support_worker', $site);
        $incoming = $this->siteUser('support_worker', $site);

        $acceptTarget = $this->makeHandover($vehicle, $outgoing, $incoming);
        $incoming->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id]);
        $this->actingAs($incoming)
            ->post("/fleet-assets/handovers/{$acceptTarget->id}/accept")
            ->assertForbidden();
        $this->assertSame('pending_acceptance', $acceptTarget->fresh()->status);

        $incoming->hrEmployeeProfile()->update([
            'primary_site_id' => $site->id,
            'is_active' => false,
        ]);
        $disputeTarget = $this->makeHandover($vehicle, $outgoing, $incoming);
        $this->actingAs($incoming)
            ->post("/fleet-assets/handovers/{$disputeTarget->id}/dispute", [
                'dispute_reason' => 'No longer eligible.',
            ])
            ->assertForbidden();
        $this->assertSame('pending_acceptance', $disputeTarget->fresh()->status);
    }

    public function test_accept_and_dispute_roll_back_when_strict_audit_writing_fails(): void
    {
        $site = Site::factory()->create();
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $outgoing = $this->siteUser('support_worker', $site);
        $incoming = $this->siteUser('support_worker', $site);

        $acceptTarget = $this->makeHandover($vehicle, $outgoing, $incoming);
        $caught = $this->captureStrictAuditFailure(fn () => $this->actingAs($incoming)
            ->post("/fleet-assets/handovers/{$acceptTarget->id}/accept"));
        $this->assertSame('Simulated Fleet strict audit failure.', $caught?->getMessage());
        $this->assertSame('pending_acceptance', $acceptTarget->fresh()->status);

        $disputeTarget = $this->makeHandover($vehicle, $outgoing, $incoming);
        $caught = $this->captureStrictAuditFailure(fn () => $this->actingAs($incoming)
            ->post("/fleet-assets/handovers/{$disputeTarget->id}/dispute", [
                'dispute_reason' => 'Audit rollback proof.',
            ]));
        $this->assertSame('Simulated Fleet strict audit failure.', $caught?->getMessage());
        $this->assertSame('pending_acceptance', $disputeTarget->fresh()->status);
        $this->assertNull($disputeTarget->fresh()->notes);
    }

    /** @return array<string, mixed> */
    private function handoverPayload(Asset $vehicle, User $incoming): array
    {
        return [
            'asset_id' => $vehicle->id,
            'incoming_user_id' => $incoming->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'keys_present' => true,
            'documents_present' => true,
            'first_aid_kit' => true,
            'fire_extinguisher' => true,
        ];
    }

    private function makeHandover(Asset $vehicle, User $outgoing, User $incoming): FleetShiftHandover
    {
        return FleetShiftHandover::query()->create([
            'asset_id' => $vehicle->id,
            'outgoing_user_id' => $outgoing->id,
            'incoming_user_id' => $incoming->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);
    }

    private function siteFleetManager(Site $site): User
    {
        $manager = $this->siteUser('manager', $site);
        $permission = Permission::query()->where('key', 'fleet.manage')->firstOrFail();
        $manager->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);

        return $manager;
    }

    private function applicationFleetManager(?Site $site = null): User
    {
        $manager = $this->siteUser('admin', $site);
        $manager->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        return $manager;
    }

    private function siteUser(
        string $role = 'support_worker',
        ?Site $site = null,
        bool $approved = true,
    ): User {
        $user = User::factory()->create([
            'approved_at' => $approved ? now() : null,
            'role' => $role,
        ]);

        if ($site) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $user->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
            ]);
        }

        return $user;
    }

    private function captureStrictAuditFailure(callable $mutation): ?RuntimeException
    {
        $eventName = 'eloquent.creating: '.AuditLog::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Simulated Fleet strict audit failure.');
        });
        $caught = null;

        $this->withoutExceptionHandling();
        try {
            $mutation();
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            $this->withExceptionHandling();
            Event::forget($eventName);
        }

        return $caught;
    }
}
