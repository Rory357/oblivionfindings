<?php

use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Services\FinanceHubCountsService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Finance hub tab count badges (C3d). Every finance page shares `financeHubCounts`
 * (hub → tab id → row count) so each *TabsFooter renders a count beside its tabs.
 * The prop is finance-route-scoped and lazy; the service counts every hub's lists,
 * guarding each count so one bad table never 500s the finance chrome.
 */
function countsUser(string $permissionKey = 'finance.ar.view'): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $permission = Permission::firstOrCreate(['key' => $permissionKey], ['description' => $permissionKey]);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    return $user;
}

it('shares finance hub counts on a finance page, keyed by hub and tab', function () {
    FinInvoice::factory()->count(5)->create(['status' => 'sent']);

    $this->actingAs(countsUser())
        ->get(route('finance.invoices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('financeHubCounts.receivables.invoices', 5)
            ->has('financeHubCounts.payables')
            ->has('financeHubCounts.banking')
            ->has('financeHubCounts.ledger')
            ->has('financeHubCounts.tax'));
});

it('counts the one application finance list', function () {
    FinInvoice::factory()->count(5)->create(['status' => 'sent']);

    $counts = (new FinanceHubCountsService)->forApplication();

    expect($counts['receivables']['invoices'])->toBe(5)
        ->and($counts)->toHaveKeys(['receivables', 'payables', 'banking', 'ledger', 'tax']);
});

it('shares the open job count for the current employee Sites', function () {
    $user = countsUser();
    $jobBoardPermission = Permission::firstOrCreate(
        ['key' => 'job_board.viewAny'],
        ['description' => 'job_board.viewAny'],
    );
    $user->permissionOverrides()->syncWithoutDetaching([
        $jobBoardPermission->id => ['allowed' => true],
    ]);

    $assignedSite = Site::factory()->create(['is_active' => true]);
    $otherSite = Site::factory()->create(['is_active' => true]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $assignedSite->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => today()->subDay(),
        'end_date' => null,
    ]);

    $createOpenPosition = function (Site $site): void {
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $shift = Shift::factory()->unassigned()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);
        ShiftOpenPosition::query()->create([
            'shift_id' => $shift->id,
            'status' => 'open',
            'expires_at' => now()->addDay(),
        ]);
    };

    $createOpenPosition($assignedSite);
    $createOpenPosition($assignedSite);
    $createOpenPosition($otherSite);

    $this->actingAs($user)
        ->get(route('finance.invoices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.job_board.open_count', 2));
});
