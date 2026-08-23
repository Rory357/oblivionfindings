<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillLine;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Domain\Governance\Models\SpendApproval;
use App\Domain\Governance\Models\SpendApprovalDecision;
use App\Domain\Governance\Services\SpendApprovalCommandService;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\FinancePermissionsSeeder;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Helpers are prefixed sag_ to remain unique in Pest's global function space. */
function sag_grant(User $user, array $permissionKeys): void
{
    $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
    foreach ($permissions as $permissionId) {
        $user->permissionOverrides()->syncWithoutDetaching([$permissionId => ['allowed' => true]]);
    }
    $user->unsetRelation('permissionOverrides');
    $user->unsetRelation('roles');
}

function sag_actor(?Site $site, array $permissions): User
{
    $user = User::factory()->create([
        'organization_id' => 1,
        'approved_at' => now(),
    ]);
    if ($site) {
        ensureCanonicalHrStaffProfile($user, $site);
    }
    sag_grant($user, $permissions);

    return $user;
}

function sag_seedAccounts(): void
{
    foreach ([['2000', 'Accounts Payable', 'liability'], ['6000', 'Supplies', 'expense']] as [$code, $name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'is_active' => true,
        ]);
    }

    FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'FY',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);
}

function sag_draftBill(Site $site, string $total = '15000.00'): FinBill
{
    $expense = FinAccount::where('organization_id', 1)->where('code', '6000')->firstOrFail();
    $vendor = FinVendor::factory()->create(['organization_id' => 1]);
    $bill = FinBill::factory()->create([
        'organization_id' => 1,
        'vendor_id' => $vendor->id,
        'site_id' => $site->id,
        'status' => 'draft',
        'bill_date' => now()->subDay(),
        'due_date' => now()->addMonth(),
        'subtotal' => $total,
        'gst_amount' => 0,
        'total_amount' => $total,
        'amount_paid' => 0,
    ]);
    FinBillLine::create([
        'bill_id' => $bill->id,
        'description' => 'Governed supplies',
        'quantity' => 1,
        'unit_price' => $total,
        'gst_rate' => 0,
        'gst_amount' => 0,
        'line_total' => $total,
        'account_id' => $expense->id,
    ]);

    return $bill;
}

function sag_billPayload(FinBill $bill, ?int $approvalId): array
{
    $line = $bill->lines()->sole();

    return [
        'vendor_id' => $bill->vendor_id,
        'vendor_reference' => $bill->vendor_reference,
        'bill_date' => $bill->bill_date->toDateString(),
        'due_date' => $bill->due_date->toDateString(),
        'notes' => $bill->notes,
        'purchase_order_id' => $bill->purchase_order_id,
        'spend_approval_id' => $approvalId,
        'lines' => [[
            'description' => $line->description,
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'gst_rate' => 0,
            'account_id' => $line->account_id,
            'cost_centre_id' => $line->cost_centre_id,
            'funding_stream_id' => $line->funding_stream_id,
        ]],
    ];
}

function sag_governedApproval(FinBill $bill, Site $site, string $amount = '20000.00'): SpendApproval
{
    $requester = sag_actor($site, ['governance.spend.view', 'governance.spend.request']);
    $decider = sag_actor($site, ['governance.spend.view', 'governance.spend.approve']);
    $service = app(SpendApprovalCommandService::class);
    $approval = $service->create($requester, [
        'title' => 'Independent bill authority '.$bill->id,
        'description' => 'Governed independently before AP posting.',
        'category' => SpendApproval::CATEGORY_CAPEX,
        'amount' => $amount,
        'currency' => 'NZD',
        'site_id' => $site->id,
        'source_type' => FinBill::class,
        'source_id' => $bill->id,
    ]);
    $submitted = $service->submit($requester, $approval->id, $approval->version);

    return $service->decide($decider, $submitted->id, SpendApproval::STATUS_APPROVED, [
        'decision_key' => (string) Str::uuid(),
        'expected_version' => $submitted->version,
        'expected_content_digest' => $submitted->content_digest,
        'decision_notes' => 'Independent evidence-based approval.',
    ]);
}

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(FinancePermissionsSeeder::class);
    $this->seed(GovernancePermissionsSeeder::class);
    sag_seedAccounts();
    config(['finance.spend_approval.enforce' => true, 'finance.spend_approval.threshold' => 10000]);
});

it('bounds the bill approval picker by exact governance action and canonical Site scope', function (): void {
    $siteA = Site::factory()->create(['name' => 'Picker Site A']);
    $siteB = Site::factory()->create(['name' => 'Picker Site B']);
    $approvalA = sag_governedApproval(sag_draftBill($siteA), $siteA);
    $approvalB = sag_governedApproval(sag_draftBill($siteB), $siteB);

    $limited = sag_actor($siteA, ['finance.ap.view', 'finance.ap.manage', 'governance.spend.view']);
    $this->actingAs($limited)->get('/finance/bills')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('spendApprovals', 1)
            ->where('spendApprovals.0.id', $approvalA->id));

    $noGovernanceAction = sag_actor($siteA, ['finance.ap.view', 'finance.ap.manage']);
    sag_grant($noGovernanceAction, ['governance.spend.viewAllSites']);
    $this->actingAs($noGovernanceAction)->get('/finance/bills')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('spendApprovals', 0));

    $emptyScope = sag_actor(null, ['finance.ap.view', 'finance.ap.manage', 'governance.spend.view']);
    $this->actingAs($emptyScope)->get('/finance/bills')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('spendApprovals', 0));

    $global = sag_actor(null, [
        'finance.ap.view',
        'finance.ap.manage',
        'governance.spend.view',
        'governance.spend.viewAllSites',
    ]);
    $this->actingAs($global)->get('/finance/bills')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('spendApprovals', 2)
            ->where('spendApprovals.0.id', $approvalB->id)
            ->where('spendApprovals.1.id', $approvalA->id));
});

it('conceals foreign missing and wrong-source links with zero bill effects', function (): void {
    $siteA = Site::factory()->create(['name' => 'Link Site A']);
    $siteB = Site::factory()->create(['name' => 'Link Site B']);
    $billA = sag_draftBill($siteA);
    $billB = sag_draftBill($siteB);
    $approvalA = sag_governedApproval($billA, $siteA);
    $approvalB = sag_governedApproval($billB, $siteB);
    $actor = sag_actor($siteA, ['finance.ap.view', 'finance.ap.manage', 'governance.spend.view']);
    $before = $billA->fresh()->getAttributes();

    $foreign = $this->actingAs($actor)->put("/finance/bills/{$billA->id}", sag_billPayload($billA, $approvalB->id));
    $missing = $this->actingAs($actor)->put("/finance/bills/{$billA->id}", sag_billPayload($billA, 2147483647));
    $wrongSource = $this->actingAs($actor)->put("/finance/bills/{$billB->id}", sag_billPayload($billB, $approvalA->id));
    $foreign->assertNotFound();
    $missing->assertNotFound();
    $wrongSource->assertNotFound();
    expect($foreign->getContent())->toBe($missing->getContent())
        ->and($wrongSource->getContent())->toBe($missing->getContent())
        ->and($billA->fresh()->getAttributes())->toBe($before)
        ->and($billA->fresh()->spend_approval_id)->toBeNull();

    $billCount = FinBill::count();
    $storePayload = sag_billPayload($billA, $approvalA->id);
    $storePayload['bill_number'] = 'BILL-STORE-SOURCE-MISMATCH';
    $sourceMismatch = $this->actingAs($actor)->post('/finance/bills', $storePayload);
    $storePayload['spend_approval_id'] = $approvalB->id;
    $foreignStore = $this->actingAs($actor)->post('/finance/bills', $storePayload);
    $storePayload['spend_approval_id'] = 2147483647;
    $missingStore = $this->actingAs($actor)->post('/finance/bills', $storePayload);
    $sourceMismatch->assertNotFound();
    $foreignStore->assertNotFound();
    $missingStore->assertNotFound();
    expect($foreignStore->getContent())->toBe($missingStore->getContent())
        ->and($sourceMismatch->getContent())->toBe($missingStore->getContent());
    expect(FinBill::count())->toBe($billCount);
});

it('allows explicit global Site scope only with both finance and governance actions', function (): void {
    $site = Site::factory()->create(['name' => 'Global Link Site']);
    $bill = sag_draftBill($site);
    $approval = sag_governedApproval($bill, $site);
    $scopeWithoutView = sag_actor(null, ['finance.ap.view', 'finance.ap.manage', 'governance.spend.viewAllSites']);

    $this->actingAs($scopeWithoutView)
        ->put("/finance/bills/{$bill->id}", sag_billPayload($bill, $approval->id))
        ->assertForbidden();
    expect($bill->fresh()->spend_approval_id)->toBeNull();

    $global = sag_actor(null, [
        'finance.ap.view',
        'finance.ap.manage',
        'governance.spend.view',
        'governance.spend.viewAllSites',
    ]);
    $this->actingAs($global)
        ->put("/finance/bills/{$bill->id}", sag_billPayload($bill, $approval->id))
        ->assertRedirect();
    expect($bill->fresh()->spend_approval_id)->toBe($approval->id);
});

it('posts only against independently decided current exact bill evidence', function (): void {
    $site = Site::factory()->create(['name' => 'Posting Site']);
    $bill = sag_draftBill($site);
    $approval = sag_governedApproval($bill, $site);
    $actor = sag_actor($site, ['finance.ap.manage', 'governance.spend.view']);
    app(AccountsPayableService::class)->updateBill($bill, sag_billPayload($bill, $approval->id), $actor);

    $result = app(AccountsPayableService::class)->approveBill($bill->fresh(), $actor->id);

    expect($result->status)->toBe('approved')
        ->and($result->journal_id)->not->toBeNull()
        ->and($approval->requested_by)->not->toBe($approval->decided_by)
        ->and(SpendApprovalDecision::where('spend_approval_id', $approval->id)->count())->toBe(1);
    $journal = $result->journal()->with('lines')->firstOrFail();
    $debits = $journal->lines->reduce(fn (string $total, $line) => bcadd($total, (string) $line->debit, 2), '0');
    $credits = $journal->lines->reduce(fn (string $total, $line) => bcadd($total, (string) $line->credit, 2), '0');
    expect($debits)->toBe('15000.00')->and($credits)->toBe('15000.00');
});

it('rejects missing approval foreign action and source or evidence tamper without posting', function (string $case): void {
    $siteA = Site::factory()->create(['name' => 'Gate Site A']);
    $siteB = Site::factory()->create(['name' => 'Gate Site B']);
    $bill = sag_draftBill($siteA);
    $approval = sag_governedApproval($bill, $siteA);
    $linker = sag_actor($siteA, ['finance.ap.manage', 'governance.spend.view']);
    app(AccountsPayableService::class)->updateBill($bill, sag_billPayload($bill, $approval->id), $linker);
    $actor = $case === 'foreign-action'
        ? sag_actor($siteB, ['finance.ap.manage', 'governance.spend.view'])
        : sag_actor($siteA, ['finance.ap.manage', 'governance.spend.view']);

    if ($case === 'missing-approval') {
        DB::table('fin_bills')->where('id', $bill->id)->update(['spend_approval_id' => null]);
    } elseif ($case === 'source-mismatch') {
        DB::table('spend_approvals')->where('id', $approval->id)->update(['source_id' => 2147483647]);
    } elseif ($case === 'source-tamper') {
        DB::table('fin_bills')->where('id', $bill->id)->update(['total_amount' => '14999.00']);
    } elseif ($case === 'decision-tamper') {
        DB::table('spend_approval_decisions')->where('spend_approval_id', $approval->id)->update([
            'reason' => 'Tampered decision evidence.',
        ]);
    } elseif ($case === 'approval-status') {
        DB::table('spend_approvals')->where('id', $approval->id)->update([
            'status' => SpendApproval::STATUS_SUBMITTED,
        ]);
    } elseif ($case === 'insufficient-amount') {
        DB::table('spend_approvals')->where('id', $approval->id)->update([
            'amount' => '14999.99',
        ]);
    }

    try {
        app(AccountsPayableService::class)->approveBill($bill->fresh(), $actor->id);
        $this->fail('Invalid governance evidence must not authorize AP posting.');
    } catch (InvalidArgumentException|AuthorizationException|ModelNotFoundException) {
        expect(true)->toBeTrue();
    }

    expect($bill->fresh()->status)->toBe('draft')
        ->and($bill->fresh()->journal_id)->toBeNull()
        ->and(DB::table('fin_journals')->count())->toBe(0);
})->with([
    'missing-approval',
    'foreign-action',
    'source-mismatch',
    'source-tamper',
    'decision-tamper',
    'approval-status',
    'insufficient-amount',
]);

it('preserves below-threshold and disabled-gate behavior', function (bool $enabled, string $total): void {
    config(['finance.spend_approval.enforce' => $enabled]);
    $site = Site::factory()->create(['name' => 'Ungated Site']);
    $bill = sag_draftBill($site, $total);
    $actor = sag_actor($site, ['finance.ap.manage']);

    $result = app(AccountsPayableService::class)->approveBill($bill, $actor->id);

    expect($result->status)->toBe('approved')->and($result->journal_id)->not->toBeNull();
})->with([
    'below threshold' => [true, '500.00'],
    'enforcement disabled' => [false, '50000.00'],
]);
