<?php

declare(strict_types=1);

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\FleetAssets\HandoverController;
use App\Models\Asset;
use App\Models\AuditLog;
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

    public function test_handover_creation_rejects_an_incoming_user_from_another_tenant(): void
    {
        $site = Site::factory()->create(['tenant_id' => 301]);
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $manager = $this->tenantFleetManager(301);
        $foreignWorker = User::factory()->create([
            'organization_id' => 302,
            'role' => 'support_worker',
        ]);

        $this->actingAs($manager)
            ->post('/fleet-assets/handovers', $this->handoverPayload($vehicle, $foreignWorker))
            ->assertForbidden();

        $this->assertDatabaseCount('fleet_shift_handovers', 0);
    }

    public function test_handover_creation_rejects_an_accessible_non_vehicle_without_writing_audit_history(): void
    {
        $site = Site::factory()->create(['tenant_id' => 303]);
        $nonVehicle = Asset::factory()->create([
            'site_id' => $site->id,
            'category' => 'medical_device',
        ]);
        $manager = $this->tenantFleetManager(303);
        $incoming = $this->tenantUser(303, 'support_worker', $site);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($manager)
            ->post('/fleet-assets/handovers', $this->handoverPayload($nonVehicle, $incoming))
            ->assertForbidden();

        $this->assertDatabaseCount('fleet_shift_handovers', 0);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_handover_creation_rejects_a_client_portal_user_as_incoming_staff(): void
    {
        $site = Site::factory()->create(['tenant_id' => 311]);
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $manager = $this->tenantFleetManager(311);
        $clientPortalUser = User::factory()->create([
            'organization_id' => 311,
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
        $site = Site::factory()->create(['tenant_id' => 312]);
        $otherSite = Site::factory()->create(['tenant_id' => 312]);
        $vehicle = Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $otherSite->id,
        ]);
        $manager = $this->tenantFleetManager(312);
        $wrongSiteWorker = $this->tenantUser(312, 'support_worker', $otherSite);

        $this->actingAs($manager)
            ->post('/fleet-assets/handovers', $this->handoverPayload($vehicle, $wrongSiteWorker))
            ->assertForbidden();

        $this->assertDatabaseCount('fleet_shift_handovers', 0);
    }

    public function test_handover_creation_rejects_unapproved_inactive_and_profileless_recipients(): void
    {
        $site = Site::factory()->create(['tenant_id' => 313]);
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $manager = $this->tenantFleetManager(313);
        $unapproved = $this->tenantUser(313, 'support_worker', $site, false);
        $inactive = $this->tenantUser(313, 'support_worker', $site);
        $inactive->hrEmployeeProfile()->update(['is_active' => false]);
        $profileless = $this->tenantUser(313);

        foreach ([$unapproved, $inactive, $profileless] as $recipient) {
            $this->actingAs($manager)
                ->post('/fleet-assets/handovers', $this->handoverPayload($vehicle, $recipient))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('fleet_shift_handovers', 0);
    }

    public function test_platform_admin_cannot_create_a_cross_tenant_recipient_site_tuple(): void
    {
        $site = Site::factory()->create(['tenant_id' => 314]);
        $foreignSite = Site::factory()->create(['tenant_id' => 315]);
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $platformAdmin = User::factory()->create([
            'organization_id' => null,
            'approved_at' => now(),
            'role' => 'admin',
        ]);
        $platformAdmin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $foreignWorker = $this->tenantUser(315, 'support_worker', $foreignSite);

        $this->actingAs($platformAdmin)
            ->post('/fleet-assets/handovers', $this->handoverPayload($vehicle, $foreignWorker))
            ->assertForbidden();

        $this->assertDatabaseCount('fleet_shift_handovers', 0);
    }

    public function test_organization_less_platform_admin_cannot_originate_even_a_valid_tenant_handover(): void
    {
        $site = Site::factory()->create(['tenant_id' => 315]);
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $incoming = $this->tenantUser(315, 'support_worker', $site);
        $platformAdmin = User::factory()->create([
            'organization_id' => null,
            'approved_at' => now(),
            'role' => 'admin',
        ]);
        $platformAdmin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $this->actingAs($platformAdmin)
            ->post('/fleet-assets/handovers', $this->handoverPayload($vehicle, $incoming))
            ->assertForbidden();

        $this->assertDatabaseCount('fleet_shift_handovers', 0);
    }

    public function test_handover_creation_rolls_back_when_strict_audit_writing_fails(): void
    {
        $site = Site::factory()->create(['tenant_id' => 316]);
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $manager = $this->tenantFleetManager(316);
        $incoming = $this->tenantUser(316, 'support_worker', $site);

        $caught = $this->captureStrictAuditFailure(fn () => $this->actingAs($manager)
            ->post('/fleet-assets/handovers', $this->handoverPayload($vehicle, $incoming)));

        $this->assertSame('Simulated Fleet strict audit failure.', $caught?->getMessage());
        $this->assertDatabaseCount('fleet_shift_handovers', 0);
    }

    public function test_accept_reauthorizes_the_locked_handover_instead_of_trusting_a_stale_participant(): void
    {
        $site = Site::factory()->create(['tenant_id' => 321]);
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $outgoing = $this->tenantUser(321, 'support_worker', $site);
        $originalIncoming = $this->tenantUser(321, 'support_worker', $site);
        $replacementIncoming = $this->tenantUser(321, 'support_worker', $site);
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
        $site = Site::factory()->create(['tenant_id' => 331]);
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $outgoing = $this->tenantUser(331, 'support_worker', $site);
        $incoming = $this->tenantUser(331, 'support_worker', $site);

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

    public function test_accept_rejects_poisoned_handover_tenant_and_outgoing_user_tuples(): void
    {
        $site = Site::factory()->create(['tenant_id' => 332]);
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $outgoing = $this->tenantUser(332, 'support_worker', $site);
        $incoming = $this->tenantUser(332, 'support_worker', $site);

        $wrongTenant = $this->makeHandover($vehicle, $outgoing, $incoming);
        FleetShiftHandover::query()->whereKey($wrongTenant->id)->update(['tenant_id' => 999332]);
        $this->actingAs($incoming)
            ->post("/fleet-assets/handovers/{$wrongTenant->id}/accept")
            ->assertForbidden();
        $this->assertSame('pending_acceptance', $wrongTenant->fresh()->status);

        $foreignOutgoing = $this->tenantUser(333);
        $wrongOutgoing = $this->makeHandover($vehicle, $foreignOutgoing, $incoming);
        FleetShiftHandover::query()->whereKey($wrongOutgoing->id)->update(['tenant_id' => $site->tenant_id]);
        $this->actingAs($incoming)
            ->post("/fleet-assets/handovers/{$wrongOutgoing->id}/accept")
            ->assertForbidden();
        $this->assertSame('pending_acceptance', $wrongOutgoing->fresh()->status);
    }

    public function test_accept_and_dispute_reject_a_recipient_who_became_ineligible_after_creation(): void
    {
        $site = Site::factory()->create(['tenant_id' => 334]);
        $otherSite = Site::factory()->create(['tenant_id' => 334]);
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $outgoing = $this->tenantUser(334, 'support_worker', $site);
        $incoming = $this->tenantUser(334, 'support_worker', $site);

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
        $site = Site::factory()->create(['tenant_id' => 335]);
        $vehicle = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $outgoing = $this->tenantUser(335, 'support_worker', $site);
        $incoming = $this->tenantUser(335, 'support_worker', $site);

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
            'tenant_id' => $outgoing->organization_id,
            'asset_id' => $vehicle->id,
            'outgoing_user_id' => $outgoing->id,
            'incoming_user_id' => $incoming->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);
    }

    private function tenantFleetManager(int $organizationId): User
    {
        $manager = $this->tenantUser($organizationId, 'manager');
        $permission = Permission::query()->where('key', 'fleet.manage')->firstOrFail();
        $manager->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);

        return $manager;
    }

    private function tenantUser(
        int $organizationId,
        string $role = 'support_worker',
        ?Site $site = null,
        bool $approved = true,
    ): User {
        $user = User::factory()->create([
            'organization_id' => $organizationId,
            'approved_at' => $approved ? now() : null,
            'role' => $role,
        ]);

        if ($site) {
            HrEmployeeProfile::factory()->create([
                'tenant_id' => $organizationId,
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
