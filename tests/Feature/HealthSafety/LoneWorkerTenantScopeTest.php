<?php

declare(strict_types=1);

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoneWorkerTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_tenant_all_sites_permission_keeps_lone_worker_register_inside_its_organization(): void
    {
        $localSiteA = Site::factory()->create(['tenant_id' => 71, 'name' => 'Local Alpha']);
        $localSiteB = Site::factory()->create(['tenant_id' => 71, 'name' => 'Local Bravo']);
        $foreignSite = Site::factory()->create(['tenant_id' => 72, 'name' => 'Foreign Tenant Site']);
        $viewer = $this->tenantHsLead(71, $localSiteA);
        $localWorker = User::factory()->create(['organization_id' => 71]);
        $foreignWorker = User::factory()->create(['organization_id' => 72]);
        $localClientA = Client::factory()->create([
            'organization_id' => 71,
            'site_id' => $localSiteA->id,
        ]);
        $localClientB = Client::factory()->create([
            'organization_id' => 71,
            'site_id' => $localSiteB->id,
        ]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 72,
            'site_id' => $foreignSite->id,
        ]);
        $foreignClientUsingLocalSite = Client::factory()->create([
            'organization_id' => 72,
            'site_id' => $localSiteA->id,
        ]);
        $localShift = Shift::factory()->create([
            'organization_id' => 71,
            'site_id' => $localSiteA->id,
            'client_id' => $localClientA->id,
            'user_id' => $localWorker->id,
        ]);
        $foreignShift = Shift::factory()->create([
            'organization_id' => 72,
            'site_id' => $foreignSite->id,
            'client_id' => $foreignClient->id,
            'user_id' => $foreignWorker->id,
        ]);
        $foreignShiftUsingLocalSite = Shift::factory()->create([
            'organization_id' => 72,
            'site_id' => $localSiteA->id,
            'client_id' => $foreignClientUsingLocalSite->id,
            'user_id' => $foreignWorker->id,
        ]);

        $visibleDirect = $this->makeSession($localWorker, ['site_id' => $localSiteA->id]);
        $visibleClientFallback = $this->makeSession($localWorker, [
            'site_id' => null,
            'client_id' => $localClientB->id,
        ]);
        $visibleShiftFallback = $this->makeSession($localWorker, [
            'site_id' => null,
            'client_id' => $localClientA->id,
            'shift_id' => $localShift->id,
        ]);

        $this->makeSession($foreignWorker, ['site_id' => $foreignSite->id]);
        $this->makeSession($foreignWorker, [
            'site_id' => null,
            'client_id' => $foreignClient->id,
        ]);
        $this->makeSession($foreignWorker, [
            'site_id' => null,
            'client_id' => null,
            'shift_id' => $foreignShift->id,
        ]);
        $this->makeSession($localWorker, [
            'site_id' => null,
            'client_id' => $foreignClientUsingLocalSite->id,
        ]);
        $this->makeSession($localWorker, [
            'site_id' => null,
            'client_id' => null,
            'shift_id' => $foreignShiftUsingLocalSite->id,
        ]);
        $this->makeSession($localWorker, [
            'site_id' => null,
            'client_id' => null,
            'shift_id' => null,
        ]);
        $this->makeSession($localWorker, [
            'site_id' => $localSiteA->id,
            'client_id' => $foreignClient->id,
        ]);
        $this->makeSession($localWorker, [
            'site_id' => $localSiteA->id,
            'shift_id' => $foreignShift->id,
        ]);
        $this->makeSession($localWorker, [
            'site_id' => null,
            'client_id' => $localClientB->id,
            'shift_id' => $localShift->id,
        ]);

        $expectedIds = collect([
            $visibleDirect->id,
            $visibleClientFallback->id,
            $visibleShiftFallback->id,
        ])->sort()->values()->all();

        $this->actingAs($viewer)
            ->get('/health-safety/lone-workers?period=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sessions.data', fn ($sessions) => collect($sessions)->pluck('id')->sort()->values()->all() === $expectedIds)
                ->where('tabCounts.sessions', 3)
                ->where('hero.clusters.live.active', 3)
                ->where('options.sites', fn ($sites) => collect($sites)->pluck('id')->sort()->values()->all() === collect([
                    $localSiteA->id,
                    $localSiteB->id,
                ])->sort()->values()->all())
            );

        $this->actingAs($viewer)
            ->get('/health-safety/lone-workers?period=all&site_id='.$localSiteB->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sessions.data', fn ($sessions) => collect($sessions)
                    ->pluck('id')
                    ->values()
                    ->all() === [$visibleClientFallback->id]));
    }

    public function test_tenant_all_sites_permission_rejects_foreign_unattributed_and_conflicting_session_mutations(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 81]);
        $foreignSite = Site::factory()->create(['tenant_id' => 82]);
        $lead = $this->tenantHsLead(81, $localSite);
        $localWorker = User::factory()->create(['organization_id' => 81]);
        $foreignWorker = User::factory()->create(['organization_id' => 82]);
        $localClient = Client::factory()->create([
            'organization_id' => 81,
            'site_id' => $localSite->id,
        ]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 82,
            'site_id' => $foreignSite->id,
        ]);
        $foreignClientUsingLocalSite = Client::factory()->create([
            'organization_id' => 82,
            'site_id' => $localSite->id,
        ]);

        $visibleFallback = $this->makeSession($localWorker, [
            'site_id' => null,
            'client_id' => $localClient->id,
        ]);
        $foreign = $this->makeSession($foreignWorker, ['site_id' => $foreignSite->id]);
        $unattributed = $this->makeSession($localWorker, [
            'site_id' => null,
            'client_id' => null,
            'shift_id' => null,
        ]);
        $conflicting = $this->makeSession($localWorker, [
            'site_id' => $localSite->id,
            'client_id' => $foreignClient->id,
        ]);
        $foreignTenantOnLocalSite = $this->makeSession($localWorker, [
            'site_id' => null,
            'client_id' => $foreignClientUsingLocalSite->id,
        ]);

        foreach ([$foreign, $unattributed, $conflicting, $foreignTenantOnLocalSite] as $hiddenSession) {
            $this->actingAs($lead)
                ->post("/health-safety/lone-workers/sessions/{$hiddenSession->id}/end")
                ->assertForbidden();

            $this->assertSame('active', $hiddenSession->fresh()->status);
            $this->assertNull($hiddenSession->fresh()->ended_at);
        }

        $this->actingAs($lead)
            ->post("/health-safety/lone-workers/sessions/{$visibleFallback->id}/end")
            ->assertRedirect();

        $this->assertSame('completed', $visibleFallback->fresh()->status);
        $this->assertNotNull($visibleFallback->fresh()->ended_at);
    }

    public function test_organizationless_platform_admin_is_the_only_unrestricted_lone_worker_viewer(): void
    {
        $firstSite = Site::factory()->create(['tenant_id' => 91]);
        $secondSite = Site::factory()->create(['tenant_id' => 92]);
        $firstWorker = User::factory()->create(['organization_id' => 91]);
        $secondWorker = User::factory()->create(['organization_id' => 92]);
        $platformAdmin = User::factory()->create([
            'organization_id' => null,
            'role' => 'admin',
        ]);
        $platformAdmin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $first = $this->makeSession($firstWorker, ['site_id' => $firstSite->id]);
        $second = $this->makeSession($secondWorker, ['site_id' => $secondSite->id]);
        $unattributed = $this->makeSession($platformAdmin, [
            'site_id' => null,
            'client_id' => null,
            'shift_id' => null,
        ]);
        $expectedIds = collect([$first->id, $second->id])->sort()->values()->all();

        $this->actingAs($platformAdmin)
            ->get('/health-safety/lone-workers?period=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sessions.data', fn ($sessions) => collect($sessions)->pluck('id')->sort()->values()->all() === $expectedIds)
                ->where('tabCounts.sessions', 2)
            );

        $this->actingAs($platformAdmin)
            ->post("/health-safety/lone-workers/sessions/{$unattributed->id}/end")
            ->assertForbidden();

        $this->assertSame('active', $unattributed->fresh()->status);
    }

    public function test_foreign_worker_and_shift_worker_conflicts_are_hidden_and_block_every_location_mutation(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 101]);
        $lead = $this->tenantHsLead(101, $localSite);
        $localWorker = User::factory()->create(['organization_id' => 101]);
        $foreignWorker = User::factory()->create(['organization_id' => 102]);
        $localClient = Client::factory()->create([
            'organization_id' => 101,
            'site_id' => $localSite->id,
        ]);
        $mismatchedShift = Shift::factory()->create([
            'organization_id' => 101,
            'site_id' => $localSite->id,
            'client_id' => $localClient->id,
            'user_id' => $foreignWorker->id,
        ]);

        $foreignWorkerOnLocalSite = $this->makeSession($foreignWorker, [
            'site_id' => $localSite->id,
        ]);
        $localWorkerOnForeignWorkersShift = $this->makeSession($localWorker, [
            'site_id' => $localSite->id,
            'shift_id' => $mismatchedShift->id,
        ]);

        $this->actingAs($lead)
            ->get('/health-safety/lone-workers?period=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sessions.data', fn ($sessions) => collect($sessions)->isEmpty()));

        foreach ([$foreignWorkerOnLocalSite, $localWorkerOnForeignWorkersShift] as $hiddenSession) {
            $this->actingAs($lead)
                ->get('/health-safety/lone-workers?period=all&session='.$hiddenSession->id)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('detail', null));

            $this->actingAs($lead)
                ->post("/health-safety/lone-workers/sessions/{$hiddenSession->id}/end")
                ->assertForbidden();
            $this->actingAs($lead)
                ->post("/health-safety/lone-workers/sessions/{$hiddenSession->id}/locate")
                ->assertForbidden();

            $this->assertSame('active', $hiddenSession->fresh()->status);
            $this->assertNull($hiddenSession->fresh()->ended_at);
        }
    }

    private function tenantHsLead(int $organizationId, Site $primarySite): User
    {
        $user = User::factory()->create([
            'organization_id' => $organizationId,
            'role' => 'support_worker',
        ]);
        $permissionIds = Permission::query()
            ->whereIn('key', ['hazards.view', 'hazards.manage', 'healthSafety.viewAllSites'])
            ->pluck('id');
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn ($permissionId) => [$permissionId => ['allowed' => true]]),
        );
        HrEmployeeProfile::factory()->create([
            'tenant_id' => $organizationId,
            'user_id' => $user->id,
            'primary_site_id' => $primarySite->id,
            'secondary_site_ids' => [],
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }

    private function makeSession(User $worker, array $overrides = []): LoneWorkerSession
    {
        return LoneWorkerSession::query()->create(array_merge([
            'user_id' => $worker->id,
            'site_id' => null,
            'client_id' => null,
            'shift_id' => null,
            'started_at' => now()->subHour(),
            'expected_end_at' => now()->addHours(2),
            'last_check_in_at' => now()->subMinutes(10),
            'check_in_interval_minutes' => 30,
            'status' => 'active',
            'activity_description' => 'Tenant scope test',
            'created_by' => $worker->id,
            'updated_by' => $worker->id,
        ], $overrides));
    }
}
