<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCostCentre;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\FleetFinanceReviewRequest;
use App\Models\FleetVehicleFinanceLink;
use App\Models\FleetVehicleReminder;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use App\Services\Fleet\VehicleCalendarService;
use App\Services\Fleet\VehicleFinancePresenter;
use App\Services\Fleet\VehicleWorkspacePresenter;
use App\Services\Sites\Calendar\Providers\FleetVehicleReminderObligationProvider;
use App\Services\Tasks\TaskAggregator;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * PKG-02B vehicle Overview › Finance: records linked by Finance or from the
 * vehicle, Finance review requests with private supporting files, Finance
 * decisions and the Finance queue in All Tasks.
 */
class Pkg02bVehicleFinanceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreignSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Storage::fake('private');
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Pacific/Auckland')->utc());
        $this->site = Site::factory()->create(['name' => 'Kōwhai House']);
        $this->foreignSite = Site::factory()->create(['name' => 'Rimu House']);
        // No scanning binary is configured in tests; every upload scans clean.
        $this->app->instance(MalwareScanner::class, new class extends MalwareScanner
        {
            public function scanPath(string $path, array $settings): MalwareScanResult
            {
                return new MalwareScanResult(MalwareScanDisposition::Clean, 'test-scanner', null);
            }
        });
    }

    public function test_the_finance_view_is_concealed_without_finance_access(): void
    {
        $vehicle = $this->vehicle($this->site);
        $foreign = $this->vehicle($this->foreignSite);
        $fixed = $this->fixedAsset(['linked_asset_id' => $vehicle->id, 'asset_tag' => 'FA-2201']);
        $bill = $this->bill(['asset_id' => $vehicle->id, 'site_id' => $this->site->id, 'bill_number' => 'BILL-202609-004']);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $assetsOnly = $this->siteUser([$this->site], ['fleet.viewAny', 'finance.assets.view']);
        $finance = $this->siteUser([$this->site], ['fleet.viewAny', 'finance.assets.view', 'finance.ap.view']);

        $hidden = $this->present($manager, $vehicle);
        $this->assertFalse($hidden['can']['view']);
        $this->assertSame([], $hidden['records']);
        $this->assertSame([], $hidden['requests']);
        $this->assertNull($hidden['fixed_asset']);

        // Fixed assets need finance.assets.view; accounts payable identity and amounts need finance.ap.view.
        $partial = $this->present($assetsOnly, $vehicle);
        $rows = collect($partial['records'])->keyBy('key');
        $this->assertSame('FA-2201', $partial['fixed_asset']['label']);
        $this->assertSame('/finance/fixed-assets/'.$fixed->id, $rows['fixed_asset-'.$fixed->id]['href']);
        $this->assertTrue($rows['bill-'.$bill->id]['restricted']);
        $this->assertNull($rows['bill-'.$bill->id]['reference']);
        $this->assertNull($rows['bill-'.$bill->id]['amount']);
        $this->assertNull($rows['bill-'.$bill->id]['href']);
        $this->assertFalse($partial['can']['link']);
        $this->assertFalse($partial['can']['request_review']);
        $this->assertSame([], $partial['sources']);

        $full = collect($this->present($finance, $vehicle)['records'])->keyBy('key');
        $this->assertFalse($full['bill-'.$bill->id]['restricted']);
        $this->assertSame('BILL-202609-004', $full['bill-'.$bill->id]['reference']);
        $this->assertSame(408.25, $full['bill-'.$bill->id]['amount']);
        $this->assertSame('/finance/bills/'.$bill->id, $full['bill-'.$bill->id]['href']);

        $this->expectException(NotFoundHttpException::class);
        $this->present($finance, $foreign);
    }

    public function test_linked_records_combine_finance_and_vehicle_links_without_double_counting(): void
    {
        $vehicle = $this->vehicle($this->site);
        $unassigned = $this->vehicle($this->foreignSite);
        $finance = $this->siteUser([$this->site, $this->foreignSite], ['fleet.viewAny', 'finance.assets.view', 'finance.ap.view']);
        $fixed = $this->fixedAsset(['linked_asset_id' => $vehicle->id]);
        $order = $this->purchaseOrder(['status' => 'approved']);
        $bill = $this->bill([
            'asset_id' => $vehicle->id, 'site_id' => $this->site->id, 'purchase_order_id' => $order->id, 'status' => 'approved',
        ]);
        // The invoice Finance recorded against the vehicle is also linked from it, with the order it came from.
        $this->link($vehicle, 'bill', $bill->id);
        $this->link($vehicle, 'purchase_order', $order->id);
        $centre = FinCostCentre::query()->create([
            'organization_id' => 1, 'code' => 'CC-KOWHAI', 'name' => 'Kōwhai House', 'type' => 'site',
            'site_id' => $this->site->id, 'is_active' => true,
        ]);

        $view = $this->present($finance, $vehicle);
        $this->assertSame(
            ['fixed_asset-'.$fixed->id, 'purchase_order-'.$order->id, 'bill-'.$bill->id],
            collect($view['records'])->pluck('key')->all(),
        );
        $this->assertSame(3, $view['records_total']);
        $rows = collect($view['records'])->keyBy('key');
        $this->assertSame('both', $rows['bill-'.$bill->id]['basis']);
        $this->assertFalse($rows['bill-'.$bill->id]['can_unlink']);
        $this->assertSame('Awaiting payment', $rows['bill-'.$bill->id]['status_label']);
        $this->assertStringContainsString('Raised from purchase order', (string) $rows['bill-'.$bill->id]['detail']);
        $this->assertSame('Approved', $rows['purchase_order-'.$order->id]['status_label']);
        $this->assertSame('vehicle', $rows['purchase_order-'.$order->id]['basis']);
        // Each stage keeps its own amount and nothing adds them together.
        $this->assertSame([408.25, 408.25], [$rows['purchase_order-'.$order->id]['amount'], $rows['bill-'.$bill->id]['amount']]);
        $this->assertSame(['can', 'site_restricted', 'fixed_asset', 'cost_centre', 'pending_requests', 'records', 'records_total', 'requests',
            'request_types', 'sources', 'documents', 'link_state', 'as_of'], array_keys($view));
        $this->assertFalse($view['site_restricted']);
        $this->assertSame(['id' => $centre->id, 'code' => 'CC-KOWHAI', 'name' => 'Kōwhai House', 'active' => true], $view['cost_centre']);
        $this->assertSame($fixed->id, $view['link_state']['finance_fixed_asset']['id']);
        $this->assertNull($view['link_state']['vehicle_fixed_asset']);

        $this->assertNull($this->present($finance, $unassigned)['cost_centre']);
    }

    public function test_linking_needs_fleet_management_finance_access_a_reason_and_the_vehicles_scope(): void
    {
        $vehicle = $this->vehicle($this->site);
        $foreign = $this->vehicle($this->foreignSite);
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny', 'finance.assets.view', 'finance.ap.view']);
        $assetsManager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'finance.assets.view']);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'finance.assets.view', 'finance.ap.view']);
        $bill = $this->bill(['site_id' => $this->site->id]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/finance/links";
        $payload = ['record_type' => 'bill', 'record_id' => $bill->id, 'reason' => 'Harbour Workshop service invoice'];

        $this->actingAs($viewer)->postJson($url, $payload, ['Idempotency-Key' => 'link-0'])->assertForbidden();
        $this->actingAs($assetsManager)->postJson($url, $payload, ['Idempotency-Key' => 'link-1'])->assertForbidden();
        $this->actingAs($manager)->postJson($url, ['record_type' => 'bill', 'record_id' => $bill->id], ['Idempotency-Key' => 'link-2'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'Record the reason for this change.']);
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$foreign->id}/finance/links", $payload, ['Idempotency-Key' => 'link-3'])
            ->assertNotFound();
        $otherSite = $this->bill(['site_id' => $this->foreignSite->id]);
        $this->actingAs($manager)->postJson($url, [...$payload, 'record_id' => $otherSite->id], ['Idempotency-Key' => 'link-4'])
            ->assertUnprocessable()->assertJsonValidationErrors('record_id');
        $otherVehicle = $this->bill(['site_id' => $this->site->id, 'asset_id' => $foreign->id]);
        $this->actingAs($manager)->postJson($url, [...$payload, 'record_id' => $otherVehicle->id], ['Idempotency-Key' => 'link-5'])
            ->assertUnprocessable()->assertJsonValidationErrors(['record_id' => 'Finance records this invoice against another vehicle or asset.']);

        $first = $this->actingAs($manager)->postJson($url, $payload, ['Idempotency-Key' => 'link-6'])->assertOk()->json('links');
        $this->actingAs($manager)->postJson($url, $payload, ['Idempotency-Key' => 'link-6'])
            ->assertOk()->assertJsonPath('links.0.id', $first[0]['id']);
        $this->assertSame(1, FleetVehicleFinanceLink::query()->count());
        $this->actingAs($manager)->postJson($url, [...$payload, 'reason' => 'Changed my mind'], ['Idempotency-Key' => 'link-6'])
            ->assertStatus(409);
        $this->actingAs($manager)->postJson($url, $payload, ['Idempotency-Key' => 'link-7'])
            ->assertUnprocessable()->assertJsonValidationErrors(['record_id' => 'This record is already linked to the vehicle.']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fleet.vehicle.finance.link', 'auditable_id' => $vehicle->id]);

        // A fixed asset linked from the vehicle can be replaced; the earlier link stays as history.
        $registerEntry = $this->fixedAsset();
        $replacement = $this->fixedAsset();
        $elsewhere = $this->fixedAsset(['linked_asset_id' => $foreign->id]);
        $this->actingAs($assetsManager)->postJson($url, ['fixed_asset_id' => $elsewhere->id, 'reason' => 'Purchase confirmed'], ['Idempotency-Key' => 'fa-0'])
            ->assertUnprocessable()->assertJsonValidationErrors('fixed_asset_id');
        $this->actingAs($assetsManager)->postJson($url, ['fixed_asset_id' => $registerEntry->id, 'reason' => 'Purchase confirmed'], ['Idempotency-Key' => 'fa-1'])
            ->assertOk();
        $this->actingAs($assetsManager)->postJson($url, ['fixed_asset_id' => $replacement->id, 'reason' => 'Wrong register entry'], ['Idempotency-Key' => 'fa-2'])
            ->assertOk()->assertJsonCount(2, 'links');
        $previous = FleetVehicleFinanceLink::query()->where('record_type', 'fixed_asset')->where('record_id', $registerEntry->id)->sole();
        $this->assertNotNull($previous->unlinked_at);
        $this->assertNull($previous->active_slot);
        $this->assertSame('Wrong register entry', $previous->unlink_reason);
        $this->assertTrue(FleetVehicleFinanceLink::query()->where('record_type', 'fixed_asset')
            ->where('record_id', $replacement->id)->whereNull('unlinked_at')->exists());
        $this->assertSame($replacement->id, $this->present($manager, $vehicle)['link_state']['vehicle_fixed_asset']['id']);

        // Another vehicle cannot claim the same fixed asset, and Finance's own link is not overridden.
        $sibling = $this->vehicle($this->site);
        $this->actingAs($assetsManager)->postJson("/fleet-assets/vehicles/{$sibling->id}/finance/links",
            ['fixed_asset_id' => $replacement->id, 'reason' => 'Same van'], ['Idempotency-Key' => 'fa-3'])
            ->assertUnprocessable()->assertJsonValidationErrors(['fixed_asset_id' => 'This fixed asset is already linked to another vehicle.']);
        $this->fixedAsset(['linked_asset_id' => $sibling->id]);
        $this->actingAs($assetsManager)->postJson("/fleet-assets/vehicles/{$sibling->id}/finance/links",
            ['fixed_asset_id' => $registerEntry->id, 'reason' => 'Override'], ['Idempotency-Key' => 'fa-4'])
            ->assertUnprocessable()->assertJsonValidationErrors('fixed_asset_id');
    }

    public function test_unlinking_keeps_the_finance_record_and_needs_a_reason(): void
    {
        $vehicle = $this->vehicle($this->site);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'finance.assets.view', 'finance.ap.view']);
        $order = $this->purchaseOrder();
        $link = $this->link($vehicle, 'purchase_order', $order->id, $manager);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/finance/links/{$link->id}/unlink";

        $this->assertTrue(collect($this->present($manager, $vehicle)['records'])->firstWhere('key', 'purchase_order-'.$order->id)['can_unlink']);
        $this->actingAs($manager)->postJson($url, ['reason' => '  '], ['Idempotency-Key' => 'unlink-1'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'Record why this link is removed.']);
        $this->actingAs($manager)->postJson($url, ['reason' => 'Order raised for another van'], ['Idempotency-Key' => 'unlink-2'])
            ->assertOk()->assertJsonPath('link.active', false);
        $this->actingAs($manager)->postJson($url, ['reason' => 'Order raised for another van'], ['Idempotency-Key' => 'unlink-2'])
            ->assertOk()->assertJsonPath('link.active', false);
        $this->actingAs($manager)->postJson($url, ['reason' => 'Again'], ['Idempotency-Key' => 'unlink-3'])->assertStatus(409);
        $this->assertNotNull(FinPurchaseOrder::query()->find($order->id));
        $this->assertSame([], $this->present($manager, $vehicle)['records']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fleet.vehicle.finance.unlink', 'auditable_id' => $vehicle->id]);

        // The removed link stays as history and the record can be linked again.
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/finance/links",
            ['record_type' => 'purchase_order', 'record_id' => $order->id, 'reason' => 'Correct van after all'], ['Idempotency-Key' => 'relink'])
            ->assertOk();
        $this->assertSame(2, FleetVehicleFinanceLink::query()->where('record_id', $order->id)->count());

        // Finance's own connection cannot be removed from the vehicle.
        $bill = $this->bill(['asset_id' => $vehicle->id, 'site_id' => $this->site->id]);
        $row = collect($this->present($manager, $vehicle)['records'])->firstWhere('key', 'bill-'.$bill->id);
        $this->assertSame('finance', $row['basis']);
        $this->assertNull($row['link_id']);
        $this->assertFalse($row['can_unlink']);
    }

    public function test_linkable_search_is_scoped_to_the_vehicle_and_finance_access(): void
    {
        $vehicle = $this->vehicle($this->site);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'finance.assets.view', 'finance.ap.view']);
        $assetsManager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'finance.assets.view']);
        $vendor = FinVendor::factory()->create(['name' => 'Harbour Workshop']);
        $siteBill = $this->bill(['site_id' => $this->site->id, 'vendor_id' => $vendor->id]);
        $this->bill(['site_id' => $this->foreignSite->id]);
        $this->bill(['site_id' => $this->site->id, 'asset_id' => $this->vehicle($this->site)->id]);
        $this->bill(['site_id' => $this->site->id, 'status' => 'cancelled']);
        $centre = FinCostCentre::query()->create([
            'organization_id' => 1, 'code' => 'CC-KOWHAI', 'name' => 'Kōwhai House', 'type' => 'site',
            'site_id' => $this->site->id, 'is_active' => true,
        ]);
        $siteOrder = $this->purchaseOrder(['cost_centre_id' => $centre->id]);
        $otherOrder = $this->purchaseOrder(['po_number' => 'PO-202609-077']);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/finance/linkable";
        $ids = fn (array $results): array => collect($results)->pluck('id')->all();

        $this->actingAs($manager)->getJson($url.'?type=bill')->assertOk()
            ->assertJsonPath('results', fn (array $results): bool => $ids($results) === [$siteBill->id]);
        $this->actingAs($manager)->getJson($url.'?type=bill&q=harbour')->assertOk()->assertJsonCount(1, 'results');
        $this->actingAs($manager)->getJson($url.'?type=purchase_order')->assertOk()
            ->assertJsonPath('scope', 'site_cost_centre')
            ->assertJsonPath('results', fn (array $results): bool => $ids($results) === [$siteOrder->id]);
        $this->actingAs($manager)->getJson($url.'?type=purchase_order&q=PO-202609-077')->assertOk()
            ->assertJsonPath('results', fn (array $results): bool => $ids($results) === [$otherOrder->id]);
        $this->actingAs($assetsManager)->getJson($url.'?type=bill')->assertForbidden();
        $this->actingAs($assetsManager)->getJson($url.'?type=payment_run')->assertNotFound();

        $this->fixedAsset(['linked_asset_id' => $this->vehicle($this->site)->id]);
        $free = $this->fixedAsset(['asset_name' => 'Kōwhai van register entry']);
        $this->actingAs($assetsManager)->getJson($url.'?type=fixed_asset')->assertOk()
            ->assertJsonPath('results', fn (array $results): bool => $ids($results) === [$free->id]);
    }

    public function test_review_requests_are_idempotent_scoped_and_refuse_open_duplicates(): void
    {
        $vehicle = $this->vehicle($this->site);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'finance.assets.view', 'finance.ap.view']);
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny', 'finance.assets.view']);
        $work = FleetWorkOrder::factory()->create([
            'asset_id' => $vehicle->id, 'title' => 'Routine service', 'status' => 'completed', 'priority' => 'medium',
        ]);
        $foreignWork = FleetWorkOrder::factory()->create([
            'asset_id' => $this->vehicle($this->foreignSite)->id, 'status' => 'completed', 'priority' => 'medium',
        ]);
        $bill = $this->bill(['asset_id' => $vehicle->id, 'site_id' => $this->site->id, 'bill_number' => 'BILL-202609-004']);
        $unlinked = $this->bill(['site_id' => $this->site->id]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/finance/review-requests";
        $payload = [
            'request_type' => 'supplier_invoice_review', 'source' => 'work_order:'.$work->id,
            'amount' => '408.25', 'note' => 'The invoice is $50 above the approved quote.',
        ];

        $this->actingAs($viewer)->postJson($url, $payload, ['Idempotency-Key' => 'frq-0'])->assertForbidden();
        $created = $this->actingAs($manager)->postJson($url, $payload, ['Idempotency-Key' => 'frq-1'])->assertOk()
            ->assertJsonPath('request.reference', 'FRQ-2026-0001')
            ->assertJsonPath('request.status', 'submitted')
            ->json('request');
        $this->actingAs($manager)->postJson($url, $payload, ['Idempotency-Key' => 'frq-1'])
            ->assertOk()->assertJsonPath('request.id', $created['id']);
        $this->actingAs($manager)->postJson($url, [...$payload, 'amount' => '999.00'], ['Idempotency-Key' => 'frq-1'])
            ->assertStatus(409);
        $this->actingAs($manager)->postJson($url, $payload, ['Idempotency-Key' => 'frq-2'])->assertUnprocessable()
            ->assertJsonValidationErrors(['request_type' => 'An open request already exists for this source and request type.']);
        $this->assertSame(1, FleetFinanceReviewRequest::query()->count());

        $request = FleetFinanceReviewRequest::query()->sole();
        $this->assertStringEndsWith('Routine service', $request->source_label);
        $this->assertSame('408.25', $request->amount);
        $this->assertSame(['submitted'], $request->events()->pluck('action')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'fleet.vehicle.finance.review_request', 'auditable_id' => $vehicle->id]);

        foreach ([
            ['source' => 'work_order:'.$foreignWork->id],
            ['source' => 'bill:'.$unlinked->id],
            ['source' => 'fleet'],
            ['amount' => '-5'],
            ['amount' => '10.555'],
            ['note' => ''],
            ['request_type' => 'payment_run'],
        ] as $index => $invalid) {
            $this->actingAs($manager)->postJson($url, [...$payload, 'request_type' => 'purchase_approval', ...$invalid], ['Idempotency-Key' => 'frq-bad-'.$index])
                ->assertUnprocessable();
        }

        // A Finance record connected to the vehicle can be the source.
        $this->actingAs($manager)->postJson($url, [...$payload, 'source' => 'bill:'.$bill->id], ['Idempotency-Key' => 'frq-3'])->assertOk();
        $view = $this->present($manager, $vehicle);
        $this->assertSame(2, $view['pending_requests']);
        $this->assertSame('Pending Finance review', $view['requests'][0]['status_label']);
        $sources = collect($view['sources'])->pluck('value')->all();
        $this->assertContains('vehicle', $sources);
        $this->assertContains('work_order:'.$work->id, $sources);
        $this->assertContains('bill:'.$bill->id, $sources);
        $this->assertNotContains('work_order:'.$foreignWork->id, $sources);

        // Without accounts payable access the invoice behind a request is not named.
        $masked = collect($this->present($viewer, $vehicle)['requests'])->firstWhere('source.type', 'bill');
        $this->assertSame('Supplier invoice · accounts payable access needed', $masked['source']['label']);
    }

    public function test_review_request_files_are_private_and_open_only_for_finance_viewers(): void
    {
        $vehicle = $this->vehicle($this->site);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.viewAny', 'assets.documents.manage', 'finance.assets.view']);
        $assetViewer = $this->siteUser([$this->site], ['fleet.viewAny', 'assets.viewAny']);
        $requests = "/fleet-assets/vehicles/{$vehicle->id}/finance/review-requests";
        $documents = "/fleet-assets/vehicles/{$vehicle->id}/documents";
        $general = $this->actingAs($manager)->post($documents, [
            'category' => 'Invoice', 'document_date' => '2026-09-20', 'reason' => 'Workshop invoice',
            'request_key' => 'general-document', 'files' => [$this->pdf('workshop-invoice.pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->json('files.0');

        $request = $this->actingAs($manager)->postJson($requests, [
            'request_type' => 'purchase_approval', 'source' => 'vehicle', 'note' => 'Quote for four new tyres.',
        ], ['Idempotency-Key' => 'frq-files'])->assertOk()->json('request');
        $upload = $this->actingAs($manager)->post($documents, [
            'category' => 'Purchase approval', 'document_date' => '2026-09-23', 'reason' => 'Supporting files for '.$request['reference'],
            'source_type' => 'finance_review_request', 'source_id' => $request['id'], 'request_key' => 'frq-files-upload',
            'files' => [$this->pdf('tyre-quote.pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->json('files.0');
        $this->assertSame('available', $upload['state']);

        // Request evidence stays out of the vehicle's document library and cannot be re-used as "existing".
        $library = app(VehicleWorkspacePresenter::class)->present(User::query()->findOrFail($manager->id), $vehicle, false)['documents'];
        $this->assertSame(['workshop-invoice.pdf'], collect($library)->flatMap(fn (array $set): array => array_column($set['files'], 'name'))->all());
        $this->assertSame([$general['id']], collect($this->present($manager, $vehicle)['documents'])->pluck('id')->all());
        $this->actingAs($manager)->postJson($requests, [
            'request_type' => 'supplier_invoice_review', 'source' => 'vehicle', 'note' => 'Check this invoice.', 'existing_document_id' => $upload['id'],
        ], ['Idempotency-Key' => 'frq-bad-document'])->assertUnprocessable()->assertJsonValidationErrors('existing_document_id');
        $this->actingAs($manager)->postJson($requests, [
            'request_type' => 'supplier_invoice_review', 'source' => 'vehicle', 'note' => 'Check this invoice.', 'existing_document_id' => $general['id'],
        ], ['Idempotency-Key' => 'frq-document'])->assertOk();

        $view = collect($this->present($manager, $vehicle)['requests'])->keyBy('id');
        $file = collect($view[$request['id']]['files'])->sole();
        $this->assertSame('tyre-quote.pdf', $file['name']);
        $this->assertNotNull($file['url']);
        $this->assertSame(['workshop-invoice.pdf'], array_column($view->firstWhere('type', 'supplier_invoice_review')['files'], 'name'));
        $this->actingAs($manager)->get($file['url'])->assertOk();
        $this->actingAs($assetViewer)->get("{$documents}/{$upload['id']}/file")->assertNotFound();
        // The older asset-register download route applies the same Finance rule.
        $this->actingAs($assetViewer)->get("/assets/{$vehicle->id}/documents/{$upload['id']}/download")->assertNotFound();
        $this->actingAs($manager)->get("/assets/{$vehicle->id}/documents/{$upload['id']}/download")->assertOk();

        // Managing asset files does not grant access to a linked Finance record.
        $documentManager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.viewAny', 'assets.documents.manage']);
        $setId = AssetDocument::query()->findOrFail($upload['id'])->document_set_id;
        $fileUrl = "/fleet-assets/vehicles/{$vehicle->id}/document-files/{$upload['id']}";
        $details = ['category' => 'Purchase approval', 'document_date' => '2026-09-23', 'reason' => 'Change evidence details', 'expected_version' => 1];
        $this->actingAs($documentManager)->post($documents, [
            ...$details, 'source_type' => 'finance_review_request', 'source_id' => $request['id'],
            'request_key' => 'finance-files-denied', 'files' => [$this->pdf('denied.pdf')],
        ], ['Accept' => 'application/json'])->assertNotFound();
        $this->actingAs($documentManager)->putJson("{$documents}/{$setId}", $details, ['Idempotency-Key' => 'finance-edit-denied'])->assertNotFound();
        $this->actingAs($documentManager)->post("{$documents}/{$setId}/revisions", [
            ...$details, 'request_key' => 'finance-replace-denied', 'files' => [$this->pdf('replacement.pdf')],
        ], ['Accept' => 'application/json'])->assertNotFound();
        $this->actingAs($documentManager)->postJson("{$fileUrl}/archive", ['reason' => 'Remove evidence'], ['Idempotency-Key' => 'finance-archive-denied'])->assertNotFound();
        $this->actingAs($documentManager)->postJson("{$fileUrl}/retry")->assertNotFound();

        // A follow-up cannot disclose the same restricted evidence through another surface.
        $reminder = FleetVehicleReminder::query()->create([
            'asset_id' => $vehicle->id, 'title' => 'Private quote follow-up', 'action_text' => 'Confidential invoice query',
            'source_type' => 'document_set', 'source_id' => $setId, 'due_at' => now()->addDay(),
            'state' => 'scheduled', 'repeat_months' => 0, 'lock_version' => 1, 'owner_user_id' => $manager->id,
            'created_by_user_id' => $manager->id, 'request_key' => 'private-finance-reminder', 'request_fingerprint' => str_repeat('a', 64),
        ]);
        foreach ([[$manager, true], [$documentManager, false]] as [$viewer, $visible]) {
            $this->actingAs($viewer);
            $workspace = app(VehicleWorkspacePresenter::class)->present($viewer, $vehicle, false);
            $this->assertSame($visible, collect($workspace['reminders'])->contains('id', $reminder->id));
            $events = app(VehicleCalendarService::class)->events($viewer, $vehicle,
                CarbonImmutable::now(), CarbonImmutable::now()->addDays(3));
            $this->assertSame($visible, collect($events)->contains('id', 'reminder:'.$reminder->id));
            $this->assertSame($visible, collect((new TaskAggregator)->itemsFor($viewer, []))->contains('id', 'fleet_vehicle_reminder-'.$reminder->id));
            $siteEvents = (new FleetVehicleReminderObligationProvider)->obligations(
                [$this->site->id], Carbon::now(), Carbon::now()->addDays(3));
            $this->assertSame($visible, collect($siteEvents)->contains('id', 'fleet-reminder-'.$reminder->id));
        }
        $this->actingAs($documentManager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/reminders/{$reminder->id}/acknowledge",
            ['note' => 'Guess the private source', 'expected_version' => 1], ['Idempotency-Key' => 'private-reminder-denied'])->assertNotFound();

        // Once Finance has decided, its original supporting evidence stays intact.
        FleetFinanceReviewRequest::query()->whereKey($request['id'])->update(['status' => 'resolved']);
        $this->actingAs($manager)->putJson("{$documents}/{$setId}", $details, ['Idempotency-Key' => 'finance-edit-closed'])->assertUnprocessable()->assertJsonValidationErrors('source_id');
        $this->actingAs($manager)->postJson("{$fileUrl}/archive", ['reason' => 'Remove evidence'], ['Idempotency-Key' => 'finance-archive-closed'])->assertUnprocessable()->assertJsonValidationErrors('source_id');
        $this->actingAs($manager)->post($documents, [
            'category' => 'Purchase approval', 'document_date' => '2026-09-23', 'reason' => 'Late file',
            'source_type' => 'finance_review_request', 'source_id' => $request['id'], 'request_key' => 'frq-files-late',
            'files' => [$this->pdf('late.pdf')],
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('source_id');
    }

    public function test_only_finance_decides_a_review_request(): void
    {
        $vehicle = $this->vehicle($this->site);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'finance.assets.view']);
        $finance = $this->siteUser([$this->site], ['fleet.viewAny', 'finance.assets.view', 'finance.ap.view', 'finance.ap.manage']);
        $remoteFinance = $this->siteUser([$this->foreignSite], ['fleet.viewAny', 'finance.assets.view', 'finance.ap.manage']);
        $request = $this->reviewRequest($manager, $vehicle);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/finance/review-requests/{$request['id']}/decision";
        $decision = ['decision' => 'resolved', 'note' => 'Credit note requested from the supplier.', 'expected_version' => 1];

        $this->assertFalse($this->present($manager, $vehicle)['requests'][0]['can_decide']);
        $this->assertTrue($this->present($finance, $vehicle)['requests'][0]['can_decide']);
        $this->actingAs($manager)->postJson($url, $decision, ['Idempotency-Key' => 'decision-0'])->assertForbidden();
        $this->actingAs($remoteFinance)->postJson($url, $decision, ['Idempotency-Key' => 'decision-1'])->assertNotFound();
        $this->actingAs($finance)->postJson($url, [...$decision, 'note' => ''], ['Idempotency-Key' => 'decision-2'])
            ->assertUnprocessable()->assertJsonValidationErrors(['note' => 'Record the Finance decision and any next step.']);
        $this->actingAs($finance)->postJson($url, [...$decision, 'decision' => 'approved'], ['Idempotency-Key' => 'decision-3'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->actingAs($finance)->postJson($url, [...$decision, 'expected_version' => 5], ['Idempotency-Key' => 'decision-4'])
            ->assertStatus(409);
        $this->actingAs($finance)->postJson($url, $decision, ['Idempotency-Key' => 'decision-5'])
            ->assertOk()->assertJsonPath('request.status', 'resolved')->assertJsonPath('request.lock_version', 2);
        $this->actingAs($finance)->postJson($url, $decision, ['Idempotency-Key' => 'decision-5'])
            ->assertOk()->assertJsonPath('request.status', 'resolved');
        $this->actingAs($finance)->postJson($url, [...$decision, 'decision' => 'declined', 'expected_version' => 2], ['Idempotency-Key' => 'decision-6'])
            ->assertStatus(409);

        $stored = FleetFinanceReviewRequest::query()->findOrFail($request['id']);
        $this->assertSame($finance->id, $stored->decided_by_user_id);
        $this->assertSame('Credit note requested from the supplier.', $stored->decision_note);
        $this->assertSame(['submitted', 'resolved'], $stored->events()->orderBy('id')->pluck('action')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'fleet.vehicle.finance.review_decision', 'auditable_id' => $vehicle->id]);
        $view = $this->present($finance, $vehicle);
        $this->assertSame(0, $view['pending_requests']);
        $this->assertSame('Resolved', $view['requests'][0]['status_label']);
        $this->assertFalse($view['requests'][0]['can_decide']);
        $this->assertSame(['Submitted to Finance', 'Resolved by Finance'], array_column($view['requests'][0]['history'], 'label'));
    }

    public function test_the_person_who_asked_for_a_review_cannot_decide_it(): void
    {
        $vehicle = $this->vehicle($this->site);
        $requester = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'finance.assets.view', 'finance.ap.view', 'finance.ap.manage']);
        $finance = $this->siteUser([$this->site], ['fleet.viewAny', 'finance.assets.view', 'finance.ap.view', 'finance.ap.manage']);
        $request = $this->reviewRequest($requester, $vehicle);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/finance/review-requests/{$request['id']}/decision";
        $decision = ['decision' => 'resolved', 'note' => 'Quote and invoice match.', 'expected_version' => 1];

        $this->assertFalse($this->present($requester, $vehicle)['requests'][0]['can_decide']);
        $this->actingAs($requester)->postJson($url, $decision, ['Idempotency-Key' => 'own-review-decision'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['decision' => 'Someone other than the person who asked for this review must decide it.']);
        $this->assertSame('submitted', FleetFinanceReviewRequest::query()->findOrFail($request['id'])->status);

        $this->assertTrue($this->present($finance, $vehicle)['requests'][0]['can_decide']);
        $this->actingAs($finance)->postJson($url, $decision, ['Idempotency-Key' => 'other-review-decision'])
            ->assertOk()->assertJsonPath('request.status', 'resolved');
    }

    public function test_finance_decides_review_requests_in_finance_without_fleet_access(): void
    {
        $vehicle = $this->vehicle($this->site);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.viewAny', 'assets.documents.manage', 'finance.assets.view']);
        $finance = $this->siteUser([$this->site], ['finance.assets.view', 'finance.ap.view', 'finance.ap.manage']);
        $viewOnly = $this->siteUser([$this->site], ['finance.assets.view']);
        $fleetOnly = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $remote = $this->siteUser([$this->foreignSite], ['finance.assets.view', 'finance.ap.manage']);
        $central = $this->siteUser([$this->foreignSite], ['finance.assets.view', 'finance.ap.manage', 'finance.insights.viewAllSites']);
        $request = $this->reviewRequest($manager, $vehicle);
        $upload = $this->actingAs($manager)->post("/fleet-assets/vehicles/{$vehicle->id}/documents", [
            'category' => 'Supplier invoice review', 'document_date' => '2026-09-23', 'reason' => 'Invoice for '.$request['reference'],
            'source_type' => 'finance_review_request', 'source_id' => $request['id'], 'request_key' => 'finance-page-file',
            'files' => [$this->pdf('service-invoice.pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->json('files.0');

        // Finance is told where to decide it; the requester and other Sites aren't.
        $notified = fn (User $user): bool => DB::table('notifications')->where('notifiable_id', $user->id)->pluck('data')
            ->contains(fn (string $data): bool => (json_decode($data, true)['url'] ?? null) === '/finance/vehicle-reviews?request='.$request['id']);
        $this->assertTrue($notified($finance));
        $this->assertTrue($notified($central));
        $this->assertFalse($notified($remote));
        $this->assertFalse($notified($manager));

        // Finance › Vehicle reviews lists it for Finance at the vehicle's Site, or with all-Sites Finance access.
        $this->actingAs($finance)->get('/finance/vehicle-reviews')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('finance/vehicle-reviews/index')
                ->where('summary.open', 1)
                ->where('requests.0.id', $request['id'])
                ->where('requests.0.can_decide', true)
                ->where('requests.0.vehicle.url', null)
                ->where('requests.0.files.0.name', 'service-invoice.pdf')
                ->where('can.decide', true)
                ->etc());
        $this->actingAs($central)->get('/finance/vehicle-reviews')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('requests.0.id', $request['id'])->etc());
        $this->actingAs($remote)->get('/finance/vehicle-reviews')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('requests', [])->where('summary.open', 0)->etc());
        $this->actingAs($viewOnly)->get('/finance/vehicle-reviews')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('requests.0.can_decide', false)->where('can.decide', false)->etc());
        $this->actingAs($fleetOnly)->get('/finance/vehicle-reviews')->assertForbidden();

        // Its evidence opens from Finance, sandboxed; other documents don't.
        $this->actingAs($finance)->get("/finance/vehicle-reviews/{$request['id']}/files/{$upload['id']}")->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = $this->actingAs($manager)->post("/fleet-assets/vehicles/{$vehicle->id}/documents", [
            'category' => 'Invoice', 'document_date' => '2026-09-20', 'reason' => 'Unrelated invoice',
            'request_key' => 'finance-page-other', 'files' => [$this->pdf('unrelated.pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->json('files.0');
        $this->actingAs($finance)->get("/finance/vehicle-reviews/{$request['id']}/files/{$other['id']}")->assertNotFound();
        $this->actingAs($remote)->get("/finance/vehicle-reviews/{$request['id']}/files/{$upload['id']}")->assertNotFound();

        // A request opened from All Tasks shows whatever the filter.
        $this->actingAs($finance)->get("/finance/vehicle-reviews?status=decided&request={$request['id']}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('requests', [])->where('focus.id', $request['id'])->etc());

        // Deciding: a note is required, only Finance with authority at the Site decides, once.
        $url = "/finance/vehicle-reviews/{$request['id']}/decision";
        $decision = ['decision' => 'resolved', 'note' => 'Credit note requested from the supplier.', 'expected_version' => 1];
        $this->actingAs($viewOnly)->post($url, $decision + ['request_key' => 'finance-page-view'])->assertForbidden();
        $this->actingAs($remote)->from('/finance/vehicle-reviews')->post($url, $decision + ['request_key' => 'finance-page-remote'])->assertNotFound();
        $this->actingAs($finance)->from('/finance/vehicle-reviews')->post($url, ['note' => ''] + $decision + ['request_key' => 'finance-page-empty'])
            ->assertRedirect('/finance/vehicle-reviews')->assertSessionHasErrors('note');
        $this->actingAs($finance)->from('/finance/vehicle-reviews')->post($url, $decision + ['request_key' => 'finance-page-decide'])
            ->assertRedirect('/finance/vehicle-reviews')->assertSessionHasNoErrors();
        $stored = FleetFinanceReviewRequest::query()->findOrFail($request['id']);
        $this->assertSame(['resolved', $finance->id], [$stored->status, (int) $stored->decided_by_user_id]);
        $this->actingAs($finance)->from('/finance/vehicle-reviews')
            ->post($url, ['decision' => 'declined', 'expected_version' => 2] + $decision + ['request_key' => 'finance-page-again'])
            ->assertRedirect('/finance/vehicle-reviews')->assertSessionHasErrors('decision');
        // The requester sees Finance's decision on the vehicle.
        $this->assertSame('Resolved', $this->present($manager, $vehicle)['requests'][0]['status_label']);
    }

    public function test_open_review_requests_reach_finance_in_all_tasks(): void
    {
        $vehicle = $this->vehicle($this->site);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'finance.assets.view']);
        $finance = $this->siteUser([$this->site], ['fleet.viewAny', 'finance.assets.view', 'finance.assets.manage']);
        $remoteFinance = $this->siteUser([$this->foreignSite], ['fleet.viewAny', 'finance.assets.view', 'finance.assets.manage']);
        $request = $this->reviewRequest($manager, $vehicle);
        $id = 'fleet_finance_review-'.$request['id'];

        $item = collect((new TaskAggregator)->itemsFor($this->fresh($finance), []))->firstWhere('id', $id);
        $this->assertNotNull($item);
        // The row opens the request in Finance › Vehicle reviews.
        $this->assertSame("/finance/vehicle-reviews?request={$request['id']}", $item->link);
        $this->assertSame($request['reference'], $item->ref);
        $this->assertSame($this->site->id, $item->site['id']);
        $this->assertNull(collect((new TaskAggregator)->itemsFor($this->fresh($manager), []))->firstWhere('id', $id));
        $this->assertNull(collect((new TaskAggregator)->itemsFor($this->fresh($remoteFinance), []))->firstWhere('id', $id));
        // Finance needs no Fleet access to see it.
        $financeOnly = $this->siteUser([$this->site], ['finance.ap.view', 'finance.ap.manage']);
        $this->assertNotNull(collect((new TaskAggregator)->itemsFor($this->fresh($financeOnly), []))->firstWhere('id', $id));
        $this->actingAs($finance)->getJson('/tasks/detail?source=fleet_finance_review&id='.$request['id'])
            ->assertOk()->assertJsonPath('item.id', $id);

        $this->actingAs($finance)->postJson("/fleet-assets/vehicles/{$vehicle->id}/finance/review-requests/{$request['id']}/decision", [
            'decision' => 'declined', 'note' => 'Not a Finance matter; raise it with Maintenance.', 'expected_version' => 1,
        ], ['Idempotency-Key' => 'decision-tasks'])->assertOk();
        $this->assertNull(collect((new TaskAggregator)->itemsFor($this->fresh($finance), []))->firstWhere('id', $id));
    }

    /** @return array<string,mixed> */
    private function present(User $viewer, Asset $vehicle): array
    {
        return app(VehicleFinancePresenter::class)->present($this->fresh($viewer), $vehicle);
    }

    private function fresh(User $user): User
    {
        return User::query()->findOrFail($user->id);
    }

    /** @return array<string,mixed> */
    private function reviewRequest(User $manager, Asset $vehicle): array
    {
        return $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/finance/review-requests", [
            'request_type' => 'supplier_invoice_review', 'source' => 'vehicle', 'amount' => '575.00',
            'note' => 'The next service invoice needs checking against the quote.',
        ], ['Idempotency-Key' => 'frq-'.$vehicle->id.'-'.$manager->id])->assertOk()->json('request');
    }

    private function link(Asset $vehicle, string $type, int $id, ?User $by = null): FleetVehicleFinanceLink
    {
        return FleetVehicleFinanceLink::query()->create([
            'asset_id' => $vehicle->id, 'record_type' => $type, 'record_id' => $id, 'active_slot' => 1,
            'reason' => 'Service spend for this van', 'linked_by_user_id' => $by?->id,
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function fixedAsset(array $attributes = []): FinFixedAsset
    {
        return FinFixedAsset::factory()->create([
            'category' => 'vehicle', 'status' => 'active', 'purchase_date' => '2024-01-12', 'purchase_cost' => 42000,
            'accumulated_depreciation' => 4200, 'useful_life_months' => 60, 'depreciation_method' => 'straight_line',
            ...$attributes,
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function purchaseOrder(array $attributes = []): FinPurchaseOrder
    {
        return FinPurchaseOrder::factory()->create([
            'status' => 'approved', 'order_date' => '2026-09-01', 'subtotal' => 355, 'gst_amount' => 53.25, 'total_amount' => 408.25,
            ...$attributes,
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function bill(array $attributes = []): FinBill
    {
        return FinBill::factory()->create([
            'status' => 'awaiting_approval', 'bill_date' => '2026-09-05', 'due_date' => '2026-10-05',
            'subtotal' => 355, 'gst_amount' => 53.25, 'total_amount' => 408.25,
            ...$attributes,
        ]);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    /**
     * @param  list<Site>  $sites
     * @param  list<string>  $permissions
     */
    private function siteUser(array $sites, array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $sites[0]->id,
            'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->values()->all(),
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $user->permissionOverrides()->syncWithoutDetaching(collect($permissions)->mapWithKeys(fn (string $key): array => [
            Permission::query()->firstOrCreate(['key' => $key], [
                'description' => $key, 'group' => str($key)->before('.')->value(), 'module' => str($key)->before('.')->value(),
            ])->id => ['allowed' => true],
        ])->all());
        $user->unsetRelation('permissionOverrides');

        return $user;
    }

    private function vehicle(Site $site): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id, 'home_site_id' => $site->id, 'name' => 'Kōwhai van', 'status' => 'active',
        ]);
    }
}
