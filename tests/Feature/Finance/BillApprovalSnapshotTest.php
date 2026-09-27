<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillApprovalReceipt;
use App\Domain\Finance\Models\FinBillDocument;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Domain\Finance\Services\BillApprovalSnapshot;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use App\Services\Fleet\MaintenanceFinanceService;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    config(['finance.spend_approval.enforce' => false]);
    $this->freezeTime();
    $this->approver = User::factory()->create(['approved_at' => now(), 'role' => 'manager']);
    foreach (['finance.ap.view', 'finance.ap.manage', 'finance.payments.manageAllSites'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'finance', 'module' => 'finance']);
        $this->approver->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    }
    $this->expense = FinAccount::factory()->create(['organization_id' => 1, 'code' => '6000', 'type' => 'expense', 'is_active' => true]);
    FinAccount::factory()->create(['organization_id' => 1, 'code' => '2000', 'type' => 'liability', 'is_active' => true]);
    FinFiscalPeriod::create(['organization_id' => 1, 'name' => 'Current year', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear(), 'status' => 'open']);
    $this->site = Site::factory()->create();
    $this->bill = FinBill::factory()->create(['site_id' => $this->site->id, 'status' => 'draft', 'bill_date' => today(), 'subtotal' => 500, 'gst_amount' => 0, 'total_amount' => 500]);
    $this->line = $this->bill->lines()->create(['description' => 'Vehicle service', 'quantity' => 1, 'unit_price' => 500, 'gst_rate' => 0, 'gst_amount' => 0, 'line_total' => 500, 'account_id' => $this->expense->id]);
    $this->billUrl = '/finance/bills/'.$this->bill->id;
    $this->actingAs($this->approver);
});

function pkg03QuoteWork($test): array
{
    foreach (['fleet.maintenance.manage', 'fleet.viewAny', 'assets.viewAny', 'sites.viewAll', 'finance.ledger.view'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'fleet', 'module' => 'fleet']);
        $test->approver->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    $test->approver = $test->approver->fresh();
    ensureCanonicalHrStaffProfile($test->approver, $test->site);
    $test->actingAs($test->approver);
    $asset = Asset::factory()->create(['site_id' => $test->site->id, 'category' => 'vehicle']);
    $work = FleetWorkOrder::create(['asset_id' => $asset->id, 'reported_by_user_id' => $test->approver->id,
        'title' => 'Synthetic quote test', 'category' => 'vehicle', 'priority' => 'medium', 'status' => 'open', 'version' => 0]);
    $test->bill->update(['asset_id' => $asset->id]);
    app(MaintenanceFinanceService::class)->linkBill($test->approver, $work->id, $test->bill->id);
    $vendor = FinVendor::factory()->create(['is_active' => true]);
    $payload = ['operation' => 'record_cost_estimate', 'version' => 0, 'vendor_id' => $vendor->id,
        'quote_reference' => 'QUOTE-01', 'amount' => '450.00', 'document_ids' => [], 'reason' => 'Supplier quote received'];

    return [$asset, $work, $payload];
}

it('retains estimate revisions with safe retry and rejects stale or unauthorised changes', function (): void {
    [$asset, $work, $payload] = pkg03QuoteWork($this);
    $url = '/fleet-assets/maintenance/work-orders/'.$work->id;
    $this->putJson($url, $payload, ['Idempotency-Key' => 'quote-command-1'])->assertOk()->assertJsonPath('saved', true);
    $this->putJson($url, $payload, ['Idempotency-Key' => 'quote-command-1'])->assertOk();
    expect(DB::table('fleet_maintenance_actions')->where('work_order_id', $work->id)->where('action_type', 'record_cost_estimate')->count())->toBe(1);
    expect((float) $work->fresh()->estimated_cost)->toBe(450.0);
    $this->putJson($url, [...$payload, 'amount' => '490.00'], ['Idempotency-Key' => 'quote-command-2'])->assertConflict();
    $this->putJson($url, [...$payload, 'amount' => '490.00', 'version' => 1], ['Idempotency-Key' => 'quote-command-2'])->assertOk();
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('cost_workspace.estimates', 2)
        ->where('cost_workspace.estimates.0.amount', '490.00')->where('cost_workspace.bills.0.id', $this->bill->id));
    $permission = Permission::where('key', 'finance.ap.view')->firstOrFail();
    $this->approver->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    $this->actingAs($this->approver->fresh())->putJson($url, [...$payload, 'version' => 2], ['Idempotency-Key' => 'quote-command-3'])->assertForbidden();
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('cost_workspace', null)->where('actions', []));
});

it('invalidates bill approval when the linked quote changes and retains the journal source', function (): void {
    [, $work, $payload] = pkg03QuoteWork($this);
    $token = BillApprovalSnapshot::token($this->bill->fresh());
    $this->putJson('/fleet-assets/maintenance/work-orders/'.$work->id, $payload, ['Idempotency-Key' => 'quote-source-command'])->assertOk();
    $this->postJson($this->billUrl.'/approve', ['approval_snapshot' => $token])->assertConflict();
    $receipt = $this->postJson($this->billUrl.'/approve', ['approval_snapshot' => BillApprovalSnapshot::token($this->bill->fresh())])->assertOk()->json('receipt');
    $this->get('/finance/journals/'.$receipt['journal_id'])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('sourceBill.id', $this->bill->id)->where('sourceBill.work.title', 'Synthetic quote test')->where('sourceBill.paid', '0.00'));
});

it('serves only clean current linked quote evidence through bill authorisation', function (): void {
    [$asset, $work, $payload] = pkg03QuoteWork($this);
    Storage::fake('local');
    Storage::disk('local')->put('quotes/test.pdf', '%PDF-1.4 synthetic');
    $file = AssetDocument::create(['asset_id' => $asset->id, 'uploaded_by_user_id' => $this->approver->id,
        'title' => 'Quote', 'category' => 'Maintenance quote', 'storage_disk' => 'local', 'storage_path' => 'quotes/test.pdf',
        'original_name' => 'quote.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 18, 'state' => 'available', 'scan_disposition' => 'clean']);
    $this->putJson('/fleet-assets/maintenance/work-orders/'.$work->id, [...$payload, 'document_ids' => [$file->id]], ['Idempotency-Key' => 'quote-evidence-command'])->assertOk();
    $url = $this->billUrl.'/work-evidence/'.$file->id;
    $this->get($url.'?inline=1')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $unrelated = FinBill::factory()->create(['site_id' => $this->site->id, 'asset_id' => $asset->id]);
    $this->get('/finance/bills/'.$unrelated->id.'/work-evidence/'.$file->id)->assertNotFound();
    $file->update(['state' => 'scan_unavailable', 'scan_disposition' => 'unavailable']);
    $this->get($url)->assertNotFound();
    $this->postJson($this->billUrl.'/approve', ['approval_snapshot' => BillApprovalSnapshot::token($this->bill->fresh())])->assertUnprocessable();
    $file->update(['state' => 'available', 'scan_disposition' => 'clean', 'archived_at' => now()]);
    $this->get($url)->assertNotFound();
});

it('uses canonical vehicle file access while retaining Finance and source ownership checks', function (): void {
    [$asset, $work, $payload] = pkg03QuoteWork($this);
    $this->approver->hrEmployeeProfile()->delete();
    $permission = Permission::firstOrCreate(['key' => 'fleet.vehicles.viewAllSites'], ['description' => 'Central Fleet oversight', 'group' => 'fleet', 'module' => 'fleet']);
    $this->approver->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    $file = AssetDocument::create(['asset_id' => $asset->id, 'uploaded_by_user_id' => $this->approver->id,
        'title' => 'Quote', 'storage_disk' => 'private', 'storage_path' => 'quotes/central.pdf',
        'original_name' => 'central.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 18,
        'state' => 'available', 'scan_disposition' => 'clean']);
    $url = '/fleet-assets/maintenance/work-orders/'.$work->id;
    $command = [...$payload, 'document_ids' => [$file->id]];
    $this->actingAs($this->approver->fresh())->putJson($url, $command, ['Idempotency-Key' => 'central-quote'])->assertNotFound();

    $this->approver->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    $this->actingAs($this->approver->fresh())->putJson($url, $command, ['Idempotency-Key' => 'central-quote'])->assertOk();
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('cost_workspace.documents.0.url', "/fleet-assets/vehicles/{$asset->id}/documents/{$file->id}/file"));

    $file->update(['source_type' => 'finance_review_request', 'source_id' => 999999]);
    $this->putJson($url, [...$command, 'version' => 1], ['Idempotency-Key' => 'owned-quote'])->assertNotFound();
    $permission = Permission::where('key', 'finance.ap.view')->firstOrFail();
    $this->approver->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    $this->actingAs($this->approver->fresh())->putJson($url, [...$command, 'version' => 1], ['Idempotency-Key' => 'finance-denied'])->assertForbidden();
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('cost_workspace', null));
});

it('requires the snapshot displayed with the bill and rejects forged snapshots', function (): void {
    $token = BillApprovalSnapshot::token($this->bill->fresh('lines'));
    $this->get($this->billUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('finance/bills/Show')->where('approvalSnapshot', $token));
    $this->from($this->billUrl)->post($this->billUrl.'/approve')
        ->assertSessionHasErrors('approval_snapshot');
    $this->from($this->billUrl)->post($this->billUrl.'/approve', ['approval_snapshot' => str_repeat('0', 64)])
        ->assertSessionHasErrors('approval_snapshot');
    expect($this->bill->fresh()->status)->toBe('draft');
    expect(FinJournal::where('source_type', FinBill::class)->where('source_id', $this->bill->id)->count())->toBe(0);
});

it('rejects changes made after review even within the same timestamp second', function (string $change): void {
    $token = BillApprovalSnapshot::token($this->bill->fresh('lines'));
    if ($change === 'amount') {
        $this->bill->update(['subtotal' => 600, 'total_amount' => 600]);
        $this->line->update(['unit_price' => 600, 'line_total' => 600]);
    } elseif ($change === 'allocation') {
        $otherAccount = FinAccount::factory()->create(['organization_id' => 1, 'code' => '6001', 'type' => 'expense', 'is_active' => true]);
        $this->line->update(['account_id' => $otherAccount->id]);
    } else {
        $this->line->update(['description' => 'Different service at the same price']);
    }
    $this->from($this->billUrl)->post($this->billUrl.'/approve', ['approval_snapshot' => $token])
        ->assertRedirect($this->billUrl)->assertSessionHasErrors('approval_snapshot');
    expect($this->bill->fresh()->status)->toBe('draft');
    expect(FinJournal::where('source_type', FinBill::class)->where('source_id', $this->bill->id)->count())->toBe(0);

    $current = BillApprovalSnapshot::token($this->bill->fresh('lines'));
    expect($current)->not->toBe($token);
    $this->from($this->billUrl)->post($this->billUrl.'/approve', ['approval_snapshot' => $current])
        ->assertRedirect($this->billUrl)->assertSessionHasNoErrors();
    expect($this->bill->fresh()->status)->toBe('approved');
    $this->from($this->billUrl)->post($this->billUrl.'/approve', ['approval_snapshot' => $current])
        ->assertSessionHasNoErrors();
    expect(FinJournal::where('source_type', FinBill::class)->where('source_id', $this->bill->id)->count())->toBe(1);
})->with(['amount', 'allocation', 'line description']);

it('does not let a valid review snapshot confer approval permission', function (): void {
    $token = BillApprovalSnapshot::token($this->bill->fresh('lines'));
    $permission = Permission::where('key', 'finance.ap.manage')->firstOrFail();
    $this->approver->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    $this->actingAs($this->approver->fresh())->post($this->billUrl.'/approve', ['approval_snapshot' => $token])->assertForbidden();
    expect($this->bill->fresh()->status)->toBe('draft');
});

it('retains an approval receipt and prevents stale cancellation from overwriting a posted bill', function (): void {
    $asset = Asset::factory()->create(['site_id' => $this->site->id, 'category' => 'vehicle']);
    $this->bill->update(['asset_id' => $asset->id]);
    $stale = $this->bill->fresh();
    $payload = ['approval_snapshot' => BillApprovalSnapshot::token($stale)];
    $first = $this->postJson($this->billUrl.'/approve', $payload)->assertOk()->json('receipt');
    expect($this->postJson($this->billUrl.'/approve', $payload)->assertOk()->json('receipt'))->toEqual($first);
    $asset->delete();
    expect($this->postJson($this->billUrl.'/approve', $payload)->assertOk()->json('receipt'))->toEqual($first);
    expect(FinBillApprovalReceipt::count())->toBe(1);
    expect(fn () => app(AccountsPayableService::class)->cancelBill($stale, $this->approver))
        ->toThrow(InvalidArgumentException::class);
    expect($stale->fresh()->status)->toBe('approved');
    expect(FinJournal::where('source_type', FinBill::class)->where('source_id', $stale->id)->count())->toBe(1);
});

it('conceals bills outside approved sites and aggregates only accessible balances', function (): void {
    $central = Permission::where('key', 'finance.payments.manageAllSites')->firstOrFail();
    $this->approver->permissionOverrides()->updateExistingPivot($central->id, ['allowed' => false]);
    ensureCanonicalHrStaffProfile($this->approver, $this->site);
    $foreign = FinBill::factory()->create(['site_id' => Site::factory()->create()->id, 'total_amount' => 98765]);
    $this->actingAs($this->approver->fresh());
    $this->get('/finance/bills/'.$foreign->id)->assertNotFound();
    $this->post('/finance/bills/'.$foreign->id.'/cancel')->assertNotFound();
    $this->postJson('/finance/bills/'.$foreign->id.'/approve', ['approval_snapshot' => str_repeat('a', 64)])->assertNotFound();
    FinBill::factory()->count(45)->create(['site_id' => $this->site->id, 'status' => 'approved', 'due_date' => today('Pacific/Auckland'), 'total_amount' => 100, 'amount_paid' => 20]);
    $this->get('/finance/bills')->assertOk()->assertInertia(fn (Assert $page) => $page->has('bills.data', 20)
        ->where('bills.total', 46)->where('summary.unpaid_count', 45)
        ->where('summary.total_unpaid', '3600.00')->where('summary.overdue_count', 0)->where('summary.due_this_week_count', 45));
    expect($this->get('/finance/bills/export')->assertOk()->streamedContent())->not->toContain($foreign->bill_number);
});

it('requires clean private bill evidence, permits scan recovery and freezes it after approval', function (): void {
    Storage::fake('local');
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scanPath')->once()->andReturn(new MalwareScanResult(MalwareScanDisposition::Unavailable, 'test', 'offline'));
    $scanner->shouldReceive('scanPath')->once()->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'test'));
    app()->instance(MalwareScanner::class, $scanner);
    $oldToken = BillApprovalSnapshot::token($this->bill->fresh());
    $file = UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF");
    $response = $this->postJson($this->billUrl.'/documents', ['file' => $file], ['Idempotency-Key' => 'invoice-file'])->assertOk();
    $response->assertJsonPath('document.state', 'scan_unavailable')->assertJsonMissingPath('document.path');
    $id = $response->json('document.id');
    $url = $this->billUrl.'/documents/'.$id;
    $this->get($url.'?inline=1')->assertStatus(409);
    $this->postJson($this->billUrl.'/approve', ['approval_snapshot' => $oldToken])->assertUnprocessable();
    $this->postJson($url.'/retry')->assertOk()->assertJsonPath('document.state', 'available');
    $this->get($url.'?inline=1')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->postJson($this->billUrl.'/approve', ['approval_snapshot' => $oldToken])->assertStatus(409);
    $this->postJson($this->billUrl.'/approve', ['approval_snapshot' => BillApprovalSnapshot::token($this->bill->fresh())])->assertOk();
    $this->postJson($url.'/withdraw')->assertStatus(409);
    $other = FinBill::factory()->create(['site_id' => $this->site->id]);
    $this->get('/finance/bills/'.$other->id.'/documents/'.$id)->assertNotFound();
});

it('withdraws failed evidence once while retaining its bytes and the actor audit', function (): void {
    Storage::fake('local');
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scanPath')->once()->andReturn(new MalwareScanResult(MalwareScanDisposition::Unavailable, 'test', 'offline'));
    app()->instance(MalwareScanner::class, $scanner);
    $file = UploadedFile::fake()->createWithContent('failed.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF");
    $id = $this->postJson($this->billUrl.'/documents', ['file' => $file], ['Idempotency-Key' => 'withdraw-file'])->assertOk()->json('document.id');
    $document = FinBillDocument::findOrFail($id);
    $this->postJson($this->billUrl.'/documents/'.$id.'/withdraw')->assertOk();
    $this->postJson($this->billUrl.'/documents/'.$id.'/withdraw')->assertOk();
    expect($document->fresh()->state)->toBe('withdrawn');
    expect(Storage::disk('local')->exists($document->path))->toBeTrue();
    expect(DB::table('audit_logs')->where('action', 'finance.bill.evidence.withdrawn')->where('auditable_id', $this->bill->id)->count())->toBe(1);
    $this->get($this->billUrl.'/documents/'.$id)->assertStatus(409);
});

it('shows bounded duplicate warnings for the same supplier and normalised invoice reference', function (): void {
    $this->bill->update(['vendor_reference' => ' Invoice-42 ']);
    $matches = FinBill::factory()->count(12)->create(['site_id' => $this->site->id, 'vendor_id' => $this->bill->vendor_id, 'vendor_reference' => 'invoice-42', 'status' => 'draft']);
    FinBill::factory()->create(['site_id' => $this->site->id, 'vendor_reference' => 'invoice-42', 'status' => 'draft']);
    $this->get($this->billUrl)->assertOk()->assertInertia(fn (Assert $page) => $page->has('duplicateBills', 10)
        ->where('duplicateBills.0.id', $matches->last()->id));
});

it('serializes independent approval and cancellation workers without duplicate or contradictory posting', function (string $secondAction): void {
    $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');
    $database = $connection->getDatabaseName();
    $token = BillApprovalSnapshot::token($this->bill->fresh());
    $connection->commit();
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pkg03-race-'.bin2hex(random_bytes(12));
    $processes = [];
    $paths = [];
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['finance.spend_approval.enforce' => false]);
$bill = App\Domain\Finance\Models\FinBill::findOrFail((int) $argv[2]);
$actor = App\Models\User::findOrFail((int) $argv[3]);
// The service race needs actor context, not concurrent interactive-login
// listeners writing the same user's session and login audit before the barrier.
Illuminate\Support\Facades\Auth::setUser($actor);
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 90');
file_put_contents($argv[6], 'attempting');
try {
    $service = app(App\Domain\Finance\Services\AccountsPayableService::class);
    if ($argv[4] === 'approve') $service->approveBill($bill, $actor->id, $argv[5]);
    else $service->cancelBill($bill, $actor);
    echo $argv[4];
} catch (InvalidArgumentException|App\Domain\Finance\Exceptions\StaleBillApprovalException $error) {
    echo 'rejected';
}
PHP;
    try {
        $connection->beginTransaction();
        FinBill::whereKey($this->bill->id)->lockForUpdate()->firstOrFail();
        foreach (['approve', $secondAction] as $index => $action) {
            $paths[] = $ready = $barrier.'-'.$index;
            $process = new Process([PHP_BINARY, '-r', $worker, base_path(), (string) $this->bill->id,
                (string) $this->approver->id, $action, $token, $ready], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => $database]);
            $process->setTimeout(120);
            $process->start();
            $processes[] = $process;
        }
        // Cold application bootstrap can be slow while other isolated schemas
        // load. Keep a bounded startup allowance without relaxing the shared
        // lock barrier or any posting/receipt assertions below.
        $deadline = microtime(true) + 60;
        while (collect($paths)->contains(fn ($path) => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                $diagnostics = array_map(fn (Process $process): string => $process->isRunning()
                    ? 'worker still starting'
                    : trim($process->getErrorOutput().' '.$process->getOutput()), $processes);
                throw new RuntimeException('Bill workers did not reach the shared lock: '.implode(' | ', $diagnostics));
            }
            usleep(10_000);
        }
        usleep(200_000);
        foreach ($processes as $process) {
            expect($process->isRunning())->toBeTrue();
        }
        $connection->commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException($process->getErrorOutput());
            }
            $results[] = trim($process->getOutput());
        }
        $posted = $this->bill->fresh()->status === 'approved';
        expect(FinJournal::where('source_type', FinBill::class)->where('source_id', $this->bill->id)->count())->toBe($posted ? 1 : 0);
        expect(FinBillApprovalReceipt::where('bill_id', $this->bill->id)->count())->toBe($posted ? 1 : 0);
        if ($secondAction === 'approve') {
            expect($results)->toBe(['approve', 'approve']);
        } else {
            expect($this->bill->fresh()->status)->toBeIn(['approved', 'cancelled']);
            expect(collect($results)->filter(fn ($result) => $result === 'rejected')->count())->toBe(1);
        }
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $connection->beginTransaction();
    }
})->with(['cancel', 'approve']);
