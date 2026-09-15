<?php

namespace Tests\Feature\Governance;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCostCentre;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPaymentRunItem;
use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Governance\Models\BudgetLineItem;
use App\Domain\Governance\Models\SpendApproval;
use App\Domain\Governance\Models\SpendApprovalDecision;
use App\Domain\Governance\Services\SpendApprovalCommandService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class SpendApprovalAuthorityTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
        $this->siteA = Site::factory()->create(['name' => 'Accessible Site']);
        $this->siteB = Site::factory()->create(['name' => 'Foreign Site']);
    }

    public function test_read_and_attachment_surfaces_enforce_exact_view_and_canonical_site_scope(): void
    {
        Storage::fake('local');
        $requester = $this->createUserWithRole('board_secretary');
        $accessible = $this->draft($requester, [
            'reference' => 'SA-READ-ACCESSIBLE',
            'attachments' => [[
                'id' => 'accessible-evidence',
                'path' => 'governance/spend-approvals/accessible.txt',
                'original_name' => 'accessible.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 10,
                'sha256' => hash('sha256', 'accessible'),
            ]],
        ]);
        $foreign = $this->draft($requester, [
            'reference' => 'SA-READ-FOREIGN',
            'site_id' => $this->siteB->id,
            'attachments' => [[
                'id' => 'foreign-evidence',
                'path' => 'governance/spend-approvals/foreign.txt',
                'original_name' => 'foreign.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 7,
                'sha256' => hash('sha256', 'foreign'),
            ]],
        ]);
        Storage::disk('local')->put('governance/spend-approvals/accessible.txt', 'accessible');
        Storage::disk('local')->put('governance/spend-approvals/foreign.txt', 'foreign');

        $viewer = $this->createUserWithRole('board_member');
        // Seeded board members don't hold the spend view action; grant exactly it.
        $this->grant($viewer, ['governance.spend.view']);
        $this->assignSite($viewer, $this->siteA);
        $this->actingAs($viewer)->get('/governance/spend-approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('approvals.data', 1)
                ->where('approvals.data.0.id', $accessible->id)
                ->where('summary.pending', 1));
        $this->actingAs($viewer)->get("/governance/spend-approvals/{$accessible->id}")->assertOk();
        $this->actingAs($viewer)
            ->get("/governance/spend-approvals/{$accessible->id}/attachments/accessible-evidence/download")
            ->assertOk();

        $foreignShow = $this->actingAs($viewer)->get("/governance/spend-approvals/{$foreign->id}");
        $missingShow = $this->actingAs($viewer)->get('/governance/spend-approvals/2147483647');
        $foreignShow->assertNotFound();
        $missingShow->assertNotFound();
        $this->assertSame($missingShow->getContent(), $foreignShow->getContent());

        $foreignDownload = $this->actingAs($viewer)
            ->get("/governance/spend-approvals/{$foreign->id}/attachments/foreign-evidence/download");
        $missingDownload = $this->actingAs($viewer)
            ->get('/governance/spend-approvals/2147483647/attachments/foreign-evidence/download');
        $foreignDownload->assertNotFound();
        $missingDownload->assertNotFound();
        $this->assertSame($missingDownload->getContent(), $foreignDownload->getContent());
        $this->actingAs($viewer)
            ->get("/governance/spend-approvals/{$accessible->id}/attachments/missing-evidence/download")
            ->assertNotFound();

        $emptyViewer = $this->createUserWithRole('board_member');
        $this->grant($emptyViewer, ['governance.spend.view']);
        $this->actingAs($emptyViewer)->get('/governance/spend-approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('approvals.data', 0)
                ->where('summary.pending', 0)
                ->where('summary.approved_this_year', fn ($total) => (float) $total === 0.0)
                ->where('summary.rejected_this_year', fn ($total) => (float) $total === 0.0));
        $this->actingAs($emptyViewer)->get("/governance/spend-approvals/{$accessible->id}")->assertNotFound();
        $this->actingAs($emptyViewer)
            ->get("/governance/spend-approvals/{$accessible->id}/attachments/accessible-evidence/download")
            ->assertNotFound();

        $scopeWithoutView = User::factory()->create(['approved_at' => now()]);
        $this->grant($scopeWithoutView, ['governance.spend.viewAllSites']);
        $this->actingAs($scopeWithoutView)->get('/governance/spend-approvals')->assertForbidden();
        $this->actingAs($scopeWithoutView)->get("/governance/spend-approvals/{$foreign->id}")->assertForbidden();
        $this->actingAs($scopeWithoutView)
            ->get("/governance/spend-approvals/{$foreign->id}/attachments/foreign-evidence/download")
            ->assertForbidden();

        $globalViewer = User::factory()->create(['approved_at' => now()]);
        $this->grant($globalViewer, [
            'governance.spend.view',
            'governance.spend.viewAllSites',
        ]);
        $this->actingAs($globalViewer)->get('/governance/spend-approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('approvals.data', 2));
        $this->actingAs($globalViewer)->get("/governance/spend-approvals/{$foreign->id}")->assertOk();
        $this->actingAs($globalViewer)
            ->get("/governance/spend-approvals/{$foreign->id}/attachments/foreign-evidence/download")
            ->assertOk();

        $this->assertSame(
            ['governance/spend-approvals/accessible.txt', 'governance/spend-approvals/foreign.txt'],
            collect(Storage::disk('local')->allFiles())->sort()->values()->all(),
        );
    }

    public function test_own_draft_or_manage_any_controls_update_submit_and_draft_evidence(): void
    {
        Storage::fake('local');
        $requester = $this->createUserWithRole('board_secretary');
        $otherRequester = $this->createUserWithRole('board_secretary');
        $approval = $this->draft($requester);
        $this->assignSite($otherRequester, $this->siteA);

        $this->actingAs($otherRequester)->put("/governance/spend-approvals/{$approval->id}", [
            ...$this->draftPayload('Foreign edit'),
            'expected_version' => 1,
        ])->assertForbidden();
        $this->actingAs($otherRequester)->post("/governance/spend-approvals/{$approval->id}/submit", [
            'expected_version' => 1,
        ])->assertForbidden();
        $this->actingAs($otherRequester)->post("/governance/spend-approvals/{$approval->id}/attachments", [
            'files' => [UploadedFile::fake()->create('foreign.txt', 1, 'text/plain')],
        ])->assertForbidden();

        $this->assertSame('Governed spend', $approval->fresh()->title);
        $this->assertSame(SpendApproval::STATUS_DRAFT, $approval->fresh()->status);
        $this->assertNull($approval->fresh()->attachments);

        $this->grant($otherRequester, ['governance.spend.manageAny']);
        $this->actingAs($otherRequester)->put("/governance/spend-approvals/{$approval->id}", [
            ...$this->draftPayload('Managed edit'),
            'expected_version' => 1,
        ])->assertRedirect();
        $this->actingAs($otherRequester)->post("/governance/spend-approvals/{$approval->id}/attachments", [
            'files' => [UploadedFile::fake()->create('managed.txt', 1, 'text/plain')],
        ])->assertRedirect();
        $this->actingAs($otherRequester)->post("/governance/spend-approvals/{$approval->id}/submit", [
            'expected_version' => 3,
        ])->assertRedirect();

        $submitted = $approval->fresh();
        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $submitted->status);
        $this->assertSame($otherRequester->id, $submitted->submitted_by);
        $this->assertSame(4, $submitted->version);
        $this->assertSame(4, $submitted->submission_version);
        $this->assertSame($submitted->decisionContentDigest(), $submitted->content_digest);

        $this->actingAs($requester)->post("/governance/spend-approvals/{$approval->id}/attachments", [
            'files' => [UploadedFile::fake()->create('late.txt', 1, 'text/plain')],
        ])->assertForbidden();
        $this->assertCount(1, $approval->fresh()->attachments);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_canonical_parents_are_inferred_and_mismatches_have_no_side_effects(): void
    {
        $requester = $this->createUserWithRole('board_secretary');
        $this->assignSite($requester, $this->siteA);
        $costCentre = FinCostCentre::create([
            'organization_id' => 1,
            'code' => 'CC-GOV-1',
            'name' => 'Governance Site',
            'type' => 'site',
            'site_id' => $this->siteA->id,
            'is_active' => true,
            'created_by' => $requester->id,
        ]);
        $fundingStream = FinFundingStream::create([
            'organization_id' => 1,
            'code' => 'FS-GOV-1',
            'name' => 'Governance Funding',
            'funder_type' => 'other',
            'is_active' => true,
            'created_by' => $requester->id,
        ]);
        $otherFundingStream = FinFundingStream::create([
            'organization_id' => 1,
            'code' => 'FS-GOV-2',
            'name' => 'Other Funding',
            'funder_type' => 'other',
            'is_active' => true,
            'created_by' => $requester->id,
        ]);
        $donorFund = FinDonorFund::create([
            'organization_id' => 1,
            'fund_code' => 'DF-GOV-1',
            'fund_name' => 'Restricted Fund',
            'fund_type' => 'grant',
            'funding_stream_id' => $fundingStream->id,
            'created_by' => $requester->id,
        ]);
        $budget = $this->createBudget($requester, ['fiscal_year' => '2026-A']);
        $otherBudget = $this->createBudget($requester, ['fiscal_year' => '2026-B']);
        $line = BudgetLineItem::create([
            'budget_id' => $budget->id,
            'category' => 'operations',
            'description' => 'Governance services',
            'budget_amount' => 50000,
        ]);

        $approval = app(SpendApprovalCommandService::class)->create($requester, [
            ...$this->draftPayload(),
            'cost_centre_id' => $costCentre->id,
            'donor_fund_id' => $donorFund->id,
            'budget_line_item_id' => $line->id,
        ]);
        $this->assertSame($this->siteA->id, $approval->site_id);
        $this->assertSame($fundingStream->id, $approval->funding_stream_id);
        $this->assertSame($budget->id, $approval->budget_id);

        foreach ([
            ['field' => 'site_id', 'payload' => ['cost_centre_id' => $costCentre->id, 'site_id' => $this->siteB->id]],
            ['field' => 'funding_stream_id', 'payload' => ['donor_fund_id' => $donorFund->id, 'funding_stream_id' => $otherFundingStream->id]],
            ['field' => 'budget_line_item_id', 'payload' => ['budget_line_item_id' => $line->id, 'budget_id' => $otherBudget->id]],
        ] as $mismatch) {
            $count = SpendApproval::count();
            try {
                app(SpendApprovalCommandService::class)->create($requester, [
                    ...$this->draftPayload('Mismatched parents'),
                    ...$mismatch['payload'],
                ]);
                $this->fail('A mismatched parent graph must be rejected.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
            $this->assertSame($count, SpendApproval::count());
        }
    }

    public function test_finance_sources_are_exact_site_consistent_snapshotted_and_digest_bound(): void
    {
        $requester = $this->createUserWithRole('board_secretary');
        $this->assignSite($requester, $this->siteA);
        $vendor = FinVendor::factory()->create(['organization_id' => 1]);
        $costCentre = FinCostCentre::create([
            'organization_id' => 1,
            'code' => 'CC-SOURCE-A',
            'name' => 'Source Site A',
            'type' => 'site',
            'site_id' => $this->siteA->id,
            'is_active' => true,
            'created_by' => $requester->id,
        ]);
        $bill = FinBill::factory()->create([
            'organization_id' => 1,
            'vendor_id' => $vendor->id,
            'site_id' => $this->siteA->id,
            'bill_number' => 'BILL-GOV-SOURCE',
            'status' => 'awaiting_approval',
            'total_amount' => 12000,
        ]);
        $purchaseOrder = FinPurchaseOrder::factory()->create([
            'organization_id' => 1,
            'vendor_id' => $vendor->id,
            'cost_centre_id' => $costCentre->id,
            'po_number' => 'PO-GOV-SOURCE',
            'status' => 'sent',
            'total_amount' => 12000,
        ]);
        $paymentRun = FinPaymentRun::factory()->create([
            'organization_id' => 1,
            'run_number' => 'PAY-GOV-SOURCE',
            'status' => 'draft',
            'total_amount' => 12000,
            'item_count' => 1,
        ]);
        $paymentItem = FinPaymentRunItem::create([
            'payment_run_id' => $paymentRun->id,
            'site_id' => $this->siteA->id,
            'bill_id' => $bill->id,
            'vendor_id' => $vendor->id,
            'amount' => 12000,
            'reference' => $bill->bill_number,
            'status' => 'pending',
        ]);

        $service = app(SpendApprovalCommandService::class);
        $billApproval = $service->create($requester, [
            ...$this->draftPayload('Bill-backed approval'),
            'source_type' => FinBill::class,
            'source_id' => $bill->id,
        ]);
        $purchaseOrderApproval = $service->create($requester, [
            ...$this->draftPayload('PO-backed approval'),
            'source_type' => FinPurchaseOrder::class,
            'source_id' => $purchaseOrder->id,
        ]);
        $paymentRunApproval = $service->create($requester, [
            ...$this->draftPayload('Run-backed approval'),
            'source_type' => FinPaymentRun::class,
            'source_id' => $paymentRun->id,
        ]);
        $this->assertSame(FinBill::class, $billApproval->source_type);
        $this->assertSame($purchaseOrder->id, $purchaseOrderApproval->source_id);
        $this->assertSame($paymentRun->id, $paymentRunApproval->source_id);

        $submittedBill = $this->submit($requester, $billApproval);
        $decider = $this->createAdminUser();
        // Spend of this size needs board sign-off: it is approved under the
        // passed resolution that covers it.
        $boardResolution = $this->createResolution($decider, [
            'status' => 'closed',
            'outcome' => 'carried',
            'closed_at' => now(),
            'cost_impact' => ['amount' => 12000],
        ]);
        $decided = $service->decide(
            $decider,
            $submittedBill->id,
            SpendApproval::STATUS_APPROVED,
            [...$this->decisionPayload($submittedBill), 'resolution_id' => $boardResolution->id],
        );
        $evidence = SpendApprovalDecision::where('spend_approval_id', $decided->id)->sole();
        $this->assertSame(FinBill::class, $evidence->parent_evidence['source']['type']);
        $this->assertSame($bill->id, $evidence->parent_evidence['source']['id']);
        $this->assertSame('BILL-GOV-SOURCE', $evidence->parent_evidence['source']['reference']);

        $missingBill = FinBill::factory()->create([
            'organization_id' => 1,
            'vendor_id' => $vendor->id,
            'site_id' => $this->siteA->id,
            'bill_number' => 'BILL-GOV-MISSING',
            'status' => 'awaiting_approval',
            'total_amount' => 12000,
        ]);
        $missingBillApproval = $service->create($requester, [
            ...$this->draftPayload('Missing bill source approval'),
            'source_type' => FinBill::class,
            'source_id' => $missingBill->id,
        ]);
        $submittedMissingBill = $this->submit($requester, $missingBillApproval);
        $missingBill->delete();
        try {
            $service->decide(
                $decider,
                $submittedMissingBill->id,
                SpendApproval::STATUS_APPROVED,
                $this->decisionPayload($submittedMissingBill),
            );
            $this->fail('A missing submitted Finance source must be concealed.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $submittedMissingBill->fresh()->status);
        $this->assertDatabaseMissing('spend_approval_decisions', ['spend_approval_id' => $submittedMissingBill->id]);

        $submittedPurchaseOrder = $this->submit($requester, $purchaseOrderApproval);
        $costCentre->update(['site_id' => $this->siteB->id]);
        try {
            $service->decide(
                $decider,
                $submittedPurchaseOrder->id,
                SpendApproval::STATUS_APPROVED,
                $this->decisionPayload($submittedPurchaseOrder),
            );
            $this->fail('A Site-mismatched submitted Finance source must be concealed.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $submittedPurchaseOrder->fresh()->status);
        $this->assertDatabaseMissing('spend_approval_decisions', ['spend_approval_id' => $submittedPurchaseOrder->id]);

        $submittedRun = $this->submit($requester, $paymentRunApproval);
        $paymentItem->update(['amount' => 11999]);
        try {
            $service->decide(
                $decider,
                $submittedRun->id,
                SpendApproval::STATUS_APPROVED,
                $this->decisionPayload($submittedRun),
            );
            $this->fail('Tampered Finance source evidence must invalidate the decision digest.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expected_content_digest', $exception->errors());
        }
        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $submittedRun->fresh()->status);
        $this->assertDatabaseMissing('spend_approval_decisions', ['spend_approval_id' => $submittedRun->id]);
    }

    public function test_missing_mismatched_mixed_and_unsupported_finance_sources_have_no_effects(): void
    {
        $requester = $this->createUserWithRole('board_secretary');
        $this->assignSite($requester, $this->siteA);
        $vendor = FinVendor::factory()->create(['organization_id' => 1]);
        $foreignBill = FinBill::factory()->create([
            'organization_id' => 1,
            'vendor_id' => $vendor->id,
            'site_id' => $this->siteB->id,
            'bill_number' => 'BILL-GOV-FOREIGN',
        ]);
        $invalidRead = $this->draft($requester, [
            'reference' => 'SA-INVALID-SOURCE-READ',
            'source_type' => FinBill::class,
            'source_id' => $foreignBill->id,
        ]);
        $this->actingAs($requester)->get('/governance/spend-approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('approvals.data', 0));
        $this->actingAs($requester)->get("/governance/spend-approvals/{$invalidRead->id}")->assertNotFound();
        $mixedRun = FinPaymentRun::factory()->create([
            'organization_id' => 1,
            'run_number' => 'PAY-GOV-MIXED',
            'item_count' => 2,
        ]);
        foreach ([$this->siteA, $this->siteB] as $index => $site) {
            FinPaymentRunItem::create([
                'payment_run_id' => $mixedRun->id,
                'site_id' => $site->id,
                'vendor_id' => $vendor->id,
                'amount' => 100 + $index,
                'reference' => "MIXED-{$index}",
                'status' => 'pending',
            ]);
        }

        $service = app(SpendApprovalCommandService::class);
        $before = SpendApproval::count();
        foreach ([
            ['source_type' => FinBill::class, 'source_id' => 2147483647],
            ['source_type' => FinBill::class, 'source_id' => $foreignBill->id],
            ['source_type' => FinPaymentRun::class, 'source_id' => $mixedRun->id],
            ['source_type' => User::class, 'source_id' => $requester->id],
            ['source_type' => FinBill::class, 'source_id' => null],
            ['source_type' => null, 'source_id' => $foreignBill->id],
        ] as $source) {
            try {
                $service->create($requester, [
                    ...$this->draftPayload('Invalid Finance source'),
                    ...$source,
                ]);
                $this->fail('Invalid Finance source identities must be concealed.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
            $this->assertSame($before, SpendApproval::count());
        }
    }

    public function test_empty_scope_fails_closed_and_global_scope_still_requires_the_action(): void
    {
        $emptyScopeActor = $this->createUserWithRole('board_secretary');
        $payload = [...$this->draftPayload('No accessible site'), 'site_id' => $this->siteA->id];

        $this->actingAs($emptyScopeActor)->get('/governance/spend-approvals/create')->assertNotFound();
        $this->actingAs($emptyScopeActor)->post('/governance/spend-approvals', $payload)->assertNotFound();
        $this->assertDatabaseCount('spend_approvals', 0);

        $scopeOnlyActor = User::factory()->create(['approved_at' => now()]);
        $this->grant($scopeOnlyActor, [
            'governance.spend.view',
            'governance.spend.viewAllSites',
        ]);
        $this->actingAs($scopeOnlyActor)->post('/governance/spend-approvals', $payload)->assertForbidden();
        $this->assertDatabaseCount('spend_approvals', 0);

        $globalRequester = User::factory()->create(['approved_at' => now()]);
        $this->grant($globalRequester, [
            'governance.spend.view',
            'governance.spend.request',
            'governance.spend.viewAllSites',
        ]);
        $this->actingAs($globalRequester)->post('/governance/spend-approvals', [
            ...$this->draftPayload('Global request with exact action'),
            'site_id' => $this->siteB->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('spend_approvals', [
            'title' => 'Global request with exact action',
            'site_id' => $this->siteB->id,
            'requested_by' => $globalRequester->id,
        ]);
    }

    public function test_create_picker_is_bounded_to_canonical_accessible_sites(): void
    {
        $requester = $this->createUserWithRole('board_secretary');
        $this->assignSite($requester, $this->siteA);

        // The retired create page now opens the index wizard dialog.
        $this->actingAs($requester)->get('/governance/spend-approvals/create')
            ->assertRedirect('/governance/spend-approvals?create=1');
        $this->actingAs($requester)->get('/governance/spend-approvals?create=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/SpendApprovals/Index')
                ->where('can_create', true)
                ->has('form_options.sites', 1)
                ->where('form_options.sites.0.id', $this->siteA->id)
                ->where('form_options.sites.0.name', $this->siteA->name));

        $globalRequester = User::factory()->create(['approved_at' => now()]);
        $this->grant($globalRequester, [
            'governance.spend.view',
            'governance.spend.request',
            'governance.spend.viewAllSites',
        ]);
        $this->actingAs($globalRequester)->get('/governance/spend-approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_create', true)
                ->has('form_options.sites', 2)
                ->where('form_options.sites.0.id', $this->siteA->id)
                ->where('form_options.sites.1.id', $this->siteB->id));

        // View-only and empty-scope viewers never receive the picker.
        $viewer = User::factory()->create(['approved_at' => now()]);
        $this->grant($viewer, ['governance.spend.view', 'governance.spend.viewAllSites']);
        $this->actingAs($viewer)->get('/governance/spend-approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_create', false)
                ->where('form_options', null));

        $emptyScopeRequester = $this->createUserWithRole('board_secretary');
        $this->actingAs($emptyScopeRequester)->get('/governance/spend-approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_create', false)
                ->where('form_options', null));
    }

    public function test_edit_deep_link_opens_the_show_wizard_only_for_an_editable_draft(): void
    {
        $requester = $this->createUserWithRole('board_secretary');
        $approval = $this->draft($requester);

        $this->actingAs($requester)->get("/governance/spend-approvals/{$approval->id}/edit")
            ->assertRedirect("/governance/spend-approvals/{$approval->id}?edit=1");
        $this->actingAs($requester)->get("/governance/spend-approvals/{$approval->id}?edit=1")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/SpendApprovals/Show')
                ->where('authority.update', true)
                ->has('form_options.sites', 1)
                ->where('form_options.sites.0.id', $this->siteA->id)
                ->has('form_options.thresholds'));

        $otherRequester = $this->createUserWithRole('board_secretary');
        $this->assignSite($otherRequester, $this->siteA);
        $this->actingAs($otherRequester)->get("/governance/spend-approvals/{$approval->id}/edit")
            ->assertForbidden();
        $this->actingAs($otherRequester)->get("/governance/spend-approvals/{$approval->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('authority.update', false)
                ->where('form_options', null));
    }

    public function test_foreign_and_missing_approval_ids_are_concealed_without_side_effects(): void
    {
        Storage::fake('local');
        $actor = $this->createUserWithRole('board_secretary');
        $this->assignSite($actor, $this->siteA);
        $this->grant($actor, ['governance.spend.manageAny']);
        $foreignOwner = $this->createUserWithRole('board_secretary');
        $foreign = $this->draft($foreignOwner, ['site_id' => $this->siteB->id]);

        $foreignResponse = $this->actingAs($actor)->put("/governance/spend-approvals/{$foreign->id}", [
            ...$this->draftPayload('Foreign rewrite'),
            'site_id' => $this->siteB->id,
            'expected_version' => 1,
        ]);
        $missingResponse = $this->actingAs($actor)->put('/governance/spend-approvals/2147483647', [
            ...$this->draftPayload('Missing rewrite'),
            'site_id' => $this->siteA->id,
            'expected_version' => 1,
        ]);

        $foreignResponse->assertNotFound();
        $missingResponse->assertNotFound();
        $this->assertSame($missingResponse->getContent(), $foreignResponse->getContent());
        $this->actingAs($actor)->post("/governance/spend-approvals/{$foreign->id}/submit", [
            'expected_version' => 1,
        ])->assertNotFound();
        $this->actingAs($actor)->post("/governance/spend-approvals/{$foreign->id}/attachments", [
            'files' => [UploadedFile::fake()->create('foreign.txt', 1, 'text/plain')],
        ])->assertNotFound();

        $this->assertSame('Governed spend', $foreign->fresh()->title);
        $this->assertSame(SpendApproval::STATUS_DRAFT, $foreign->fresh()->status);
        $this->assertNull($foreign->fresh()->attachments);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_site_limited_decider_and_linked_parent_ids_are_concealed_without_effects(): void
    {
        $requester = $this->createUserWithRole('board_secretary');
        $foreign = $this->draft($requester, ['site_id' => $this->siteB->id]);
        $foreign->update([
            'status' => SpendApproval::STATUS_SUBMITTED,
            'submitted_by' => $requester->id,
            'submitted_at' => now(),
            'submission_version' => 2,
            'content_digest' => $foreign->decisionContentDigest(),
            'version' => 2,
        ]);
        $decider = $this->createUserWithRole('board_member');
        $this->assignSite($decider, $this->siteA);
        $this->grant($decider, ['governance.spend.view', 'governance.spend.approve']);

        $foreignResponse = $this->actingAs($decider)->post("/governance/spend-approvals/{$foreign->id}/approve", [
            ...$this->decisionPayload($foreign->fresh()),
            'decision_notes' => 'Should remain concealed.',
        ]);
        $missingResponse = $this->actingAs($decider)->post('/governance/spend-approvals/2147483647/approve', [
            ...$this->decisionPayload($foreign->fresh()),
            'decision_notes' => 'Should remain concealed.',
        ]);
        $foreignResponse->assertNotFound();
        $missingResponse->assertNotFound();
        $this->assertSame($missingResponse->getContent(), $foreignResponse->getContent());
        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $foreign->fresh()->status);
        $this->assertDatabaseMissing('spend_approval_decisions', ['spend_approval_id' => $foreign->id]);

        $costCentre = FinCostCentre::create([
            'organization_id' => 1,
            'code' => 'CC-FOREIGN',
            'name' => 'Foreign Site Cost Centre',
            'type' => 'site',
            'site_id' => $this->siteB->id,
            'is_active' => true,
            'created_by' => $decider->id,
        ]);
        $creator = $this->createUserWithRole('board_secretary');
        $this->assignSite($creator, $this->siteA);
        $before = SpendApproval::count();
        foreach ([
            ['cost_centre_id' => $costCentre->id],
            ['cost_centre_id' => 2147483647],
            ['donor_fund_id' => 2147483647],
            ['budget_line_item_id' => 2147483647],
        ] as $linkedParent) {
            try {
                app(SpendApprovalCommandService::class)->create($creator, [
                    ...$this->draftPayload('Concealed linked parent'),
                    ...$linkedParent,
                ]);
                $this->fail('Foreign and missing linked parents must be concealed.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
            $this->assertSame($before, SpendApproval::count());
        }

        $accessible = $this->submit($creator, $this->draft($creator, ['reference' => 'SA-MISSING-RESOLUTION']));
        try {
            app(SpendApprovalCommandService::class)->decide(
                $decider,
                $accessible->id,
                SpendApproval::STATUS_APPROVED,
                [...$this->decisionPayload($accessible), 'resolution_id' => 2147483647],
            );
            $this->fail('A missing decision resolution must be concealed.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $accessible->fresh()->status);
        $this->assertDatabaseMissing('spend_approval_decisions', ['spend_approval_id' => $accessible->id]);
    }

    public function test_strict_audit_failure_rolls_back_aggregate_and_decision_evidence(): void
    {
        $requester = $this->createUserWithRole('board_secretary');
        $approval = $this->draft($requester);
        $failing = new class(app(UserSiteAccessService::class)) extends SpendApprovalCommandService
        {
            protected function audit(string $action, int $approvalId, array $metadata = []): void
            {
                throw new RuntimeException('Injected strict governance audit failure.');
            }
        };

        try {
            $failing->update($requester, $approval->id, [
                ...$this->draftPayload('Must roll back'),
            ], 1);
            $this->fail('Audit failure must abort the aggregate update.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected strict governance audit failure.', $exception->getMessage());
        }
        $this->assertSame('Governed spend', $approval->fresh()->title);
        $this->assertSame(1, $approval->fresh()->version);

        $submitted = $this->submit($requester, $approval->fresh());
        $decider = $this->createAdminUser();
        $auditCount = DB::table('governance_audit_log')->count();
        try {
            $failing->decide(
                $decider,
                $submitted->id,
                SpendApproval::STATUS_APPROVED,
                $this->decisionPayload($submitted),
            );
            $this->fail('Audit failure must abort decision evidence and aggregate mutation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected strict governance audit failure.', $exception->getMessage());
        }
        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $submitted->fresh()->status);
        $this->assertSame(2, $submitted->fresh()->version);
        $this->assertDatabaseMissing('spend_approval_decisions', ['spend_approval_id' => $submitted->id]);
        $this->assertSame($auditCount, DB::table('governance_audit_log')->count());
    }

    public function test_requester_cannot_decide_and_submitted_evidence_is_immutable(): void
    {
        $requester = $this->createAdminUser();
        $approval = $this->submit($requester, $this->draft($requester));
        $payload = $this->decisionPayload($approval);

        $this->actingAs($requester)->post("/governance/spend-approvals/{$approval->id}/approve", $payload)
            ->assertForbidden();
        $this->actingAs($requester)->put("/governance/spend-approvals/{$approval->id}", [
            ...$this->draftPayload('Late rewrite'),
            'expected_version' => $approval->version,
        ])->assertForbidden();

        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $approval->fresh()->status);
        $this->assertSame($approval->content_digest, $approval->fresh()->content_digest);
        $this->assertDatabaseCount('spend_approval_decisions', 0);

        $separateSubmitter = $this->createAdminUser();
        $thirdPartyRequester = $this->createUserWithRole('board_secretary');
        $submittedByAnother = $this->draft($thirdPartyRequester, ['reference' => 'SA-SEPARATE-SUBMITTER']);
        $submittedByAnother->update([
            'status' => SpendApproval::STATUS_SUBMITTED,
            'submitted_by' => $separateSubmitter->id,
            'submitted_at' => now(),
            'submission_version' => 2,
            'content_digest' => $submittedByAnother->decisionContentDigest(),
            'version' => 2,
        ]);
        $this->actingAs($separateSubmitter)
            ->post("/governance/spend-approvals/{$submittedByAnother->id}/approve", $this->decisionPayload($submittedByAnother->fresh()))
            ->assertForbidden();
        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $submittedByAnother->fresh()->status);
    }

    public function test_stable_replay_converges_and_changed_or_stale_decisions_have_zero_partial_effects(): void
    {
        $requester = $this->createUserWithRole('board_secretary');
        $decider = $this->createAdminUser();
        $approval = $this->submit($requester, $this->draft($requester));
        $resolution = $this->createResolution($decider, [
            'status' => 'closed',
            'outcome' => 'carried',
        ]);
        $payload = [
            ...$this->decisionPayload($approval),
            'decision_notes' => 'Approved under delegated authority.',
            'resolution_id' => $resolution->id,
        ];

        $service = app(SpendApprovalCommandService::class);
        $first = $service->decide($decider, $approval->id, SpendApproval::STATUS_APPROVED, $payload);
        $decidedAt = $first->decided_at;
        $replay = $service->decide($decider, $approval->id, SpendApproval::STATUS_APPROVED, $payload);

        $this->assertSame(SpendApproval::STATUS_APPROVED, $replay->status);
        $this->assertTrue($decidedAt->equalTo($replay->decided_at));
        $this->assertSame(1, DB::table('spend_approval_decisions')->where('spend_approval_id', $approval->id)->count());
        $this->assertSame(3, $replay->version);

        $evidence = SpendApprovalDecision::where('spend_approval_id', $approval->id)->sole();
        $this->assertSame(1, $evidence->evidence_version);
        $this->assertSame($approval->submission_version, $evidence->submission_version);
        $this->assertSame($approval->content_digest, $evidence->content_digest);
        $this->assertSame(SpendApproval::STATUS_APPROVED, $evidence->outcome);
        $this->assertSame($decider->id, $evidence->decided_by);
        $this->assertNotNull($evidence->decided_at);
        $this->assertSame($resolution->id, $evidence->resolution_id);
        $this->assertSame($resolution->id, $evidence->parent_evidence['resolution']['id']);
        try {
            $evidence->update(['reason' => 'Rewritten reason']);
            $this->fail('Decision evidence must not be updateable.');
        } catch (LogicException) {
            $this->assertSame('Approved under delegated authority.', $evidence->fresh()->reason);
        }
        try {
            $evidence->delete();
            $this->fail('Decision evidence must not be deleteable.');
        } catch (LogicException) {
            $this->assertDatabaseHas('spend_approval_decisions', ['id' => $evidence->id]);
        }

        try {
            $service->decide($decider, $approval->id, SpendApproval::STATUS_REJECTED, [
                ...$payload,
                'decision_notes' => 'Changed outcome under the same key.',
            ]);
            $this->fail('Changed stable-key replay must conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision_key', $exception->errors());
        }
        $this->assertSame(SpendApproval::STATUS_APPROVED, $approval->fresh()->status);
        $this->assertSame(1, DB::table('spend_approval_decisions')->where('spend_approval_id', $approval->id)->count());

        $stale = $this->submit($requester, $this->draft($requester, ['reference' => 'SA-STALE-0001']));
        try {
            $service->decide($decider, $stale->id, SpendApproval::STATUS_APPROVED, [
                ...$this->decisionPayload($stale),
                'expected_version' => $stale->version - 1,
            ]);
            $this->fail('Stale decision must conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expected_version', $exception->errors());
        }
        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $stale->fresh()->status);
        $this->assertDatabaseMissing('spend_approval_decisions', ['spend_approval_id' => $stale->id]);

        DB::table('spend_approvals')->where('id', $stale->id)->update(['title' => 'Tampered after submit']);
        try {
            $service->decide($decider, $stale->id, SpendApproval::STATUS_APPROVED, $this->decisionPayload($stale));
            $this->fail('Changed submitted content must conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expected_content_digest', $exception->errors());
        }
        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $stale->fresh()->status);
        $this->assertDatabaseMissing('spend_approval_decisions', ['spend_approval_id' => $stale->id]);
    }

    /**
     * P1: spend that needs board sign-off is approved only with the passed
     * resolution that covers it — enforced by the command service, after the
     * replay check so a retried approval still converges.
     */
    public function test_spend_needing_board_sign_off_is_approved_only_with_a_passed_resolution_covering_it(): void
    {
        $requester = $this->createUserWithRole('board_secretary');
        $decider = $this->createAdminUser();
        $service = app(SpendApprovalCommandService::class);
        $approval = $this->submit($requester, $this->draft($requester, [
            'reference' => 'SA-BOARD-0001',
            'amount' => 60000,
            'requires_board' => true,
        ]));

        $rejectedWith = function (?int $resolutionId) use ($service, $decider, $approval): string {
            try {
                $service->decide($decider, $approval->id, SpendApproval::STATUS_APPROVED, [
                    ...$this->decisionPayload($approval),
                    ...($resolutionId ? ['resolution_id' => $resolutionId] : []),
                ]);
                $this->fail('Board-level spend was approved without a covering passed resolution.');
            } catch (ValidationException $exception) {
                return $exception->errors()['resolution_id'][0];
            }
        };

        $this->assertSame(
            'This request needs a board decision. Choose the resolution the board passed before approving it.',
            $rejectedWith(null),
        );

        $draft = $this->createResolution($decider, ['cost_impact' => ['amount' => 60000]]);
        $this->assertSame(
            "This resolution can't be used yet — voting must be finished and the result recorded as passed.",
            $rejectedWith($draft->id),
        );

        $tooSmall = $this->createResolution($decider, ['status' => 'closed', 'outcome' => 'carried', 'closed_at' => now(), 'cost_impact' => ['amount' => 50000]]);
        $this->assertSame('The board approved up to $50,000.00 but this request is for $60,000.00.', $rejectedWith($tooSmall->id));

        $noAmount = $this->createResolution($decider, ['status' => 'closed', 'outcome' => 'carried', 'closed_at' => now(), 'cost_impact' => ['is_none' => true]]);
        $this->assertStringContainsString("doesn't state the dollar amount approved", $rejectedWith($noAmount->id));

        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $approval->fresh()->status);
        $this->assertDatabaseMissing('spend_approval_decisions', ['spend_approval_id' => $approval->id]);

        $covering = $this->createResolution($decider, ['status' => 'implemented', 'outcome' => 'carried', 'closed_at' => now(), 'cost_impact' => ['amount' => 75000]]);

        $this->actingAs($decider)->get("/governance/spend-approvals/{$approval->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('site_name', 'Accessible Site')
                ->where('decision_blocked_reason', null)
                ->where('board_resolution_options', fn ($options) => collect($options)->pluck('id')->all() === [$covering->id]));

        $payload = [...$this->decisionPayload($approval), 'resolution_id' => $covering->id];
        $approved = $service->decide($decider, $approval->id, SpendApproval::STATUS_APPROVED, $payload);
        $this->assertSame(SpendApproval::STATUS_APPROVED, $approved->status);
        $this->assertSame($covering->id, $approved->resolution_id);

        // A retried approval still converges on the one decision.
        $replay = $service->decide($decider, $approval->id, SpendApproval::STATUS_APPROVED, $payload);
        $this->assertSame(SpendApproval::STATUS_APPROVED, $replay->status);
        $this->assertSame(1, DB::table('spend_approval_decisions')->where('spend_approval_id', $approval->id)->count());

        // One resolution approves one request.
        $second = $this->submit($requester, $this->draft($requester, [
            'reference' => 'SA-BOARD-0002',
            'amount' => 20000,
            'requires_board' => true,
        ]));
        try {
            $service->decide($decider, $second->id, SpendApproval::STATUS_APPROVED, [
                ...$this->decisionPayload($second),
                'resolution_id' => $covering->id,
            ]);
            $this->fail('A resolution was used to approve a second spend request.');
        } catch (ValidationException $exception) {
            $this->assertSame('This resolution has already been used to approve another spend request.', $exception->errors()['resolution_id'][0]);
        }
        $this->assertSame(SpendApproval::STATUS_SUBMITTED, $second->fresh()->status);

        // The used resolution is no longer offered; an unused passed one that
        // covers this smaller request is.
        $this->actingAs($decider)->get("/governance/spend-approvals/{$second->id}")
            ->assertInertia(fn ($page) => $page->where(
                'board_resolution_options',
                fn ($options) => collect($options)->pluck('id')->all() === [$tooSmall->id],
            ));

        // Declining never needs a resolution.
        $declined = $service->decide($decider, $second->id, SpendApproval::STATUS_REJECTED, $this->decisionPayload($second));
        $this->assertSame(SpendApproval::STATUS_REJECTED, $declined->status);

        // The requester is told why they can't decide their own request.
        $third = $this->submit($requester, $this->draft($requester, ['reference' => 'SA-BOARD-0003']));
        $this->grant($requester, ['governance.spend.approve']);
        $this->actingAs($requester)->get("/governance/spend-approvals/{$third->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('authority.decide', false)
                ->where('decision_blocked_reason', 'You requested this, so another approver must decide it.'));
    }

    private function draft(User $requester, array $overrides = []): SpendApproval
    {
        $this->assignSite($requester, $this->siteA);

        return SpendApproval::create(array_merge([
            'reference' => 'SA-'.strtoupper(Str::random(10)),
            'title' => 'Governed spend',
            'category' => SpendApproval::CATEGORY_OPEX,
            'amount' => 12000,
            'currency' => 'NZD',
            'status' => SpendApproval::STATUS_DRAFT,
            'requested_by' => $requester->id,
            'site_id' => $this->siteA->id,
            'version' => 1,
        ], $overrides));
    }

    private function submit(User $requester, SpendApproval $approval): SpendApproval
    {
        return app(SpendApprovalCommandService::class)->submit($requester, $approval->id, $approval->version);
    }

    private function draftPayload(string $title = 'Governed spend'): array
    {
        return [
            'title' => $title,
            'description' => 'Documented operational spend.',
            'category' => SpendApproval::CATEGORY_OPEX,
            'amount' => 12000,
            'currency' => 'NZD',
            'site_id' => $this->siteA->id,
        ];
    }

    private function decisionPayload(SpendApproval $approval): array
    {
        return [
            'decision_key' => (string) Str::uuid(),
            'expected_version' => $approval->version,
            'expected_content_digest' => $approval->content_digest,
            'decision_notes' => 'Documented governance decision.',
        ];
    }

    private function assignSite(User $user, Site $primarySite, array $secondarySites = []): void
    {
        HrEmployeeProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'employee_number' => 'GOV-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                'work_email' => $user->email,
                'position_title' => 'Governance Tester',
                'position_role' => 'governance',
                'employment_type' => 'full_time',
                'contract_type' => 'individual',
                'start_date' => now()->subYear()->toDateString(),
                'primary_site_id' => $primarySite->id,
                'secondary_site_ids' => collect($secondarySites)->map(fn (Site $site) => $site->id)->all(),
                'is_active' => true,
                'end_date' => null,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ],
        );
        $user->unsetRelation('hrEmployeeProfile');
    }

    private function grant(User $user, array $keys): void
    {
        $permissions = Permission::query()->whereIn('key', $keys)->pluck('id');
        foreach ($permissions as $permissionId) {
            $user->permissionOverrides()->syncWithoutDetaching([$permissionId => ['allowed' => true]]);
        }
        $user->unsetRelation('permissionOverrides');
        $user->unsetRelation('roles');
    }
}
