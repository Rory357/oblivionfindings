<?php

namespace Tests\Feature\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\SafeguardingActionPlan;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingRiskAssessment;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S44 (23 Oct 2026): the safeguarding register listed, counted and tabbed every
 * concern, while opening one applied the policy's Site rule. Every register
 * number and row now follows SafeguardingConcernPolicy::applyVisibleScope:
 * the reader's Sites plus Site-less organisation records, or everything with
 * the reports bypass.
 */
class SafeguardingRegisterSiteScopeTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    private Client $clientA;

    private Client $clientB;

    private SafeguardingConcern $concernA;

    private SafeguardingConcern $concernB;

    private SafeguardingConcern $concernOrg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->siteA = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->siteB = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->clientA = Client::factory()->create(['site_id' => $this->siteA->id]);
        $this->clientB = Client::factory()->create(['site_id' => $this->siteB->id]);

        $this->concernA = SafeguardingConcern::factory()->create([
            'site_id' => $this->siteA->id,
            'status' => 'reported',
            'severity' => 'medium',
            'is_sensitive' => false,
            'requires_external_referral' => true,
            'reported_at' => now()->subHour(),
        ]);
        $this->concernOrg = SafeguardingConcern::factory()->create([
            'site_id' => null,
            'status' => 'investigating',
            'severity' => 'low',
            'is_sensitive' => false,
            'requires_external_referral' => false,
            'reported_at' => now()->subHours(2),
        ]);
        // The other Site's concern is the newest and the most urgent, so any leak shows first.
        $this->concernB = SafeguardingConcern::factory()->create([
            'site_id' => $this->siteB->id,
            'status' => 'reported',
            'severity' => 'critical',
            'is_sensitive' => false,
            'requires_external_referral' => true,
            'reported_at' => now(),
        ]);

        $author = User::factory()->create(['approved_at' => now()]);
        SafeguardingRiskAssessment::query()->create([
            'safeguarding_concern_id' => $this->concernB->id,
            'assessor_id' => $author->id,
            'assessed_at' => now()->subDays(10),
            'risk_factors' => ['Unsupervised visits'],
            'protective_factors' => ['Key worker on site'],
            'risk_to_self' => 'medium',
            'risk_to_others' => 'low',
            'risk_from_others' => 'high',
            'overall_risk_level' => 'high',
            'capacity_assessed' => false,
            'next_review_date' => now()->subDay(),
            'created_by' => $author->id,
        ]);
        SafeguardingActionPlan::query()->create([
            'safeguarding_concern_id' => $this->concernB->id,
            'action_description' => 'Other Site protective action',
            'action_type' => 'protective_measure',
            'assigned_to_user_id' => $author->id,
            'due_date' => now()->subDay(),
            'status' => 'pending',
            'priority' => 2,
            'created_by' => $author->id,
        ]);
    }

    /** @param  array<int, string>  $permissionKeys */
    private function reader(array $permissionKeys, ?Site $site): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['description' => str_replace('.', ' ', $key), 'group' => explode('.', $key)[0]],
            );
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        if ($site) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $user->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'start_date' => now()->subYear(),
                'is_active' => true,
            ]);
        }

        return $user;
    }

    public function test_site_reader_sees_only_their_site_and_organisation_concerns_in_every_number(): void
    {
        $reader = $this->reader(['safeguarding.viewAny'], $this->siteA);

        $this->actingAs($reader)
            ->get('/safeguarding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 2)
                ->where('rows.data.0.id', $this->concernA->id)
                ->where('rows.data.1.id', $this->concernOrg->id)
                ->where('tabCounts.all', 2)
                ->where('tabCounts.triage', 1)
                ->where('tabCounts.reviews', 0)
                ->where('hero.openWork.open.value', 2)
                ->where('hero.openWork.awaitingTriage.value', 1)
                ->where('hero.attention.criticalOpen.value', 0)
                ->where('hero.attention.reviewsDue.value', 0)
                ->where('hero.attention.overdueActions.value', 0)
                ->where('referralOverdueCount', 1)
                ->has('sites', 1)
                ->where('sites.0.id', $this->siteA->id)
                ->has('subjects', 1)
                ->where('subjects.0.id', $this->clientA->id)
            );
    }

    public function test_site_reader_cannot_reach_another_sites_concern_by_tab_or_filter(): void
    {
        $reader = $this->reader(['safeguarding.viewAny'], $this->siteA);

        $this->actingAs($reader)
            ->get('/safeguarding?tab=reviews')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rowsKind', 'reviews')->has('rows.data', 0));

        $this->actingAs($reader)
            ->get('/safeguarding?site_id='.$this->siteB->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows.data', 0)->where('tabCounts.all', 0));

        $this->actingAs($reader)
            ->get('/safeguarding?tab=all&q='.urlencode((string) $this->concernB->reference_number))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rows.data', 0));
    }

    public function test_reader_without_site_assignments_sees_only_organisation_concerns(): void
    {
        // Matches SafeguardingConcernPolicy::view(): no current Site, no Site-owned concern.
        $reader = $this->reader(['safeguarding.viewAny'], null);

        $this->actingAs($reader)
            ->get('/safeguarding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.id', $this->concernOrg->id)
                ->where('tabCounts.all', 1)
                ->where('hero.openWork.open.value', 1)
                ->where('referralOverdueCount', 0)
                ->has('sites', 0)
                ->has('subjects', 0)
            );
    }

    public function test_reports_bypass_reader_still_sees_every_site(): void
    {
        $reader = $this->reader(['safeguarding.viewAny', 'reports.viewAny'], null);

        $this->actingAs($reader)
            ->get('/safeguarding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 3)
                ->where('rows.data.0.id', $this->concernB->id)
                ->where('tabCounts.all', 3)
                ->where('tabCounts.reviews', 1)
                ->where('hero.openWork.open.value', 3)
                ->where('hero.attention.criticalOpen.value', 1)
                ->where('hero.attention.reviewsDue.value', 1)
                ->where('hero.attention.overdueActions.value', 1)
                ->where('referralOverdueCount', 2)
                ->where('sites', fn ($sites) => collect($sites)->pluck('id')->intersect([$this->siteA->id, $this->siteB->id])->count() === 2)
                ->where('subjects', fn ($subjects) => collect($subjects)->pluck('id')->intersect([$this->clientA->id, $this->clientB->id])->count() === 2)
            );
    }
}
