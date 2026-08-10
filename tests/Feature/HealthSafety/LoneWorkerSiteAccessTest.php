<?php

declare(strict_types=1);

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoneWorkerSiteAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_current_staff_viewer_sees_exact_sessions_at_their_assigned_site(): void
    {
        $localSite = Site::factory()->create(['name' => 'Local Site']);
        $outsideSite = Site::factory()->create(['name' => 'Outside Site']);
        $viewer = $this->siteUser($localSite, ['hazards.view', 'hazards.manage']);
        $localWorker = $this->siteUser($localSite);
        $outsideWorker = $this->siteUser($outsideSite);
        $localClient = Client::factory()->create([
            'site_id' => $localSite->id,
            'status' => 'active',
        ]);
        $outsideClient = Client::factory()->create([
            'site_id' => $outsideSite->id,
            'status' => 'active',
        ]);
        $localShift = Shift::factory()->create([
            'site_id' => $localSite->id,
            'client_id' => $localClient->id,
            'user_id' => $localWorker->id,
        ]);

        $visibleDirect = $this->makeSession($localWorker, ['site_id' => $localSite->id]);
        $visibleClientFallback = $this->makeSession($localWorker, [
            'client_id' => $localClient->id,
        ]);
        $visibleShiftFallback = $this->makeSession($localWorker, [
            'client_id' => $localClient->id,
            'shift_id' => $localShift->id,
        ]);
        $outside = $this->makeSession($outsideWorker, ['site_id' => $outsideSite->id]);

        $this->makeSession($localWorker);
        $this->makeSession($localWorker, [
            'site_id' => $localSite->id,
            'client_id' => $outsideClient->id,
        ]);
        $this->makeSession($outsideWorker, ['site_id' => $localSite->id]);

        $expectedIds = collect([
            $visibleDirect->id,
            $visibleClientFallback->id,
            $visibleShiftFallback->id,
        ])->sort()->values()->all();

        $this->actingAs($viewer)
            ->get('/health-safety/lone-workers?period=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sessions.data', fn ($sessions) => collect($sessions)
                    ->pluck('id')->sort()->values()->all() === $expectedIds)
                ->where('tabCounts.sessions', 3)
                ->where('hero.clusters.live.active', 3)
                ->where('options.sites', fn ($sites) => collect($sites)->pluck('id')->all() === [
                    $localSite->id,
                ]));

        $this->actingAs($viewer)
            ->post("/health-safety/lone-workers/sessions/{$outside->id}/end")
            ->assertForbidden();

        $this->assertSame('active', $outside->fresh()->status);
        $this->assertNull($outside->fresh()->ended_at);
    }

    public function test_explicit_all_sites_viewer_sees_every_valid_attributable_active_site(): void
    {
        $firstSite = Site::factory()->create(['name' => 'First Site']);
        $secondSite = Site::factory()->create(['name' => 'Second Site']);
        $thirdSite = Site::factory()->create(['name' => 'Third Site']);
        $inactiveSite = Site::factory()->create([
            'name' => 'Inactive Site',
            'is_active' => false,
        ]);
        $viewer = $this->siteUser($firstSite, [
            'hazards.view',
            'hazards.manage',
            'healthSafety.viewAllSites',
        ]);
        $firstWorker = $this->siteUser($firstSite);
        $secondWorker = $this->siteUser($secondSite);
        $thirdWorker = $this->siteUser($thirdSite);
        $inactiveWorker = $this->siteUser($inactiveSite);
        $secondClient = Client::factory()->create([
            'site_id' => $secondSite->id,
            'status' => 'active',
        ]);
        $thirdClient = Client::factory()->create([
            'site_id' => $thirdSite->id,
            'status' => 'active',
        ]);
        $thirdShift = Shift::factory()->create([
            'site_id' => $thirdSite->id,
            'client_id' => $thirdClient->id,
            'user_id' => $thirdWorker->id,
        ]);

        $first = $this->makeSession($firstWorker, ['site_id' => $firstSite->id]);
        $second = $this->makeSession($secondWorker, ['client_id' => $secondClient->id]);
        $third = $this->makeSession($thirdWorker, [
            'client_id' => $thirdClient->id,
            'shift_id' => $thirdShift->id,
        ]);
        $this->makeSession($inactiveWorker, ['site_id' => $inactiveSite->id]);
        $this->makeSession($firstWorker);

        $expectedIds = collect([$first->id, $second->id, $third->id])->sort()->values()->all();
        $expectedSiteIds = collect([$firstSite->id, $secondSite->id, $thirdSite->id])
            ->sort()->values()->all();

        $this->actingAs($viewer)
            ->get('/health-safety/lone-workers?period=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sessions.data', fn ($sessions) => collect($sessions)
                    ->pluck('id')->sort()->values()->all() === $expectedIds)
                ->where('tabCounts.sessions', 3)
                ->where('hero.clusters.live.active', 3)
                ->where('options.sites', fn ($sites) => collect($sites)
                    ->pluck('id')->sort()->values()->all() === $expectedSiteIds));

        $this->actingAs($viewer)
            ->get('/health-safety/lone-workers?period=all&site_id='.$thirdSite->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sessions.data', fn ($sessions) => collect($sessions)
                    ->pluck('id')->values()->all() === [$third->id]));
    }

    public function test_direct_actions_preserve_site_access_integrity_and_response_contracts(): void
    {
        $localSite = Site::factory()->create();
        $outsideSite = Site::factory()->create();
        $localViewer = $this->siteUser($localSite, ['hazards.view', 'hazards.manage']);
        $allSitesViewer = $this->siteUser($localSite, [
            'hazards.view',
            'hazards.manage',
            'healthSafety.viewAllSites',
        ]);
        $localWorker = $this->siteUser($localSite);
        $outsideWorker = $this->siteUser($outsideSite);
        $outsideClient = Client::factory()->create(['site_id' => $outsideSite->id]);

        $validOutside = $this->makeSession($outsideWorker, ['site_id' => $outsideSite->id]);
        $unattributed = $this->makeSession($localWorker);
        $conflicting = $this->makeSession($localWorker, [
            'site_id' => $localSite->id,
            'client_id' => $outsideClient->id,
        ]);
        $workerSiteMismatch = $this->makeSession($localWorker, ['site_id' => $outsideSite->id]);

        $this->actingAs($allSitesViewer)
            ->post("/health-safety/lone-workers/sessions/{$validOutside->id}/locate")
            ->assertRedirect()
            ->assertSessionHas('error', 'This worker does not have a paired GPS tracker.');
        $this->assertSame('active', $validOutside->fresh()->status);

        $this->actingAs($localViewer)
            ->post("/health-safety/lone-workers/sessions/{$validOutside->id}/end")
            ->assertForbidden();
        $this->assertSame('active', $validOutside->fresh()->status);

        foreach ([$unattributed, $conflicting, $workerSiteMismatch] as $unsafeSession) {
            $this->actingAs($allSitesViewer)
                ->post("/health-safety/lone-workers/sessions/{$unsafeSession->id}/end")
                ->assertForbidden();
            $this->actingAs($allSitesViewer)
                ->post("/health-safety/lone-workers/sessions/{$unsafeSession->id}/locate")
                ->assertForbidden();

            $this->assertSame('active', $unsafeSession->fresh()->status);
            $this->assertNull($unsafeSession->fresh()->ended_at);
        }

        $this->actingAs($allSitesViewer)
            ->post("/health-safety/lone-workers/sessions/{$validOutside->id}/end")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('completed', $validOutside->fresh()->status);
        $this->assertNotNull($validOutside->fresh()->ended_at);
    }

    public function test_noncurrent_worker_and_shift_worker_conflicts_are_hidden_and_immutable(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteUser($site, [
            'hazards.view',
            'hazards.manage',
            'healthSafety.viewAllSites',
        ]);
        $currentWorker = $this->siteUser($site);
        $otherWorker = $this->siteUser($site);
        $workerWithoutProfile = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
        $expiredWorker = $this->siteUser($site);
        $expiredWorker->hrEmployeeProfile()->update([
            'end_date' => today()->subDay(),
        ]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $mismatchedShift = Shift::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => $otherWorker->id,
        ]);

        $missingProfile = $this->makeSession($workerWithoutProfile, ['site_id' => $site->id]);
        $expiredProfile = $this->makeSession($expiredWorker, ['site_id' => $site->id]);
        $shiftWorkerConflict = $this->makeSession($currentWorker, [
            'site_id' => $site->id,
            'client_id' => $client->id,
            'shift_id' => $mismatchedShift->id,
        ]);

        $this->actingAs($viewer)
            ->get('/health-safety/lone-workers?period=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sessions.data', fn ($sessions) => collect($sessions)->isEmpty())
                ->where('tabCounts.sessions', 0)
                ->where('hero.clusters.live.active', 0));

        foreach ([$missingProfile, $expiredProfile, $shiftWorkerConflict] as $unsafeSession) {
            $this->actingAs($viewer)
                ->get('/health-safety/lone-workers?period=all&session='.$unsafeSession->id)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('detail', null));
            $this->actingAs($viewer)
                ->post("/health-safety/lone-workers/sessions/{$unsafeSession->id}/end")
                ->assertForbidden();

            $this->assertSame('active', $unsafeSession->fresh()->status);
            $this->assertNull($unsafeSession->fresh()->ended_at);
        }
    }

    /** @param array<int, string> $permissionKeys */
    private function siteUser(Site $primarySite, array $permissionKeys = []): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);

        if ($permissionKeys !== []) {
            $permissionIds = Permission::query()
                ->whereIn('key', $permissionKeys)
                ->pluck('id');
            $user->permissionOverrides()->sync(
                $permissionIds->mapWithKeys(
                    fn ($permissionId) => [$permissionId => ['allowed' => true]],
                ),
            );
        }

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $primarySite->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
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
            'activity_description' => 'Site access test',
            'created_by' => $worker->id,
            'updated_by' => $worker->id,
        ], $overrides));
    }
}
