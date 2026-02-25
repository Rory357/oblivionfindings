<?php

namespace Tests\Feature\Sites;

use App\Models\HouseLedger;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\HouseLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->houseSite = Site::factory()->create(['type' => 'house']);
        $this->officeSite = Site::factory()->create(['type' => 'head_office']);
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
            ->post("/sites/{$this->houseSite->id}/ledger", [
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
