<?php

namespace Tests\Feature\Sites;

use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Models\HouseLedger;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\HouseLedgerService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HouseLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supportWorker;

    protected Site $houseSite;

    protected Site $officeSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->houseSite = Site::factory()->create(['type' => 'house']);
        $this->officeSite = Site::factory()->create(['type' => 'head_office']);

        Bus::fake();
    }

    public function test_ledger_index_requires_authentication(): void
    {
        $this->get("/sites/{$this->houseSite->id}/ledger")->assertRedirect('/login');
    }

    public function test_admin_can_view_house_ledger(): void
    {
        $this->actingAs($this->admin)
            ->get("/sites/{$this->houseSite->id}/ledger")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/ledger/index')
                ->has('site')
                ->has('ledger')
                ->has('entries')
            );
    }

    public function test_non_house_site_returns_404_for_ledger(): void
    {
        $this->actingAs($this->admin)
            ->get("/sites/{$this->officeSite->id}/ledger")
            ->assertNotFound();
    }

    public function test_admin_can_add_ledger_entry(): void
    {
        $this->actingAs($this->admin)
            ->post("/sites/{$this->houseSite->id}/ledger/entries", [
                'entry_type' => 'income',
                'category' => 'funding',
                'description' => 'Monthly house funding',
                'amount' => 1500.00,
                'entry_date' => '2026-02-20',
            ])
            ->assertRedirect();

        $ledger = HouseLedger::where('site_id', $this->houseSite->id)->first();
        $this->assertNotNull($ledger);
        $this->assertEquals(1500.00, (float) $ledger->current_balance);

        $this->assertDatabaseHas('house_ledger_entries', [
            'house_ledger_id' => $ledger->id,
            'entry_type' => 'income',
            'amount' => 1500.00,
            'running_balance' => 1500.00,
        ]);

        Bus::assertDispatched(ProcessFinancialEventJob::class, fn (ProcessFinancialEventJob $job) => $job->eventData['event_type'] === 'house_ledger_income'
            && $job->eventData['site_id'] === $this->houseSite->id);
    }

    public function test_ledger_index_can_return_json_with_date_filters(): void
    {
        $service = app(HouseLedgerService::class);
        $ledger = $service->getOrCreateLedger($this->houseSite);

        $service->addEntry($ledger, [
            'entry_type' => 'income',
            'category' => 'funding',
            'description' => 'April funding',
            'amount' => 500.00,
            'entry_date' => '2026-04-15',
        ], $this->admin->id);

        $service->addEntry($ledger, [
            'entry_type' => 'expense',
            'category' => 'groceries',
            'description' => 'May groceries',
            'amount' => 120.00,
            'entry_date' => '2026-05-03',
        ], $this->admin->id);

        $this->actingAs($this->admin)
            ->getJson("/sites/{$this->houseSite->id}/ledger?from=2026-05-01&to=2026-05-31")
            ->assertOk()
            ->assertJsonPath('ledger.id', $ledger->id)
            ->assertJsonPath('entries.meta.total', 1)
            ->assertJsonPath('entries.data.0.description', 'May groceries');
    }

    public function test_expense_reduces_balance(): void
    {
        $service = app(HouseLedgerService::class);
        $ledger = $service->getOrCreateLedger($this->houseSite);

        // Add income first
        $service->addEntry($ledger, [
            'entry_type' => 'income',
            'category' => 'funding',
            'description' => 'Funding',
            'amount' => 1000.00,
            'entry_date' => '2026-02-20',
        ], $this->admin->id);

        // Add expense
        $service->addEntry($ledger, [
            'entry_type' => 'expense',
            'category' => 'groceries',
            'description' => 'Weekly groceries',
            'amount' => 250.00,
            'entry_date' => '2026-02-20',
        ], $this->admin->id);

        $ledger->refresh();
        $this->assertEquals(750.00, (float) $ledger->current_balance);
    }

    public function test_running_balance_is_sequential(): void
    {
        $service = app(HouseLedgerService::class);
        $ledger = $service->getOrCreateLedger($this->houseSite);

        $entry1 = $service->addEntry($ledger, [
            'entry_type' => 'income',
            'category' => 'funding',
            'description' => 'First deposit',
            'amount' => 500.00,
            'entry_date' => '2026-02-20',
        ], $this->admin->id);

        $entry2 = $service->addEntry($ledger, [
            'entry_type' => 'expense',
            'category' => 'utilities',
            'description' => 'Power bill',
            'amount' => 120.00,
            'entry_date' => '2026-02-20',
        ], $this->admin->id);

        $entry3 = $service->addEntry($ledger, [
            'entry_type' => 'income',
            'category' => 'funding',
            'description' => 'Top up',
            'amount' => 200.00,
            'entry_date' => '2026-02-20',
        ], $this->admin->id);

        $this->assertEquals(500.00, (float) $entry1->running_balance);
        $this->assertEquals(380.00, (float) $entry2->running_balance);
        $this->assertEquals(580.00, (float) $entry3->running_balance);
    }

    public function test_admin_can_reconcile_ledger(): void
    {
        // First create the ledger
        $this->actingAs($this->admin)
            ->post("/sites/{$this->houseSite->id}/ledger", [
                'entry_type' => 'income',
                'category' => 'funding',
                'description' => 'Initial',
                'amount' => 100.00,
                'entry_date' => '2026-02-20',
            ]);

        $this->actingAs($this->admin)
            ->post("/sites/{$this->houseSite->id}/ledger/reconcile")
            ->assertRedirect();

        $ledger = HouseLedger::where('site_id', $this->houseSite->id)->first();
        $this->assertNotNull($ledger->last_reconciled_at);
        $this->assertSame($this->admin->id, $ledger->reconciled_by);
    }

    public function test_admin_can_download_ledger_attachment(): void
    {
        Storage::fake('private');

        $service = app(HouseLedgerService::class);
        $ledger = $service->getOrCreateLedger($this->houseSite);
        Storage::disk('private')->put('house-ledger/test-receipt.pdf', 'receipt-content');

        $entry = $service->addEntry($ledger, [
            'entry_type' => 'expense',
            'category' => 'groceries',
            'description' => 'Receipt attached',
            'amount' => 20.00,
            'entry_date' => '2026-02-20',
            'attachments' => [[
                'path' => 'house-ledger/test-receipt.pdf',
                'disk' => 'private',
                'original_name' => 'test-receipt.pdf',
                'mime_type' => 'application/pdf',
                'size' => 15,
            ]],
        ], $this->admin->id);

        $this->actingAs($this->admin)
            ->get("/sites/{$this->houseSite->id}/ledger/entries/{$entry->id}/download")
            ->assertOk();
    }

    public function test_download_attachment_requires_ledger_view_permission(): void
    {
        Storage::fake('private');

        $service = app(HouseLedgerService::class);
        $ledger = $service->getOrCreateLedger($this->houseSite);
        Storage::disk('private')->put('house-ledger/perm-receipt.pdf', 'receipt');

        $entry = $service->addEntry($ledger, [
            'entry_type' => 'expense',
            'category' => 'groceries',
            'description' => 'Permission-gated receipt',
            'amount' => 12.50,
            'entry_date' => '2026-02-20',
            'attachments' => [[
                'path' => 'house-ledger/perm-receipt.pdf',
                'disk' => 'private',
                'original_name' => 'perm-receipt.pdf',
                'mime_type' => 'application/pdf',
                'size' => 7,
            ]],
        ], $this->admin->id);

        // User can view sites (passes route middleware + SitePolicy) but lacks sites.ledger.view.
        $unprivileged = User::factory()->create(['role' => 'viewer', 'approved_at' => now()]);
        $grants = Permission::whereIn('key', ['sites.viewAny', 'sites.type.house.view'])
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();
        $unprivileged->permissionOverrides()->attach($grants);

        $this->actingAs($unprivileged)
            ->get("/sites/{$this->houseSite->id}/ledger/entries/{$entry->id}/download")
            ->assertForbidden();
    }

    public function test_ledger_routes_reject_cross_tenant_sites(): void
    {
        $otherTenantSite = Site::factory()->create([
            'tenant_id' => 2,
            'type' => 'house',
        ]);

        $this->actingAs($this->admin)
            ->get("/sites/{$otherTenantSite->id}/ledger")
            ->assertForbidden();
    }

    public function test_site_show_defers_house_ledger_to_its_canonical_workspace(): void
    {
        $service = app(HouseLedgerService::class);
        $ledger = $service->getOrCreateLedger($this->houseSite);
        $service->addEntry($ledger, [
            'entry_type' => 'income',
            'category' => 'funding',
            'description' => 'Opening balance',
            'amount' => 300.00,
            'entry_date' => '2026-02-20',
        ], $this->admin->id);

        $this->actingAs($this->admin)
            ->get("/sites/{$this->houseSite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/show')
                ->missing('houseLedger')
                ->missing('adminData')
            );

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->houseSite->id}", $this->inertiaPartialHeaders('sites/show', 'adminData'))
            ->assertOk()
            ->assertJsonPath(
                'props.adminData.financials.house_ledger.href',
                route('sites.ledger.index', $this->houseSite),
            );

        $this->assertStringNotContainsString('Opening balance', $response->getContent());
    }

    public function test_non_house_site_cannot_add_ledger_entry(): void
    {
        $this->actingAs($this->admin)
            ->post("/sites/{$this->officeSite->id}/ledger", [
                'entry_type' => 'income',
                'category' => 'funding',
                'description' => 'Should fail',
                'amount' => 100.00,
                'entry_date' => '2026-02-20',
            ])
            ->assertNotFound();
    }
}
