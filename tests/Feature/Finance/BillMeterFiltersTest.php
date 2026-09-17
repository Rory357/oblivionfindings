<?php

use App\Domain\Finance\Models\FinBill;
use App\Models\Permission;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Every header meter block links to the list its number came from
 * (design_styles/PAGE_HEADER_STYLE_GUIDE.md). The bills index counted unpaid,
 * overdue and due-this-week correctly over the whole register, but had no
 * filters to match: all three meters linked to `?status=approved`, so clicking
 * any of them showed the same rows and none of them agreed with the number
 * above it. These are the filters behind those links.
 */
function billMeterViewer(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $permission = Permission::firstOrCreate(
        ['key' => 'finance.ap.view'],
        ['description' => 'finance.ap.view'],
    );
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    return $user;
}

function seedBillMeterFixtures(): array
{
    return [
        'overdue' => FinBill::factory()->create([
            'organization_id' => 1, 'status' => 'approved',
            'total_amount' => '500.00', 'amount_paid' => '0.00',
            'due_date' => now()->subDays(3)->toDateString(),
        ]),
        'dueThisWeek' => FinBill::factory()->create([
            'organization_id' => 1, 'status' => 'partially_paid',
            'total_amount' => '400.00', 'amount_paid' => '100.00',
            'due_date' => now()->addDays(2)->toDateString(),
        ]),
        'dueLater' => FinBill::factory()->create([
            'organization_id' => 1, 'status' => 'approved',
            'total_amount' => '900.00', 'amount_paid' => '0.00',
            'due_date' => now()->addDays(40)->toDateString(),
        ]),
        'draft' => FinBill::factory()->create([
            'organization_id' => 1, 'status' => 'draft',
            'total_amount' => '100.00', 'amount_paid' => '0.00',
            'due_date' => now()->addDays(10)->toDateString(),
        ]),
        'awaiting' => FinBill::factory()->create([
            'organization_id' => 1, 'status' => 'awaiting_approval',
            'total_amount' => '200.00', 'amount_paid' => '0.00',
            'due_date' => now()->addDays(10)->toDateString(),
        ]),
        'paid' => FinBill::factory()->create([
            'organization_id' => 1, 'status' => 'paid',
            'total_amount' => '300.00', 'amount_paid' => '300.00',
            'due_date' => now()->subDays(9)->toDateString(),
        ]),
    ];
}

/** @return string[] bill numbers in the rendered list */
function billNumbersFrom(Assert $page): array
{
    return collect($page->toArray()['props']['bills']['data'])
        ->pluck('bill_number')
        ->sort()
        ->values()
        ->all();
}

it('filters to unpaid bills — approved and owing, the Unpaid meter', function () {
    $bills = seedBillMeterFixtures();

    $this->actingAs(billMeterViewer())
        ->get(route('finance.bills.index', ['status' => 'unpaid']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => expect(billNumbersFrom($page))->toBe(
            collect([$bills['overdue'], $bills['dueThisWeek'], $bills['dueLater']])
                ->pluck('bill_number')->sort()->values()->all(),
        ));
});

it('filters to overdue bills — the Overdue meter', function () {
    $bills = seedBillMeterFixtures();

    $this->actingAs(billMeterViewer())
        ->get(route('finance.bills.index', ['due' => 'overdue']))
        ->assertOk()
        // The paid bill is also past its due date; overdue means still owing.
        ->assertInertia(fn (Assert $page) => expect(billNumbersFrom($page))
            ->toBe([$bills['overdue']->bill_number]));
});

it('filters to bills due in the next seven days — the Due-this-week meter', function () {
    $bills = seedBillMeterFixtures();

    $this->actingAs(billMeterViewer())
        ->get(route('finance.bills.index', ['due' => 'week']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => expect(billNumbersFrom($page))
            ->toBe([$bills['dueThisWeek']->bill_number]));
});

it('filters to bills awaiting approval — drafts included, as the meter counts them', function () {
    $bills = seedBillMeterFixtures();

    $this->actingAs(billMeterViewer())
        ->get(route('finance.bills.index', ['status' => 'awaiting']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => expect(billNumbersFrom($page))->toBe(
            collect([$bills['draft'], $bills['awaiting']])
                ->pluck('bill_number')->sort()->values()->all(),
        ));
});

it('keeps each meter link agreeing with the number printed above it', function () {
    seedBillMeterFixtures();
    $user = billMeterViewer();

    $summary = null;
    $this->actingAs($user)
        ->get(route('finance.bills.index'))
        ->assertInertia(function (Assert $page) use (&$summary) {
            $summary = $page->toArray()['props']['summary'];
        });

    foreach ([
        ['query' => ['status' => 'unpaid'], 'count' => 'unpaid_count'],
        ['query' => ['due' => 'overdue'], 'count' => 'overdue_count'],
        ['query' => ['due' => 'week'], 'count' => 'due_this_week_count'],
        ['query' => ['status' => 'awaiting'], 'count' => 'awaiting_count'],
    ] as $case) {
        $this->actingAs($user)
            ->get(route('finance.bills.index', $case['query']))
            ->assertInertia(fn (Assert $page) => expect(
                $page->toArray()['props']['bills']['total'],
            )->toBe((int) $summary[$case['count']]));
    }
});

it('still honours a literal status filter', function () {
    $bills = seedBillMeterFixtures();

    $this->actingAs(billMeterViewer())
        ->get(route('finance.bills.index', ['status' => 'draft']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => expect(billNumbersFrom($page))
            ->toBe([$bills['draft']->bill_number]));
});
