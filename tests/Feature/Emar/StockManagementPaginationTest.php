<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationStockLot;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Stock\MedicationStockService;
use App\Services\Medication\Stock\StockAvailability;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockManagementPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['name' => 'Pagination House', 'is_active' => true, 'archived' => false]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Current', 'last_name' => 'Person']);
        $role = Role::create(['name' => 'stock-pagination-'.Str::uuid(), 'label' => 'Scoped stock keeper', 'type' => 'custom', 'level' => 10]);
        $role->permissions()->sync(Permission::whereIn('key', ['medications.view', 'medications.stock.update', 'medications.controlled.view'])->pluck('id'));
        $this->actor = User::factory()->create(['role' => $role->name, 'approved_at' => now()]);
        $this->actor->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now('Pacific/Auckland')->subYear()->toDateString(), 'end_date' => null]);
        $this->assertTrue($this->actor->canDo('medications.stock.update'));
        $this->assertTrue($this->actor->canDo('medications.controlled.view'));
        $this->assertFalse($this->actor->canDo('reports.viewAny'));
        $this->assertFalse($this->actor->canDo('clinical.accessAllSites'));
        $this->actingAs($this->actor);
    }

    public function test_inventory_pages_are_deterministic_and_filters_keep_full_scoped_summary(): void
    {
        $stocks = [];
        for ($index = 1; $index <= 25; $index++) {
            [, $stocks[]] = $this->medicine(sprintf('Medicine %02d', $index), $index >= 21, stock: [
                'on_hand' => $index === 25 ? null : ($index <= 6 ? 4 : 12),
                'expiry_date' => $index >= 7 && $index <= 10 ? now('Pacific/Auckland')->subDay()->toDateString()
                    : ($index >= 11 && $index <= 15 ? now('Pacific/Auckland')->addDays(15)->toDateString() : null),
                'storage_condition' => in_array($index, [1, 2, 11], true) ? 'fridge' : 'ambient',
            ]);
        }
        $before = $this->rawSnapshot();
        $summary = ['total_stock' => 25, 'low_stock' => 6, 'expiring' => 5, 'expired' => 4, 'controlled' => 5,
            'controlled_discrepancies' => 0, 'total_orders' => 0, 'open_orders' => 0, 'overdue_orders' => 0];
        $seen = [];
        foreach ([1 => 10, 2 => 10, 3 => 5] as $page => $expectedSize) {
            $props = $this->page(['page' => $page]);
            $this->assertCount($expectedSize, $props['stockItems']);
            $ids = array_column($props['stockItems'], 'id');
            $this->assertSame(array_map(fn ($s) => $s->id, array_slice($stocks, ($page - 1) * 10, 10)), $ids);
            $seen = [...$seen, ...$ids];
            $this->assertSame($summary, $props['summary']);
            $this->assertSame(25, $props['pagination']['total']);
            $this->assertSame(10, $props['pagination']['per_page']);
            $this->assertSame($page, $props['pagination']['current_page']);
            $this->assertSame(3, $props['pagination']['last_page']);
            $this->assertCount(25, $props['activeMedications']);
        }
        $this->assertSame(array_map(fn ($s) => $s->id, $stocks), $seen);
        $this->assertCount(25, array_unique($seen));
        $house = $this->page(['q' => 'Pagination House', 'site_id' => $this->site->id, 'client_id' => $this->client->id]);
        $this->assertSame(25, $house['pagination']['total']);
        parse_str(parse_url($house['pagination']['next_page_url'], PHP_URL_QUERY), $next);
        $this->assertSame('Pagination House', $next['q']);
        $this->assertSame((string) $this->site->id, $next['site_id']);
        $this->assertSame((string) $this->client->id, $next['client_id']);
        $this->assertSame('2', $next['page']);
        $lowCold = $this->page(['view' => 'low', 'chip' => 'cold_chain']);
        $this->assertSame(2, $lowCold['pagination']['total']);
        $this->assertSame([$stocks[0]->id, $stocks[1]->id], array_column($lowCold['stockItems'], 'id'));
        $this->assertSame($summary, $lowCold['summary']);
        $this->assertSame(6, $lowCold['lowStockCount']);
        $this->assertSame(5, $lowCold['expiringCount']);
        $this->assertSame(4, $lowCold['expiredCount']);
        $this->assertSame(1, $this->page(['view' => 'expiring', 'chip' => 'cold_chain'])['pagination']['total']);
        $this->assertSame(4, $this->page(['view' => 'expired'])['pagination']['total']);
        $search = $this->page(['q' => 'Medicine 25']);
        $this->assertSame(1, $search['pagination']['total']);
        $this->assertSame($stocks[24]->id, $search['stockItems'][0]['id']);
        $this->assertNull($search['stockItems'][0]['on_hand']);
        $this->assertSame($summary, $search['summary']);
        $this->assertSame($before, $this->rawSnapshot());
    }

    public function test_pharmacy_history_has_every_order_past_forty_and_open_actions_are_not_page_limited(): void
    {
        [$medication] = $this->medicine('Ordinary medicine');
        $orders = [];
        $overdueCreatedAt = now()->subDays(15)->startOfMinute();
        $recentCreatedAt = now()->subDay()->startOfMinute();
        for ($index = 1; $index <= 45; $index++) {
            $orders[] = $this->order($medication, [
                'pharmacy_name' => $index === 1 ? 'Older isolated pharmacy' : 'Recent pharmacy',
                'batch_number' => $index === 1 ? 'OLD-SEARCH-BATCH' : 'RECENT',
                'status' => match ($index) {
                    1 => 'draft', 2 => 'closed_short', 3 => 'cancelled', 4 => 'received', default => 'delivered'
                },
                'created_at' => $index === 1 ? $overdueCreatedAt : $recentCreatedAt,
            ]);
        }
        foreach ($orders as $index => $order) {
            $expectedCreatedAt = $index === 0 ? $overdueCreatedAt : $recentCreatedAt;
            $this->assertSame($expectedCreatedAt->toDateTimeString(), $order->fresh()->getRawOriginal('created_at'));
        }
        $before = $this->rawSnapshot();
        $seen = [];
        foreach ([1 => 10, 2 => 10, 3 => 10, 4 => 10, 5 => 5] as $page => $expectedSize) {
            $props = $this->page(['view' => 'orders', 'page' => $page]);
            $this->assertSame(45, $props['pagination']['total']);
            $this->assertSame(5, $props['pagination']['last_page']);
            $this->assertCount($expectedSize, $props['pharmacyOrders']);
            $seen = [...$seen, ...array_column($props['pharmacyOrders'], 'id')];
            $this->assertSame(45, $props['summary']['total_orders']);
            $this->assertSame(1, $props['summary']['open_orders']);
            $this->assertSame(1, $props['summary']['overdue_orders']);
            $this->assertSame($orders[0]->id, $props['openOrdersByMedication'][(string) $medication->id]['id']);
        }
        $this->assertSame(array_reverse(array_map(fn ($o) => $o->id, $orders)), $seen);
        $this->assertCount(45, array_unique($seen));
        foreach (['Older isolated pharmacy', 'OLD-SEARCH-BATCH'] as $query) {
            $props = $this->page(['view' => 'orders', 'q' => $query]);
            $this->assertSame(1, $props['pagination']['total']);
            $this->assertSame($orders[0]->id, $props['pharmacyOrders'][0]['id']);
            $this->assertSame(45, $props['summary']['total_orders']);
        }
        $this->assertSame($before, $this->rawSnapshot());
    }

    public function test_visible_order_and_any_picker_medicine_have_stock_context_outside_the_inventory_page(): void
    {
        $medications = $stocks = [];
        for ($index = 1; $index <= 25; $index++) {
            [$medications[], $stocks[]] = $this->medicine(sprintf('Context medicine %02d', $index), stock: ['reorder_quantity' => 17]);
        }
        $order = $this->order($medications[24], ['status' => 'dispensed']);
        $before = $this->rawSnapshot();
        $props = $this->page(['view' => 'orders']);
        $this->assertNotContains($stocks[24]->id, array_column($props['stockItems'], 'id'));
        $contextRows = collect($props['stockContext'])->keyBy('medication_id');
        $this->assertSame($stocks[24]->id, $contextRows->get($medications[24]->id)['id']);
        $this->assertSame($order->id, $props['openOrdersByMedication'][(string) $medications[24]->id]['id']);
        $this->assertContains($medications[18]->id, array_column($props['activeMedications'], 'id'));
        $response = $this->getJson(route('emar.stock.context', ['medication' => $medications[18]->id, 'site_id' => $this->site->id, 'client_id' => $this->client->id]))
            ->assertOk()->assertJsonPath('stock_item.id', $stocks[18]->id)
            ->assertJsonPath('stock_item.on_hand', 12)->assertJsonPath('stock_item.reorder_quantity', 17)
            ->assertJsonPath('stock_item.unit', 'tablets')->assertJsonPath('medication.id', $medications[18]->id)
            ->assertJsonPath('open_order', null)->assertJsonPath('context.medication_id', $medications[18]->id)
            ->assertJsonPath('context.site_id', $this->site->id)->assertJsonPath('context.client_id', $this->client->id);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before, $this->rawSnapshot());
    }

    public function test_missing_stock_unknown_stock_and_unknown_controlled_balances_stay_distinct(): void
    {
        [$missing] = $this->medicine('Missing stock', createStock: false);
        [$unknown, $stock] = $this->medicine('Unknown balance', stock: ['on_hand' => null]);
        [$controlled] = $this->medicine('Unknown controlled balance', true, stock: ['on_hand' => null]);
        $before = $this->rawSnapshot();
        $this->getJson(route('emar.stock.context', $missing))->assertOk()->assertJsonPath('stock_item', null)->assertJsonPath('medication.id', $missing->id);
        $this->getJson(route('emar.stock.context', $unknown))->assertOk()->assertJsonPath('stock_item.id', $stock->id)
            ->assertJsonPath('stock_item.on_hand', null)->assertJsonPath('stock_item.is_low', false)->assertJsonPath('stock_item.pack_workflow_url', null);
        $props = $this->page(['view' => 'controlled']);
        $this->assertSame(1, $props['pagination']['total']);
        $this->assertSame($controlled->id, $props['controlledRegister'][0]['medication_id']);
        $this->assertNull($props['controlledRegister'][0]['on_hand']);
        $this->assertNull($props['controlledRegister'][0]['register_balance']);
        $this->assertNull($props['controlledRegister'][0]['last_check_at']);
        $this->assertSame(0, $props['summary']['low_stock']);
        $this->assertSame(2, $props['summary']['total_stock']);
        $this->assertSame($before, $this->rawSnapshot());
    }

    public function test_controlled_stock_without_a_check_preserves_known_on_hand_and_unknown_last_check(): void
    {
        [$medication] = $this->medicine('Known stock without a check', true, stock: ['on_hand' => 12]);
        $before = $this->rawSnapshot();
        $props = $this->page(['view' => 'controlled']);
        $this->assertCount(1, $props['controlledRegister']);
        $row = $props['controlledRegister'][0];
        $this->assertSame($medication->id, $row['medication_id']);
        $this->assertSame(12, $row['on_hand']);
        $this->assertNull($row['register_balance']);
        $this->assertNull($row['last_check_at']);
        $this->assertNull($row['last_check_witness']);
        $this->getJson(route('emar.stock.context', $medication))->assertOk()->assertJsonPath('stock_item.on_hand', 12);
        $this->assertSame($before, $this->rawSnapshot());
    }

    public function test_latest_null_controlled_check_does_not_borrow_live_or_older_balance_and_zero_remains_zero(): void
    {
        [$unknown] = $this->medicine('Unknown latest check', true, stock: ['on_hand' => 12]);
        $this->check($unknown, now()->subDay(), 8);
        $latestAt = now()->subHour()->startOfMinute();
        ClientControlledDrugEntry::create(['client_id' => $unknown->client_id, 'client_medication_id' => $unknown->id,
            'entry_type' => 'balance_check', 'quantity' => 0, 'unit' => 'tablets', 'on_hand_after' => null,
            'recorded_at' => $latestAt, 'recorded_by' => $this->actor->id, 'witnessed_by' => $this->actor->id]);
        [$zero] = $this->medicine('Zero last check', true, stock: ['on_hand' => 12]);
        $zeroCheck = $this->check($zero, now()->subHour()->startOfMinute(), 0);
        $before = $this->rawSnapshot();
        $props = $this->page(['view' => 'controlled']);
        $this->assertCount(2, $props['controlledRegister']);
        $rows = collect($props['controlledRegister'])->keyBy('medication_id');
        $unknownRow = $rows->get($unknown->id);
        $this->assertSame(12, $unknownRow['on_hand']);
        $this->assertNull($unknownRow['register_balance']);
        $this->assertSame($latestAt->toIso8601String(), $unknownRow['last_check_at']);
        $this->assertSame($this->actor->name, $unknownRow['last_check_witness']);
        $zeroRow = $rows->get($zero->id);
        $this->assertSame(12, $zeroRow['on_hand']);
        $this->assertSame(0, $zeroRow['register_balance']);
        $this->assertSame($zeroCheck->recorded_at->toIso8601String(), $zeroRow['last_check_at']);
        $this->assertSame($this->actor->name, $zeroRow['last_check_witness']);
        $this->assertSame($before, $this->rawSnapshot());
    }

    public function test_controlled_pages_keep_latest_canonical_checks_and_full_scope_discrepancy_count(): void
    {
        $medications = $stocks = [];
        for ($index = 1; $index <= 12; $index++) {
            [$medications[], $stocks[]] = $this->medicine(sprintf('Controlled %02d', $index), true);
        }
        $latest = $this->check($medications[10], now()->subHour(), 9);
        $this->check($medications[10], now()->subDay(), 8); // Newer row ID is not the latest clinical check.
        $foreignPerson = Client::factory()->create(['site_id' => $this->site->id]);
        $this->check($medications[10], now(), 99, $foreignPerson);
        ClientControlledDrugDiscrepancy::create(['client_id' => $this->client->id, 'client_medication_id' => $medications[10]->id,
            'on_hand_before' => 12, 'on_hand_after' => 9, 'difference' => -3, 'reason' => 'Unresolved actual count',
            'status' => 'open', 'reported_at' => now(), 'reported_by' => $this->actor->id]);
        ClientControlledDrugDiscrepancy::create(['client_id' => $foreignPerson->id, 'client_medication_id' => $medications[11]->id,
            'on_hand_before' => 12, 'on_hand_after' => 1, 'difference' => -11, 'reason' => 'Invalid person correlation',
            'status' => 'open', 'reported_at' => now(), 'reported_by' => $this->actor->id]);
        $before = $this->rawSnapshot();
        $first = $this->page(['view' => 'controlled']);
        $second = $this->page(['view' => 'controlled', 'page' => 2]);
        $this->assertCount(10, $first['controlledRegister']);
        $this->assertCount(2, $second['controlledRegister']);
        $this->assertSame([$medications[10]->id, $medications[11]->id], array_column($second['controlledRegister'], 'medication_id'));
        foreach ([$first, $second] as $props) {
            $this->assertSame(12, $props['pagination']['total']);
            $this->assertSame(12, $props['summary']['controlled']);
            $this->assertSame(1, $props['summary']['controlled_discrepancies']);
        }
        $this->assertSame(9, $second['controlledRegister'][0]['register_balance']);
        $this->assertSame($latest->recorded_at->toIso8601String(), $second['controlledRegister'][0]['last_check_at']);
        $this->assertSame($this->actor->name, $second['controlledRegister'][0]['last_check_witness']);
        $this->assertSame(-3, $second['controlledRegister'][0]['discrepancy']);
        $this->assertNull($second['controlledRegister'][1]['discrepancy']);
        $search = $this->page(['view' => 'controlled', 'q' => 'Controlled 11']);
        $this->assertSame(1, $search['pagination']['total']);
        $this->assertSame($medications[10]->id, $search['controlledRegister'][0]['medication_id']);
        $this->assertSame(1, $search['summary']['controlled_discrepancies']);
        $this->assertSame($before, $this->rawSnapshot());
    }

    public function test_all_scoped_counts_lists_contexts_and_orders_exclude_foreign_and_malformed_pairs(): void
    {
        [$local] = $this->medicine('Local stock');
        $this->order($local);
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        [$otherMed] = $this->medicine('Other person stock', client: $other);
        $this->order($otherMed);
        $foreignSite = Site::factory()->create(['name' => 'Foreign house']);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        [$foreignMed] = $this->medicine('Foreign house stock', client: $foreignClient);
        $this->order($foreignMed);
        $this->order($local, ['client_id' => $other->id, 'pharmacy_name' => 'Malformed pairing']);
        $before = $this->rawSnapshot();
        $props = $this->page(['client_id' => $this->client->id, 'site_id' => $this->site->id]);
        $this->assertSame([$local->id], array_column($props['stockItems'], 'medication_id'));
        $this->assertSame([$local->id], array_column($props['stockContext'], 'medication_id'));
        $this->assertSame([$local->id], array_column($props['pharmacyOrders'], 'medication_id'));
        $this->assertSame([$local->id], array_column($props['activeMedications'], 'id'));
        $this->assertSame(1, $props['summary']['total_stock']);
        $this->assertSame(1, $props['summary']['total_orders']);
        $this->assertSame(2, $this->page()['summary']['total_stock']);
        $this->assertSame(2, $this->page()['summary']['total_orders']);
        foreach ([['site_id' => $foreignSite->id], ['client_id' => $foreignClient->id], ['site_id' => $this->site->id, 'client_id' => $foreignClient->id]] as $query) {
            $this->get(route('emar.stock', $query))->assertNotFound();
        }
        $this->getJson(route('emar.stock.context', $foreignMed))->assertNotFound();
        $this->getJson(route('emar.stock.context', ['medication' => $otherMed->id, 'client_id' => $this->client->id]))->assertNotFound();
        $this->getJson(route('emar.stock.context', ['medication' => $local->id, 'site_id' => $foreignSite->id]))->assertNotFound();
        foreach (['0', '-1', 'bad'] as $invalid) {
            $this->get(route('emar.stock', ['client_id' => $invalid]))->assertNotFound();
            $this->getJson(route('emar.stock.context', ['medication' => $local->id, 'client_id' => $invalid]))->assertNotFound();
        }
        $this->assertSame($before, $this->rawSnapshot());
    }

    public function test_current_stock_and_controlled_permissions_are_required_on_page_and_context(): void
    {
        [$ordinary] = $this->medicine('Ordinary visible medicine');
        [$controlled] = $this->medicine('Controlled hidden medicine', true);
        $this->order($controlled);
        $before = $this->rawSnapshot();
        $this->deny('medications.controlled.view');
        $props = $this->page();
        $this->assertFalse($props['can_view_controlled']);
        $this->assertSame([$ordinary->id], array_column($props['stockItems'], 'medication_id'));
        $this->assertSame(0, $props['summary']['controlled']);
        $this->assertSame(0, $props['summary']['total_orders']);
        $this->assertSame([], $props['controlledRegister']);
        $normalized = $this->page(['view' => 'controlled', 'chip' => 'controlled', 'page' => 99]);
        $this->assertSame('all', $normalized['filters']['view']);
        $this->assertSame('all', $normalized['filters']['chip']);
        $this->assertSame(1, $normalized['filters']['page']);
        $this->assertSame([$ordinary->id], array_column($normalized['stockItems'], 'medication_id'));
        $this->getJson(route('emar.stock.context', $controlled))->assertNotFound();
        $this->deny('medications.stock.update');
        $this->get(route('emar.stock'))->assertForbidden();
        $this->getJson(route('emar.stock.context', $ordinary))->assertForbidden();
        $this->assertSame($before, $this->rawSnapshot());
    }

    public function test_pack_expiry_low_stock_and_batch_search_use_canonical_availability_without_rewriting_stock(): void
    {
        config(['medications.stock_lots_enabled' => true]);
        [$medication, $stock] = $this->medicine('Pack medicine', stock: ['on_hand' => 12, 'batch_number' => 'USABLE-EXACT-BATCH',
            'expiry_date' => now('Pacific/Auckland')->addDays(15)->toDateString()]);
        DB::transaction(fn () => app(MedicationStockService::class)->startLots($stock, $this->actor, (string) Str::uuid()));
        MedicationStockLot::create(['client_medication_stock_id' => $stock->id, 'batch_number' => 'EXPIRED-NOT-DISPLAYED',
            'expiry_date' => now('Pacific/Auckland')->subDay()->toDateString(), 'quantity_received' => 3, 'quantity_remaining' => 3,
            'unit' => 'tablets', 'state' => 'open', 'source' => 'opening', 'received_by' => $this->actor->id, 'received_at' => now()->subMonth(), 'revision' => 0]);
        $before = $this->rawSnapshot();
        $props = $this->page(['q' => 'USABLE-EXACT-BATCH']);
        $this->assertSame(1, $props['pagination']['total']);
        $this->assertSame(12, $props['stockItems'][0]['on_hand']);
        $this->assertSame('USABLE-EXACT-BATCH', $props['stockItems'][0]['batch_number']);
        $this->assertSame(1, $props['summary']['expiring']);
        $this->assertSame(1, $props['summary']['expired']);
        $this->assertSame(0, $props['summary']['low_stock']);
        $this->assertSame(0, $this->page(['q' => 'EXPIRED-NOT-DISPLAYED'])['pagination']['total']);
        $this->assertSame(1, $this->page(['view' => 'expired'])['pagination']['total']);
        foreach ([true, false] as $enabled) {
            config(['medications.stock_lots_enabled' => $enabled]);
            $this->getJson(route('emar.stock.context', $medication))->assertOk()
                ->assertJsonPath('stock_item.on_hand', 12)
                ->assertJsonPath('stock_item.pack_workflow_url', '/emar/stock/packs?medication_id='.$medication->id)
                ->assertJsonPath('medication.pack_workflow_url', '/emar/stock/packs?medication_id='.$medication->id);
        }
        $this->assertSame($before, $this->rawSnapshot());
        $this->assertSame('12.00', $stock->fresh()->on_hand);
        $this->assertSame(StockAvailability::today(), now('Pacific/Auckland')->toDateString());
    }

    public function test_page_size_contract_is_bounded_and_invalid_navigation_does_not_write(): void
    {
        for ($index = 1; $index <= 51; $index++) {
            $this->medicine(sprintf('Bounded %02d', $index));
        }
        $before = $this->rawSnapshot();
        foreach ([10, 25, 50] as $size) {
            $props = $this->page(['per_page' => $size]);
            $this->assertCount($size, $props['stockItems']);
            $this->assertSame($size, $props['pagination']['per_page']);
            $this->assertSame(51, $props['pagination']['total']);
        }
        $last = $this->page(['page' => 999, 'per_page' => 50]);
        $this->assertSame(2, $last['filters']['page']);
        $this->assertSame(2, $last['pagination']['current_page']);
        $this->assertSame(2, $last['pagination']['last_page']);
        $this->assertCount(1, $last['stockItems']);
        $empty = $this->page(['page' => 999, 'q' => 'No matching stock']);
        $this->assertSame(1, $empty['filters']['page']);
        $this->assertSame(0, $empty['pagination']['total']);
        $this->assertSame([], $empty['stockItems']);
        foreach ([['per_page' => 1000], ['per_page' => 0], ['page' => -1], ['view' => 'invalid'], ['chip' => 'invalid']] as $params) {
            $this->getJson(route('emar.stock', $params))->assertUnprocessable();
        }
        $this->assertSame($before, $this->rawSnapshot());
    }

    public function test_inventory_sort_groups_person_then_medicine_with_stable_ids(): void
    {
        $firstPerson = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'A', 'last_name' => 'Alpha']);
        $lastPerson = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Z', 'last_name' => 'Zulu']);
        [, $last] = $this->medicine('A medicine', client: $lastPerson);
        [, $firstZ] = $this->medicine('Z medicine', client: $firstPerson);
        [, $firstA] = $this->medicine('A medicine', client: $firstPerson);
        [, $firstATie] = $this->medicine('A medicine', client: $firstPerson);
        $props = $this->page();
        $this->assertSame([$firstA->id, $firstATie->id, $firstZ->id, $last->id], array_column($props['stockItems'], 'id'));
    }

    public function test_unknown_previous_stock_is_an_update_without_an_invented_receipt_or_delta(): void
    {
        [$medication, $stock] = $this->medicine('Unknown previous balance', stock: ['on_hand' => null]);
        $stock->update(['on_hand' => 20]);
        $audit = AuditLog::where('auditable_type', $stock->getMorphClass())->where('auditable_id', $stock->id)
            ->where('action', 'clientmedicationstock.update')->latest('id')->firstOrFail();
        $this->assertArrayHasKey('on_hand', $audit->meta['before']);
        $this->assertNull($audit->meta['before']['on_hand']);
        $before = $this->rawSnapshot();
        $response = $this->getJson(route('emar.stock.context', $medication))->assertOk()
            ->assertJsonPath('stock_item.on_hand', 20)->assertJsonPath('stock_item.movements.0.id', $audit->id)
            ->assertJsonPath('stock_item.movements.0.delta', null)->assertJsonPath('stock_item.movements.0.type', 'updated')
            ->assertJsonPath('stock_item.movements.0.summary', 'Stock balance updated');
        $this->assertNotSame('received', $response->json('stock_item.movements.0.type'));
        $this->assertSame($before, $this->rawSnapshot());
    }

    private function medicine(string $name, bool $controlled = false, ?Client $client = null, array $stock = [], bool $createStock = true): array
    {
        $medication = ClientMedication::query()->forceCreate(['client_id' => ($client ?? $this->client)->id, 'name' => $name,
            'dosage' => '1 tablet', 'frequency' => 'PRN', 'is_prn' => true, 'controlled_drug' => $controlled,
            'active' => true, 'state' => 'active', 'approval_status' => 'verified']);
        $stockModel = $createStock ? ClientMedicationStock::create(['client_medication_id' => $medication->id,
            'on_hand' => 12, 'unit' => 'tablets', 'reorder_level' => 5, ...$stock]) : null;

        return [$medication, $stockModel];
    }

    private function order(ClientMedication $medication, array $data = []): MedicationPharmacyOrder
    {
        $createdAt = $data['created_at'] ?? null;
        unset($data['created_at']);

        $order = MedicationPharmacyOrder::create(['client_id' => $medication->client_id, 'client_medication_id' => $medication->id,
            'pharmacy_name' => 'Scoped pharmacy', 'quantity_ordered' => 12, 'status' => 'draft', 'ordered_by' => $this->actor->id, ...$data]);
        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt])->save();
        }

        return $order;
    }

    private function check(ClientMedication $medication, $recordedAt, int $balance, ?Client $client = null): ClientControlledDrugEntry
    {
        return ClientControlledDrugEntry::create(['client_id' => ($client ?? $this->client)->id, 'client_medication_id' => $medication->id,
            'entry_type' => 'balance_check', 'quantity' => 0, 'unit' => 'tablets', 'on_hand_after' => $balance,
            'recorded_at' => $recordedAt, 'recorded_by' => $this->actor->id, 'witnessed_by' => $this->actor->id]);
    }

    private function page(array $query = []): array
    {
        return $this->get(route('emar.stock', $query))->assertOk()->inertiaProps();
    }

    private function deny(string $key): void
    {
        $permission = Permission::where('key', $key)->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        $this->actor = $this->actor->fresh();
        $this->assertFalse($this->actor->canDo($key));
        $this->actingAs($this->actor);
    }

    private function rawSnapshot(): array
    {
        return [ClientMedicationStock::orderBy('id')->get()->map->getRawOriginal()->all(),
            MedicationPharmacyOrder::orderBy('id')->get()->map->getRawOriginal()->all(),
            ClientControlledDrugEntry::orderBy('id')->get()->map->getRawOriginal()->all(),
            ClientControlledDrugDiscrepancy::orderBy('id')->get()->map->getRawOriginal()->all(),
            MedicationStockLot::orderBy('id')->get()->map->getRawOriginal()->all(),
            AuditLog::query()->count()];
    }
}
