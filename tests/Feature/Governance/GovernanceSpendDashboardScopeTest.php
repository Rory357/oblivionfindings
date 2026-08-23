<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\SpendApproval;
use App\Domain\Governance\Services\SpendApprovalCommandService;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceSpendDashboardScopeTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
        $this->siteA = Site::factory()->create(['name' => 'Dashboard Site A']);
        $this->siteB = Site::factory()->create(['name' => 'Dashboard Site B']);
    }

    public function test_dashboard_spend_surfaces_require_action_and_canonical_site_scope(): void
    {
        $pendingA = $this->governedSpend($this->siteA, SpendApproval::STATUS_DRAFT, '100.00', 'Site A pending secret');
        $pendingB = $this->governedSpend($this->siteB, SpendApproval::STATUS_DRAFT, '200.00', 'Site B pending secret');
        $approvedA = $this->governedSpend($this->siteA, SpendApproval::STATUS_APPROVED, '300.00', 'Site A approved secret');
        $approvedB = $this->governedSpend($this->siteB, SpendApproval::STATUS_APPROVED, '400.00', 'Site B approved secret');

        $governanceOnly = $this->actor($this->siteA, ['governance.view']);
        $this->assertNoSpendDisclosure(
            $this->dashboard($governanceOnly),
            [$pendingA, $pendingB, $approvedA, $approvedB],
        );

        $actionOnly = $this->actor($this->siteA, ['governance.spend.view']);
        $this->actingAs($actionOnly)
            ->getJson('/governance/dashboard/data?period=month')
            ->assertForbidden();

        $emptyScope = $this->actor(null, ['governance.view', 'governance.spend.view']);
        $this->assertNoSpendDisclosure(
            $this->dashboard($emptyScope),
            [$pendingA, $pendingB, $approvedA, $approvedB],
        );

        $siteAViewer = $this->actor($this->siteA, ['governance.view', 'governance.spend.view']);
        $siteAResponse = $this->dashboard($siteAViewer);
        $this->assertSame(1, $siteAResponse->json('widgets.financial.pending_spend_count'));
        $this->assertSame(100.0, $siteAResponse->json('widgets.financial.pending_spend_total'));
        $this->assertVisibleCompletedSpend($siteAResponse, $approvedA, [$approvedB]);

        $siteBViewer = $this->actor($this->siteB, ['governance.view', 'governance.spend.view']);
        $siteBResponse = $this->dashboard($siteBViewer);
        $this->assertSame(1, $siteBResponse->json('widgets.financial.pending_spend_count'));
        $this->assertSame(200.0, $siteBResponse->json('widgets.financial.pending_spend_total'));
        $this->assertVisibleCompletedSpend($siteBResponse, $approvedB, [$approvedA]);

        $global = $this->actor(null, [
            'governance.view',
            'governance.spend.view',
            'governance.spend.viewAllSites',
        ]);
        $globalResponse = $this->dashboard($global);
        $this->assertSame(2, $globalResponse->json('widgets.financial.pending_spend_count'));
        $this->assertSame(300.0, $globalResponse->json('widgets.financial.pending_spend_total'));
        $globalCompleted = collect($globalResponse->json('cockpit.recently_completed'))
            ->where('kind', 'spend_approved');
        $this->assertCount(2, $globalCompleted);
        $this->assertTrue($globalCompleted->contains('href', "/governance/spend-approvals/{$approvedA->id}"));
        $this->assertTrue($globalCompleted->contains('href', "/governance/spend-approvals/{$approvedB->id}"));
    }

    private function dashboard(User $actor): TestResponse
    {
        return $this->actingAs($actor)
            ->getJson('/governance/dashboard/data?period=month')
            ->assertOk();
    }

    /** @param array<int, SpendApproval> $approvals */
    private function assertNoSpendDisclosure(TestResponse $response, array $approvals): void
    {
        $financial = $response->json('widgets.financial');
        $this->assertIsArray($financial);
        $this->assertArrayNotHasKey('pending_spend_count', $financial);
        $this->assertArrayNotHasKey('pending_spend_total', $financial);
        $this->assertCount(
            0,
            collect($response->json('cockpit.recently_completed'))->where('kind', 'spend_approved'),
        );

        $body = $response->getContent();
        $this->assertStringNotContainsString('/governance/spend-approvals/', $body);
        foreach ($approvals as $approval) {
            $this->assertStringNotContainsString((string) $approval->description, $body);
        }
    }

    /** @param array<int, SpendApproval> $concealed */
    private function assertVisibleCompletedSpend(
        TestResponse $response,
        SpendApproval $visible,
        array $concealed,
    ): void {
        $completed = collect($response->json('cockpit.recently_completed'))
            ->where('kind', 'spend_approved');
        $this->assertCount(1, $completed);
        $this->assertSame(
            "/governance/spend-approvals/{$visible->id}",
            $completed->first()['href'],
        );
        foreach ($concealed as $approval) {
            $this->assertStringNotContainsString((string) $approval->description, $response->getContent());
            $this->assertStringNotContainsString(
                "/governance/spend-approvals/{$approval->id}",
                $response->getContent(),
            );
        }
    }

    /** @param array<int, string> $permissions */
    private function actor(?Site $site, array $permissions): User
    {
        $actor = User::factory()->create(['approved_at' => now()]);
        if ($site) {
            ensureCanonicalHrStaffProfile($actor, $site);
        }
        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        foreach ($permissionIds as $permissionId) {
            $actor->permissionOverrides()->syncWithoutDetaching([
                $permissionId => ['allowed' => true],
            ]);
        }

        return $actor;
    }

    private function governedSpend(
        Site $site,
        string $status,
        string $amount,
        string $description,
    ): SpendApproval {
        $requester = $this->actor($site, ['governance.spend.view', 'governance.spend.request']);
        $this->actingAs($requester);
        $service = app(SpendApprovalCommandService::class);
        $approval = $service->create($requester, [
            'title' => 'Dashboard governed spend '.Str::uuid(),
            'description' => $description,
            'category' => SpendApproval::CATEGORY_OPEX,
            'amount' => $amount,
            'currency' => 'NZD',
            'site_id' => $site->id,
        ]);
        if ($status === SpendApproval::STATUS_DRAFT) {
            return $approval;
        }

        $submitted = $service->submit($requester, $approval->id, $approval->version);
        $decider = $this->actor($site, ['governance.spend.view', 'governance.spend.approve']);
        $this->actingAs($decider);

        return $service->decide($decider, $submitted->id, SpendApproval::STATUS_APPROVED, [
            'decision_key' => (string) Str::uuid(),
            'expected_version' => $submitted->version,
            'expected_content_digest' => $submitted->content_digest,
            'decision_notes' => 'Independently approved dashboard evidence.',
        ]);
    }
}
