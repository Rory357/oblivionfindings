<?php

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Services\Calendar\FinanceCalendarAggregator;
use App\Domain\Finance\Services\Calendar\Providers\GstReturnProvider;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The finance calendar aggregates read-only money obligations (AR invoice due
 * dates, AP bill due dates, scheduled payment runs, GST filing deadlines) into
 * one feed of normalised items, each deep-linking back to its ledger record.
 */
afterEach(fn () => Carbon::setTestNow());

it('aggregates invoice, bill, payment-run and GST obligations in range, sorted by date', function () {
    Carbon::setTestNow('2026-06-10');
    $start = Carbon::parse('2026-06-01');
    $end = Carbon::parse('2026-06-30');

    FinInvoice::factory()->create([
        'organization_id' => 1, 'due_date' => '2026-06-15', 'status' => 'sent',
        'total_amount' => 1150, 'client_name' => 'Acme Trust', 'invoice_number' => 'INV-00001',
    ]);
    FinBill::factory()->create([
        'organization_id' => 1, 'due_date' => '2026-06-20', 'status' => 'approved',
        'total_amount' => 500, 'amount_paid' => 0, 'bill_number' => 'BILL-202606-001',
    ]);
    FinPaymentRun::factory()->create([
        'organization_id' => 1, 'payment_date' => '2026-06-25', 'status' => 'approved',
        'total_amount' => 2000, 'run_number' => 'PAY-0001',
    ]);
    FinGstReturn::factory()->create([
        'organization_id' => 1, 'period_start' => '2026-04-01', 'period_end' => '2026-05-31',
        'status' => 'draft', 'gst_payable' => 300,
    ]); // 2-monthly period ending 31 May → due 28 Jun

    $items = app(FinanceCalendarAggregator::class)->itemsForRange(1, $start, $end);

    expect($items)->toHaveCount(4);

    // Sorted ascending by start date.
    $dates = collect($items)->pluck('start')->all();
    expect($dates)->toBe(['2026-06-15', '2026-06-20', '2026-06-25', '2026-06-28']);

    $bySource = collect($items)->keyBy('source');
    expect($bySource['invoice_due']->direction)->toBe('inflow')
        ->and($bySource['invoice_due']->status)->toBe('due')
        ->and($bySource['bill_due']->direction)->toBe('outflow')
        ->and($bySource['payment_run']->status)->toBe('scheduled')
        ->and($bySource['gst_due']->start)->toBe('2026-06-28')
        ->and($bySource['gst_due']->direction)->toBe('outflow');
});

it('marks a past-due unpaid bill overdue and a paid invoice paid', function () {
    Carbon::setTestNow('2026-06-10');
    $start = Carbon::parse('2026-06-01');
    $end = Carbon::parse('2026-06-30');

    FinBill::factory()->create([
        'organization_id' => 1, 'due_date' => '2026-06-05', 'status' => 'approved',
        'total_amount' => 500, 'amount_paid' => 0, 'bill_number' => 'BILL-202606-009',
    ]);
    FinInvoice::factory()->create([
        'organization_id' => 1, 'due_date' => '2026-06-08', 'status' => 'paid',
        'total_amount' => 100, 'client_name' => 'Paid Co', 'invoice_number' => 'INV-00009',
    ]);

    $bySource = collect(app(FinanceCalendarAggregator::class)->itemsForRange(1, $start, $end))->keyBy('source');

    expect($bySource['bill_due']->status)->toBe('overdue')
        ->and($bySource['invoice_due']->status)->toBe('paid');
});

it('applies the NZ GST holiday concessions: Nov period due 15 Jan, Mar period due 7 May', function () {
    FinGstReturn::factory()->create([
        'organization_id' => 1, 'period_start' => '2026-10-01', 'period_end' => '2026-11-30',
        'status' => 'draft', 'gst_payable' => 200,
    ]);
    FinGstReturn::factory()->create([
        'organization_id' => 1, 'period_start' => '2026-02-01', 'period_end' => '2026-03-31',
        'status' => 'filed', 'gst_payable' => 0,
    ]);

    $provider = new GstReturnProvider;

    $nov = $provider->obligations(1, Carbon::parse('2027-01-01'), Carbon::parse('2027-01-31'));
    expect($nov)->toHaveCount(1)
        ->and($nov[0]->start)->toBe('2027-01-15');

    $mar = $provider->obligations(1, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
    expect($mar)->toHaveCount(1)
        ->and($mar[0]->start)->toBe('2026-05-07')
        ->and($mar[0]->status)->toBe('filed');
});

it('filters the feed by source', function () {
    Carbon::setTestNow('2026-06-10');
    $start = Carbon::parse('2026-06-01');
    $end = Carbon::parse('2026-06-30');

    FinInvoice::factory()->create([
        'organization_id' => 1, 'due_date' => '2026-06-15', 'status' => 'sent',
        'total_amount' => 1150, 'invoice_number' => 'INV-00021', 'client_name' => 'A',
    ]);
    FinBill::factory()->create([
        'organization_id' => 1, 'due_date' => '2026-06-20', 'status' => 'approved',
        'total_amount' => 500, 'amount_paid' => 0, 'bill_number' => 'BILL-202606-021',
    ]);

    $items = app(FinanceCalendarAggregator::class)
        ->itemsForRange(1, $start, $end, ['sources' => ['invoice_due']]);

    expect(collect($items)->pluck('source')->unique()->all())->toBe(['invoice_due']);
});

it('maps obligations to the shared CalendarItem shape with money detail in desc', function () {
    Carbon::setTestNow('2026-06-10');

    FinInvoice::factory()->create([
        'organization_id' => 1, 'due_date' => '2026-06-15', 'status' => 'sent',
        'total_amount' => 1234.5, 'client_name' => 'Acme Trust', 'invoice_number' => 'INV-00041',
    ]);
    FinBill::factory()->create([
        'organization_id' => 1, 'due_date' => '2026-06-05', 'status' => 'approved',
        'total_amount' => 500, 'amount_paid' => 0, 'bill_number' => 'BILL-202606-041',
    ]);

    $payload = app(FinanceCalendarAggregator::class)->itemsPayload(
        1,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
    );

    $bySource = collect($payload['events'])->keyBy('source');

    expect(array_keys($bySource->all()))->toBe(['bill-due', 'invoice-due'])
        ->and($bySource['invoice-due']['group'])->toBe('auto')
        ->and($bySource['invoice-due']['allDay'])->toBeTrue()
        ->and($bySource['invoice-due']['editable'])->toBeFalse()
        ->and($bySource['invoice-due']['end'])->toBeNull()
        ->and($bySource['invoice-due']['owner'])->toBeNull()
        ->and($bySource['invoice-due']['site'])->toBeNull()
        ->and($bySource['invoice-due']['ref'])->toBe('INV-00041')
        ->and($bySource['invoice-due']['desc'])->toBe('$1,234.50 · Money in · Acme Trust')
        // An overdue entry keeps its overdue status whatever its source, so the
        // shared calendar parts can render it critical.
        ->and($bySource['bill-due']['status'])->toBe('overdue');

    expect($payload['totals'])->toMatchArray([
        'total' => 2,
        'overdue' => 1,
        'invoice-due' => 1,
        'bill-due' => 1,
        'gst-due' => 0,
    ]);
});

it('accepts a source filter in either the dashed or the underscored form', function () {
    Carbon::setTestNow('2026-06-10');

    FinInvoice::factory()->create([
        'organization_id' => 1, 'due_date' => '2026-06-15', 'status' => 'sent',
        'total_amount' => 100, 'invoice_number' => 'INV-00051', 'client_name' => 'A',
    ]);
    FinBill::factory()->create([
        'organization_id' => 1, 'due_date' => '2026-06-20', 'status' => 'approved',
        'total_amount' => 500, 'amount_paid' => 0, 'bill_number' => 'BILL-202606-051',
    ]);

    $aggregator = app(FinanceCalendarAggregator::class);
    $start = Carbon::parse('2026-06-01');
    $end = Carbon::parse('2026-06-30');

    foreach ([['invoice-due'], ['invoice_due']] as $filter) {
        $items = $aggregator->itemsForRange(1, $start, $end, ['sources' => $filter]);
        expect(collect($items)->pluck('source')->unique()->all())->toBe(['invoice_due']);
    }
});

it('feeds events as JSON to a finance.dashboard user and 403s others', function () {
    Carbon::setTestNow('2026-06-10');
    $permission = Permission::firstOrCreate(['key' => 'finance.dashboard'], ['description' => 'finance.dashboard']);
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    FinInvoice::factory()->create([
        'organization_id' => 1, 'due_date' => '2026-06-15', 'status' => 'sent',
        'total_amount' => 1150, 'invoice_number' => 'INV-00031', 'client_name' => 'Acme',
    ]);

    $this->actingAs($user)
        ->getJson(route('finance.calendar.events', ['start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertOk()
        // The shared SiteCalendar shape: dashed source slugs (they become
        // --src-* tokens), all-day read-only entries, money detail in desc.
        ->assertJsonPath('events.0.source', 'invoice-due')
        ->assertJsonPath('events.0.group', 'auto')
        ->assertJsonPath('events.0.allDay', true)
        ->assertJsonPath('events.0.editable', false)
        ->assertJsonPath('events.0.end', null)
        ->assertJsonPath('events.0.status', 'due')
        ->assertJsonPath('events.0.link', '/finance/invoices/'.FinInvoice::query()->value('id'))
        ->assertJsonPath('totals.invoice-due', 1)
        ->assertJsonPath('totals.overdue', 0)
        ->assertJsonPath('totals.total', 1);

    $other = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $this->actingAs($other)
        ->getJson(route('finance.calendar.events'))
        ->assertForbidden();
});

it('renders the calendar page shell for a finance.dashboard user and 403s others', function () {
    $permission = Permission::firstOrCreate(['key' => 'finance.dashboard'], ['description' => 'finance.dashboard']);
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);

    $this->actingAs($user)
        ->get(route('finance.calendar.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/Calendar')
            ->where('sources.0.key', 'invoice-due')
            ->where('sources.0.label', 'Invoices due')
            ->where('initialSources', ['invoice-due', 'bill-due', 'payment-run', 'payroll', 'period-close']),
        );

    $other = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $this->actingAs($other)
        ->get(route('finance.calendar.index'))
        ->assertForbidden();
});
