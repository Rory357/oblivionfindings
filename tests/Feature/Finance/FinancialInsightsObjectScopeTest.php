<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Services\FinancialInsightsScope;
use App\Domain\Finance\Services\FinancialInsightsScopeResolver;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;

function financialInsightsViewer(
    ?Site $site,
    array $allowed = ['finance.dashboard'],
    array $denied = [],
): User {
    $viewer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    foreach ($allowed as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'finance', 'module' => 'Finance'],
        );
        $viewer->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    foreach ($denied as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'finance', 'module' => 'Finance'],
        );
        $viewer->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => false],
        ]);
    }

    if ($site) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subMonth(),
            'end_date' => null,
        ]);
    }

    return $viewer;
}

function relateFinancialInsightsClient(Client $client, User $viewer): void
{
    $client->supportWorkers()->syncWithoutDetaching([$viewer->id]);
}

function financialInsightsAllocation(
    Site $site,
    string $date,
    float $amount,
    ?Client $client = null,
    string $eventType = 'payroll_cost',
): FinCostAllocation {
    $account = FinAccount::factory()->create(['type' => 'expense']);
    $journal = FinJournal::factory()->create([
        'journal_date' => $date,
        'status' => 'posted',
    ]);
    $line = FinJournalLine::query()->create([
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'description' => $eventType,
        'debit' => $amount,
        'credit' => 0,
    ]);

    return FinCostAllocation::query()->create([
        'journal_id' => $journal->id,
        'journal_line_id' => $line->id,
        'site_id' => $site->id,
        'client_id' => $client?->id,
        'amount' => $amount,
        'event_type' => $eventType,
        'event_date' => $date,
    ]);
}

function financialInsightsSiteRouteNames(): array
{
    return [
        'finance.api.sites.financial-summary',
        'finance.api.sites.budget',
        'finance.api.sites.variance',
        'finance.api.sites.variance.trend',
        'finance.api.sites.forecast',
    ];
}

function financialInsightsAggregateRouteNames(): array
{
    return [
        'finance.api.sites.overview',
        'finance.api.kpis',
        'finance.api.kpis.sites',
        'finance.api.kpis.clients',
        'finance.api.insights',
        'finance.api.budgets',
        'finance.api.variance',
        'finance.api.forecast',
    ];
}

afterEach(function (): void {
    Carbon::setTestNow();
    config()->set('finance.insight_thresholds.site_cost_increase_warning_pct', 15);
});

it('returns the explicit global accessible Site client relationship and denied decisions', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $otherSite = Site::factory()->create(['type' => 'house']);
    $inactiveSite = Site::factory()->create(['type' => 'house', 'is_active' => false]);
    $archivedSite = Site::factory()->create([
        'type' => 'house',
        'archived' => true,
        'archived_at' => now(),
    ]);
    $viewer = financialInsightsViewer($site);
    $assigned = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    $unassigned = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    $poisoned = Client::factory()->create(['site_id' => $otherSite->id, 'status' => 'active']);
    $siteLess = Client::factory()->create(['site_id' => null, 'status' => 'active']);
    relateFinancialInsightsClient($assigned, $viewer);
    relateFinancialInsightsClient($poisoned, $viewer);

    $resolver = app(FinancialInsightsScopeResolver::class);

    expect($resolver->resolveAggregate($viewer)->scope)->toBe(FinancialInsightsScope::AccessibleSite)
        ->and($resolver->resolveSite($viewer, $site->id)->scope)->toBe(FinancialInsightsScope::AccessibleSite)
        ->and($resolver->resolveClient($viewer, $assigned->id)->scope)->toBe(FinancialInsightsScope::ClientRelationship)
        ->and($resolver->resolveClient($viewer, $unassigned->id)->scope)->toBe(FinancialInsightsScope::Denied)
        ->and($resolver->resolveClient($viewer, $poisoned->id)->scope)->toBe(FinancialInsightsScope::Denied)
        ->and($resolver->resolveClient($viewer, $siteLess->id)->scope)->toBe(FinancialInsightsScope::Denied)
        ->and($resolver->resolveSite($viewer, $inactiveSite->id)->scope)->toBe(FinancialInsightsScope::Denied)
        ->and($resolver->resolveSite($viewer, $archivedSite->id)->scope)->toBe(FinancialInsightsScope::Denied)
        ->and($resolver->resolveClient($viewer, 999999)->scope)->toBe(FinancialInsightsScope::Denied);

    $global = financialInsightsViewer(null, [
        'finance.dashboard',
        FinancialInsightsScopeResolver::GLOBAL_PERMISSION,
    ]);

    expect($resolver->resolveAggregate($global)->scope)->toBe(FinancialInsightsScope::Global)
        ->and($resolver->resolveSite($global, $otherSite->id)->scope)->toBe(FinancialInsightsScope::Global)
        ->and($resolver->resolveClient($global, $poisoned->id)->scope)->toBe(FinancialInsightsScope::Global)
        ->and($resolver->resolveSite($global, $inactiveSite->id)->scope)->toBe(FinancialInsightsScope::Denied)
        ->and($resolver->resolveSite($global, $archivedSite->id)->scope)->toBe(FinancialInsightsScope::Denied);
});

it('allows every Site object endpoint only for the resolved active Site', function () {
    $site = Site::factory()->create(['type' => 'house', 'name' => 'Allowed Finance Site']);
    $otherSite = Site::factory()->create(['type' => 'house', 'name' => 'Hidden Finance Site']);
    $inactiveSite = Site::factory()->create(['type' => 'house', 'name' => 'Inactive Finance Site', 'is_active' => false]);
    $archivedSite = Site::factory()->create([
        'type' => 'house',
        'name' => 'Archived Finance Site',
        'archived' => true,
        'archived_at' => now(),
    ]);
    $deletedSite = Site::factory()->create(['type' => 'house', 'name' => 'Deleted Finance Site']);
    $deletedSite->delete();
    $viewer = financialInsightsViewer($site, ['finance.dashboard', 'reports.viewAny']);

    foreach (financialInsightsSiteRouteNames() as $routeName) {
        $this->actingAs($viewer)
            ->getJson(route($routeName, ['site' => $site->id]))
            ->assertOk();

        $missing = $this->actingAs($viewer)
            ->getJson(route($routeName, ['site' => 999999]));

        $missing->assertNotFound();
        foreach ([$otherSite, $inactiveSite, $archivedSite, $deletedSite] as $hiddenSite) {
            $hidden = $this->actingAs($viewer)
                ->getJson(route($routeName, ['site' => $hiddenSite->id]));

            $hidden->assertNotFound();
            expect($hidden->json('message'))->toBe($missing->json('message'));
            expect($hidden->getContent())->not->toContain($hiddenSite->name);
        }
    }

    $this->actingAs($viewer)
        ->getJson('/finance/api/sites/not-an-id/financial-summary')
        ->assertNotFound();
});

it('requires both the current Site and canonical client relationship for client details', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $otherSite = Site::factory()->create(['type' => 'house']);
    $viewer = financialInsightsViewer($site, ['finance.dashboard', 'reports.viewAny']);
    $allowed = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Allowed',
        'last_name' => 'Resident',
        'status' => 'active',
    ]);
    $unassigned = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Unassigned',
        'last_name' => 'Resident',
        'status' => 'active',
    ]);
    $wrongSite = Client::factory()->create([
        'site_id' => $otherSite->id,
        'first_name' => 'Wrongsite',
        'last_name' => 'Resident',
        'status' => 'active',
    ]);
    relateFinancialInsightsClient($allowed, $viewer);
    relateFinancialInsightsClient($wrongSite, $viewer);

    foreach (['finance.api.clients.financial-summary', 'finance.api.clients.ledger'] as $routeName) {
        $this->actingAs($viewer)
            ->getJson(route($routeName, ['client' => $allowed->id]))
            ->assertOk()
            ->assertJsonPath('client_id', $allowed->id);

        foreach ([$unassigned, $wrongSite] as $hiddenClient) {
            $hidden = $this->actingAs($viewer)
                ->getJson(route($routeName, ['client' => $hiddenClient->id]));
            $missing = $this->actingAs($viewer)
                ->getJson(route($routeName, ['client' => 999999]));

            $hidden->assertNotFound();
            $missing->assertNotFound();
            expect($hidden->json('message'))->toBe($missing->json('message'));
            expect($hidden->getContent())->not->toContain($hiddenClient->full_name);
        }
    }
});

it('conceals deleted Clients from scoped and global finance users', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $scoped = financialInsightsViewer($site);
    $global = financialInsightsViewer(null, [
        'finance.dashboard',
        FinancialInsightsScopeResolver::GLOBAL_PERMISSION,
    ]);
    $deleted = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'DeletedFinanceSentinel',
        'last_name' => 'Resident',
        'status' => 'active',
    ]);
    relateFinancialInsightsClient($deleted, $scoped);
    financialInsightsAllocation($site, '2026-04-10', 8765.43, $deleted);
    $deleted->delete();

    foreach ([$scoped, $global] as $viewer) {
        foreach (['finance.api.clients.financial-summary', 'finance.api.clients.ledger'] as $routeName) {
            $hidden = $this->actingAs($viewer)
                ->getJson(route($routeName, ['client' => $deleted->id]));
            $missing = $this->actingAs($viewer)
                ->getJson(route($routeName, ['client' => 999999]));

            $hidden->assertNotFound();
            $missing->assertNotFound();
            expect($hidden->json('message'))->toBe($missing->json('message'));
            expect($hidden->getContent())
                ->not->toContain('DeletedFinanceSentinel')
                ->and($hidden->getContent())->not->toContain('8765.43')
                ->and($hidden->getContent())->not->toContain('deleted_at');
        }

        $this->actingAs($viewer)
            ->get(route('finance.clients.financials', ['client' => $deleted->id]))
            ->assertNotFound();
    }

    $globalClientKpis = $this->actingAs($global)
        ->getJson(route('finance.api.kpis.clients', [
            'from' => '2026-04-01',
            'to' => '2026-04-30',
        ]))
        ->assertOk()
        ->assertJsonPath('highest_cost_client', null);
    expect($globalClientKpis->getContent())
        ->not->toContain('DeletedFinanceSentinel')
        ->not->toContain('8765.43');

    $this->actingAs($global)
        ->getJson(route('finance.api.sites.overview', [
            'from' => '2026-04-01',
            'to' => '2026-04-30',
        ]))
        ->assertJsonPath('sites.0.site_id', $site->id)
        ->assertJsonPath('sites.0.total_cost', '8765.43');
});

it('keeps all aggregate amounts names counts and insights inside the resolved scope', function () {
    Carbon::setTestNow('2026-05-15 10:00:00');
    config()->set('finance.insight_thresholds.site_cost_increase_warning_pct', 1);

    $visibleSite = Site::factory()->create(['type' => 'house', 'name' => 'Visible Aggregate Site']);
    $hiddenSite = Site::factory()->create(['type' => 'house', 'name' => 'Hidden Aggregate Site']);
    $viewer = financialInsightsViewer($visibleSite);
    $visibleClient = Client::factory()->create([
        'site_id' => $visibleSite->id,
        'first_name' => 'VisibleAggregate',
        'last_name' => 'Resident',
        'status' => 'active',
    ]);
    $unassignedClient = Client::factory()->create([
        'site_id' => $visibleSite->id,
        'first_name' => 'UnassignedAggregate',
        'last_name' => 'Resident',
        'status' => 'active',
    ]);
    $hiddenClient = Client::factory()->create([
        'site_id' => $hiddenSite->id,
        'first_name' => 'HiddenAggregate',
        'last_name' => 'Resident',
        'status' => 'active',
    ]);
    relateFinancialInsightsClient($visibleClient, $viewer);

    financialInsightsAllocation($visibleSite, '2026-03-10', 100, $visibleClient);
    financialInsightsAllocation($visibleSite, '2026-04-10', 400, $visibleClient);
    financialInsightsAllocation($hiddenSite, '2026-03-10', 900, $hiddenClient);
    financialInsightsAllocation($hiddenSite, '2026-04-10', 9900, $hiddenClient);

    foreach (financialInsightsAggregateRouteNames() as $routeName) {
        $parameters = match ($routeName) {
            'finance.api.sites.overview',
            'finance.api.kpis',
            'finance.api.kpis.sites',
            'finance.api.kpis.clients' => [
                'from' => '2026-04-01',
                'to' => '2026-04-30',
            ],
            'finance.api.budgets',
            'finance.api.variance' => [
                'from' => '2026-04',
                'to' => '2026-04',
            ],
            default => [],
        };
        $response = $this->actingAs($viewer)->get(route($routeName, $parameters));

        $response->assertOk();
        expect($response->getContent())
            ->not->toContain('Hidden Aggregate Site')
            ->not->toContain('HiddenAggregate Resident')
            ->not->toContain('UnassignedAggregate Resident')
            ->not->toContain('9900.00');
    }

    $this->actingAs($viewer)
        ->get(route('finance.api.sites.overview', ['from' => '2026-04-01', 'to' => '2026-04-30']))
        ->assertJsonCount(1, 'sites')
        ->assertJsonPath('sites.0.site_id', $visibleSite->id)
        ->assertJsonPath('sites.0.total_cost', '400.00');

    $this->actingAs($viewer)
        ->get(route('finance.api.kpis.clients', ['from' => '2026-04-01', 'to' => '2026-04-30']))
        ->assertJsonPath('client_count', 1)
        ->assertJsonPath('highest_cost_client.client_id', $visibleClient->id);

    $this->actingAs($viewer)
        ->get(route('finance.api.insights'))
        ->assertJsonFragment(['site_id' => $visibleSite->id])
        ->assertJsonMissing(['site_id' => $hiddenSite->id]);

    foreach (['finance.api.budgets', 'finance.api.variance'] as $routeName) {
        $this->actingAs($viewer)
            ->getJson(route($routeName, ['from' => '2026-04', 'to' => '2026-04']))
            ->assertJsonCount(1, 'sites')
            ->assertJsonPath('sites.0.site_id', $visibleSite->id)
            ->assertJsonPath('sites.0.actual', '400.00');
    }

    $this->actingAs($viewer)
        ->getJson(route('finance.api.forecast'))
        ->assertJsonCount(1, 'sites')
        ->assertJsonPath('sites.0.site_id', $visibleSite->id);
});

it('ignores caller supplied foreign dimensions and binds serializers to the resolved path object', function () {
    $site = Site::factory()->create(['type' => 'house', 'name' => 'Resolved Path Site']);
    $foreignSite = Site::factory()->create(['type' => 'house', 'name' => 'Injected Foreign Site']);
    $viewer = financialInsightsViewer($site);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'ResolvedPath',
        'last_name' => 'Client',
        'status' => 'active',
    ]);
    $foreignClient = Client::factory()->create([
        'site_id' => $foreignSite->id,
        'first_name' => 'InjectedForeign',
        'last_name' => 'Client',
        'status' => 'active',
    ]);
    relateFinancialInsightsClient($client, $viewer);

    $siteResponse = $this->actingAs($viewer)
        ->get(route('finance.api.sites.financial-summary', ['site' => $site->id])
            .'?site_id='.$foreignSite->id.'&client_id='.$foreignClient->id)
        ->assertOk()
        ->assertJsonPath('site_id', $site->id)
        ->assertJsonMissing(['site_id' => $foreignSite->id])
        ->assertJsonMissingPath('tenant_id')
        ->assertJsonMissingPath('organization_id');

    $summaryResponse = $this->actingAs($viewer)
        ->get(route('finance.api.clients.financial-summary', ['client' => $client->id])
            .'?site_id='.$foreignSite->id.'&client_id='.$foreignClient->id)
        ->assertOk()
        ->assertJsonPath('client_id', $client->id)
        ->assertJsonMissing(['client_id' => $foreignClient->id])
        ->assertJsonMissingPath('deleted_at')
        ->assertJsonMissingPath('nhi_number');

    $ledgerResponse = $this->actingAs($viewer)
        ->get(route('finance.api.clients.ledger', ['client' => $client->id])
            .'?site_id='.$foreignSite->id.'&client_id='.$foreignClient->id)
        ->assertOk()
        ->assertJsonPath('client_id', $client->id)
        ->assertJsonMissing(['client_id' => $foreignClient->id]);

    foreach ([$siteResponse, $summaryResponse, $ledgerResponse] as $response) {
        expect($response->getContent())
            ->not->toContain('Injected Foreign Site')
            ->not->toContain('InjectedForeign Client')
            ->not->toContain('"tenant_id"')
            ->not->toContain('"organization_id"')
            ->not->toContain('"deleted_at"')
            ->not->toContain('"nhi_number"');
    }
});

it('keeps matching rendered Financial Insights views on the resolved object scope', function () {
    $site = Site::factory()->create(['type' => 'house', 'name' => 'Rendered Visible Site']);
    $foreignSite = Site::factory()->create(['type' => 'house', 'name' => 'Rendered Hidden Site']);
    $viewer = financialInsightsViewer($site);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'RenderedVisible',
        'last_name' => 'Client',
        'status' => 'active',
    ]);
    $unassigned = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'RenderedUnassigned',
        'last_name' => 'Client',
        'status' => 'active',
    ]);
    relateFinancialInsightsClient($client, $viewer);

    $this->actingAs($viewer)
        ->get(route('finance.sites.financial-dashboard', ['site' => $site->id]))
        ->assertOk();
    $this->actingAs($viewer)
        ->get(route('finance.sites.financial-dashboard', ['site' => $foreignSite->id]))
        ->assertNotFound();
    $this->actingAs($viewer)
        ->get(route('finance.clients.financials', ['client' => $client->id]))
        ->assertOk();
    $this->actingAs($viewer)
        ->get(route('finance.clients.financials', ['client' => $unassigned->id]))
        ->assertNotFound();

    foreach (['finance.sites.overview', 'finance.executive-dashboard'] as $routeName) {
        $response = $this->actingAs($viewer)->get(route($routeName));
        $response->assertOk();
        expect($response->getContent())
            ->toContain('Rendered Visible Site')
            ->not->toContain('Rendered Hidden Site')
            ->not->toContain('RenderedUnassigned Client');
    }
});

it('makes global access separately permissioned and still requires the dashboard capability', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $otherSite = Site::factory()->create(['type' => 'house']);
    $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    $otherClient = Client::factory()->create(['site_id' => $otherSite->id, 'status' => 'active']);
    $global = financialInsightsViewer(null, [
        'finance.dashboard',
        FinancialInsightsScopeResolver::GLOBAL_PERMISSION,
    ]);
    $globalWithoutDashboard = financialInsightsViewer(null, [
        FinancialInsightsScopeResolver::GLOBAL_PERMISSION,
    ]);

    $this->actingAs($global)
        ->get(route('finance.api.sites.financial-summary', ['site' => $site->id]))
        ->assertOk();
    $this->actingAs($global)
        ->get(route('finance.api.clients.financial-summary', ['client' => $client->id]))
        ->assertOk();
    $this->actingAs($global)
        ->getJson(route('finance.api.sites.overview'))
        ->assertJsonCount(2, 'sites');
    $this->actingAs($global)
        ->getJson(route('finance.api.kpis.clients'))
        ->assertJsonPath('client_count', 2)
        ->assertJsonFragment(['client_id' => $client->id])
        ->assertJsonFragment(['client_id' => $otherClient->id]);
    $this->actingAs($globalWithoutDashboard)
        ->get(route('finance.api.sites.financial-summary', ['site' => $site->id]))
        ->assertForbidden();
    expect(app(FinancialInsightsScopeResolver::class)->resolveAggregate($globalWithoutDashboard)->scope)
        ->toBe(FinancialInsightsScope::Denied);

    $siteOnly = financialInsightsViewer($site);
    $siteOnly->hrEmployeeProfile()->delete();
    $this->actingAs($siteOnly)
        ->get(route('finance.api.kpis'))
        ->assertForbidden();
});

it('does not infer global scope from an admin role when the explicit permission is denied', function () {
    $this->seed(RbacSeeder::class);
    $ownSite = Site::factory()->create(['type' => 'house']);
    $foreignSite = Site::factory()->create(['type' => 'house']);
    $admin = financialInsightsViewer($ownSite, [], [FinancialInsightsScopeResolver::GLOBAL_PERMISSION]);
    $admin->forceFill(['role' => 'admin'])->save();
    $admin->roles()->sync([Role::query()->where('name', 'admin')->firstOrFail()->id]);

    $this->actingAs($admin)
        ->get(route('finance.api.sites.financial-summary', ['site' => $ownSite->id]))
        ->assertOk();
    $this->actingAs($admin)
        ->get(route('finance.api.sites.financial-summary', ['site' => $foreignSite->id]))
        ->assertNotFound();
});

it('seeds the global Financial Insights exception onto explicit finance governance roles', function () {
    $this->seed(RbacSeeder::class);

    foreach (['admin', 'finance', 'auditor'] as $roleName) {
        expect(Role::query()
            ->where('name', $roleName)
            ->whereHas('permissions', fn ($permissions) => $permissions
                ->where('key', FinancialInsightsScopeResolver::GLOBAL_PERMISSION))
            ->exists())->toBeTrue();
    }
});
