<?php

use App\Domain\Finance\Jobs\PostFinInvoiceJournalJob;
use App\Domain\Finance\Jobs\SendInvoiceEmailJob;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\FinInvoiceJournalService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\FinancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(FinancePermissionsSeeder::class);
});

it('posts and reverses a balanced FinInvoice journal', function () {
    $accounts = createFinInvoicePostingAccounts();
    createOpenFinancePeriod();

    $invoice = createDraftFinInvoice($accounts['revenue_one']->id, $accounts['revenue_two']->id);

    /** @var FinInvoiceJournalService $service */
    $service = app(FinInvoiceJournalService::class);

    $journal = $service->postInvoiceJournal($invoice);

    $invoice->refresh();
    $journal->load('lines.account');

    expect($invoice->journal_id)->toBe($journal->id)
        ->and($invoice->gl_posted_at)->not->toBeNull()
        ->and($journal->status)->toBe('posted')
        ->and($journal->type)->toBe('billing')
        ->and($journal->source_type)->toBe(FinInvoice::class)
        ->and($journal->source_id)->toBe($invoice->id);

    expect((string) $journal->lines->firstWhere('account.code', '1100')->debit)->toBe('345.00')
        ->and((string) $journal->lines->firstWhere('account.code', '4030')->credit)->toBe('100.00')
        ->and((string) $journal->lines->firstWhere('account.code', '4040')->credit)->toBe('200.00')
        ->and((string) $journal->lines->firstWhere('account.code', '2200')->credit)->toBe('45.00');

    $secondAttempt = $service->postInvoiceJournal($invoice->refresh());

    expect($secondAttempt->id)->toBe($journal->id)
        ->and(FinJournal::where('source_type', FinInvoice::class)->where('source_id', $invoice->id)->count())->toBe(1);

    $reversal = $service->reverseInvoiceJournal($invoice->refresh());

    $invoice->refresh();
    $journal->refresh();
    $reversal->load('lines.account');

    expect($invoice->journal_id)->toBeNull()
        ->and($invoice->gl_posted_at)->toBeNull()
        ->and($journal->reversed_by_journal_id)->toBe($reversal->id)
        ->and($reversal->type)->toBe('adjustment')
        ->and((string) $reversal->lines->firstWhere('account.code', '1100')->credit)->toBe('345.00')
        ->and((string) $reversal->lines->firstWhere('account.code', '4030')->debit)->toBe('100.00')
        ->and((string) $reversal->lines->firstWhere('account.code', '4040')->debit)->toBe('200.00')
        ->and((string) $reversal->lines->firstWhere('account.code', '2200')->debit)->toBe('45.00');
});

it('queues FinInvoice journal posting only when send moves a draft invoice to sent', function () {
    Queue::fake();

    $accounts = createFinInvoicePostingAccounts();
    $invoice = createDraftFinInvoice($accounts['revenue_one']->id, $accounts['revenue_two']->id);
    $user = createFinanceUserForInvoicePosting((int) $invoice->client->site_id);

    $this->actingAs($user)
        ->post(route('finance.invoices.send', $invoice))
        ->assertRedirect(route('finance.invoices.show', $invoice));

    $invoice->refresh();

    expect($invoice->status)->toBe('sent')
        ->and($invoice->sent_at)->not->toBeNull();

    Queue::assertPushed(PostFinInvoiceJournalJob::class, function (PostFinInvoiceJournalJob $job) use ($invoice) {
        return $job->invoice->is($invoice);
    });
    Queue::assertPushed(SendInvoiceEmailJob::class, 1);

    $this->actingAs($user)
        ->post(route('finance.invoices.send', $invoice))
        ->assertRedirect(route('finance.invoices.show', $invoice));

    Queue::assertPushed(PostFinInvoiceJournalJob::class, 1);
    Queue::assertPushed(SendInvoiceEmailJob::class, 2);
});

it('reverses a posted FinInvoice journal when the invoice is cancelled', function () {
    $accounts = createFinInvoicePostingAccounts();
    createOpenFinancePeriod();

    $invoice = createDraftFinInvoice($accounts['revenue_one']->id, $accounts['revenue_two']->id);
    $journal = app(FinInvoiceJournalService::class)->postInvoiceJournal($invoice);
    $user = createFinanceUserForInvoicePosting((int) $invoice->client->site_id);

    $this->actingAs($user)
        ->post(route('finance.invoices.cancel', $invoice))
        ->assertRedirect(route('finance.invoices.show', $invoice));

    $invoice->refresh();
    $journal->refresh();
    $reversal = FinJournal::findOrFail($journal->reversed_by_journal_id);

    expect($invoice->status)->toBe('cancelled')
        ->and($invoice->journal_id)->toBeNull()
        ->and($invoice->gl_posted_at)->toBeNull()
        ->and($reversal->status)->toBe('posted')
        ->and($reversal->type)->toBe('adjustment')
        ->and($reversal->source_type)->toBe(FinInvoice::class)
        ->and($reversal->source_id)->toBe($invoice->id);
});

function createFinInvoicePostingAccounts(): array
{
    return [
        'ar' => FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => '1100',
            'name' => 'Accounts Receivable',
            'type' => 'asset',
            'sub_type' => 'accounts_receivable',
        ]),
        'gst' => FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => '2200',
            'name' => 'GST Collected',
            'type' => 'liability',
            'sub_type' => 'current_liability',
        ]),
        'revenue_one' => FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => '4030',
            'name' => 'Private Pay Revenue',
            'type' => 'revenue',
            'sub_type' => 'revenue',
        ]),
        'revenue_two' => FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => '4040',
            'name' => 'Respite Revenue',
            'type' => 'revenue',
            'sub_type' => 'revenue',
        ]),
    ];
}

function createOpenFinancePeriod(): FinFiscalPeriod
{
    return FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'FY2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);
}

function createDraftFinInvoice(int $firstRevenueAccountId, int $secondRevenueAccountId): FinInvoice
{
    $site = Site::factory()->create(['is_active' => true]);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    $invoice = FinInvoice::factory()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'invoice_number' => 'INV-AR-001',
        'invoice_date' => '2026-05-01',
        'due_date' => '2026-05-31',
        'client_name' => 'Acme Care',
        'client_email' => 'accounts@example.test',
        'status' => 'draft',
        'subtotal' => '300.00',
        'tax_amount' => '45.00',
        'total_amount' => '345.00',
    ]);

    $invoice->lines()->create([
        'description' => 'Support service',
        'quantity' => '1.00',
        'unit_price' => '100.00',
        'tax_amount' => '15.00',
        'line_total' => '115.00',
        'sort_order' => 0,
        'account_id' => $firstRevenueAccountId,
    ]);

    $invoice->lines()->create([
        'description' => 'Respite service',
        'quantity' => '2.00',
        'unit_price' => '100.00',
        'tax_amount' => '30.00',
        'line_total' => '230.00',
        'sort_order' => 1,
        'account_id' => $secondRevenueAccountId,
    ]);

    return $invoice;
}

function createFinanceUserForInvoicePosting(int $siteId): User
{
    $user = User::factory()->create([
        'role' => 'finance',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    $role = Role::where('name', 'finance')->firstOrFail();
    $user->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $siteId,
        'secondary_site_ids' => [],
        'start_date' => today()->subMonth(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    return $user;
}
