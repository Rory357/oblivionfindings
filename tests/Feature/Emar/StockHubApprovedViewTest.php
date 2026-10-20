<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationStockLot;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Stock\MedicationStockService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The approved P06 hub (Main, 20 Oct: "the approved mockup is the spec"):
 * the six meters, the "Receive a delivery" picker and the stock table, each
 * in the list's own scope — house access, the person rule and controlled
 * concealment for readers without controlled-medicine view.
 */
class StockHubApprovedViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 10 am on Tuesday 20 October 2026 in Auckland; the stock day is the NZ date.
        $clock = CarbonImmutable::parse('2026-10-20 10:00:00', 'Pacific/Auckland')->utc();
        Carbon::setTestNow($clock);
        CarbonImmutable::setTestNow($clock);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** A stock user at one house, kept to that house (no all-sites bypass). */
    private function stockUser(Site $site, bool $controlledView): User
    {
        $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::where('name', 'admin')->firstOrFail()->id]);
        $overrides = Permission::whereIn('key', ['medications.view', 'medications.stock.update'])->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all();
        $denied = ['clinical.accessAllSites', 'sites.viewAll', ...($controlledView ? [] : ['medications.controlled.view'])];
        foreach (Permission::whereIn('key', $denied)->pluck('id') as $id) {
            $overrides[$id] = ['allowed' => false];
        }
        $user->permissionOverrides()->syncWithoutDetaching($overrides);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subMonth(), 'end_date' => null, 'is_active' => true]);
        Cache::flush();
        $user = $user->fresh();
        $this->assertFalse($user->canDo('sites.viewAll'));
        $this->assertSame($controlledView, $user->canDo('medications.controlled.view'));

        return $user;
    }

    private function medicine(Client $client, string $name, string $onHand, ?string $reorder, ?string $expiry, User $actor, bool $controlled = false): ClientMedication
    {
        $med = ClientMedication::query()->forceCreate(['client_id' => $client->id, 'name' => $name, 'dosage' => '1 tablet',
            'frequency' => 'PRN', 'is_prn' => true, 'controlled_drug' => $controlled, 'active' => true, 'state' => 'active', 'approval_status' => 'verified']);
        $stock = ClientMedicationStock::create(['client_medication_id' => $med->id, 'on_hand' => $onHand, 'unit' => 'tablets',
            'reorder_level' => $reorder, 'expiry_date' => $expiry, 'batch_number' => 'SYN-'.Str::upper(Str::substr($name, 0, 3))]);
        DB::transaction(fn () => app(MedicationStockService::class)->startLots($stock, $actor, (string) Str::uuid()));

        return $med;
    }

    private function order(ClientMedication $med, string $status, ?string $expected, User $actor): MedicationPharmacyOrder
    {
        return MedicationPharmacyOrder::create(['client_id' => $med->client_id, 'client_medication_id' => $med->id,
            'pharmacy_name' => 'Synthetic pharmacy', 'quantity_ordered' => 28, 'quantity_received' => $status === 'part_received' ? 10 : 0,
            'status' => $status, 'expected_delivery' => $expected, 'ordered_by' => $actor->id]);
    }

    /**
     * Two people at Kōwhai: low, expiring, expired-out, fine and one controlled
     * medicine; orders in every state the meters read; one count to sign off;
     * and a low medicine at another house that no meter may count.
     */
    private function house(): array
    {
        config(['medications.stock_lots_enabled' => true]);
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['is_active' => true, 'name' => 'Synthetic Kōwhai House']);
        $elsewhere = Site::factory()->create(['is_active' => true, 'name' => 'Synthetic other house']);
        $lead = $this->stockUser($site, true);
        $reader = $this->stockUser($site, false);
        $aroha = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $tama = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $outsider = Client::factory()->create(['site_id' => $elsewhere->id, 'status' => 'active']);
        $m = [
            'low' => $this->medicine($aroha, 'Alpha low', '3.00', '5.00', '2027-06-30', $lead),
            'expiring' => $this->medicine($aroha, 'Bravo expiring', '10.00', null, '2026-11-05', $lead),
            'expired' => $this->medicine($tama, 'Charlie expired', '6.00', null, '2026-10-01', $lead),
            'fine' => $this->medicine($tama, 'Delta fine', '20.00', null, '2027-06-30', $lead),
            'controlled' => $this->medicine($tama, 'Echo controlled', '2.00', '5.00', '2026-11-10', $lead, true),
            'outsider' => $this->medicine($outsider, 'Foxtrot elsewhere', '1.00', '5.00', '2026-10-01', $lead),
        ];
        $o = [
            'due' => $this->order($m['fine'], 'dispensed', '2026-10-20', $lead),
            'submitted' => $this->order($m['fine'], 'submitted', null, $lead),
            'part' => $this->order($m['expiring'], 'part_received', null, $lead),
            'draft' => $this->order($m['low'], 'draft', null, $lead),
            'controlled_due' => $this->order($m['controlled'], 'dispensed', '2026-10-20', $lead),
            'controlled_draft' => $this->order($m['controlled'], 'draft', null, $lead),
            'outsider_due' => $this->order($m['outsider'], 'dispensed', '2026-10-19', $lead),
        ];
        $lot = MedicationStockLot::whereHas('stock', fn ($q) => $q->where('client_medication_id', $m['fine']->id))->sole();
        $countId = $this->actingAs($lead)->postJson('/emar/stock/packs/commands', [
            'action' => 'count', 'client_medication_id' => $m['fine']->id, 'request_uuid' => (string) Str::uuid(),
            'lines' => [['lot_id' => $lot->id, 'revision' => $lot->revision, 'quantity' => '19.00']],
            'reason' => 'Synthetic count awaiting sign-off',
        ])->assertOk()->json('count_id');

        return compact('site', 'elsewhere', 'lead', 'reader', 'aroha', 'tama', 'm', 'o', 'countId');
    }

    public function test_the_six_approved_meters_count_the_houses_and_people_the_list_shows(): void
    {
        extract($this->house());
        $this->actingAs($lead)->get('/emar/stock/packs')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('emar/stock/StockHub')
            // Running low: at or below the reorder level, or out (an expired pack is not usable).
            ->where('metrics.running_low', 3)->where('metrics.running_low_first', 'Alpha low')
            // Expiring: open packs within 30 days, one already expired.
            ->where('metrics.expiring', 3)->where('metrics.expired', 1)
            // To receive: dispensed or part received; due by today; the rest of a part delivery.
            ->where('metrics.to_receive', 3)->where('metrics.to_receive_due', 2)->where('metrics.to_receive_part', 1)
            ->where('metrics.counts', 1)
            // Pharmacy orders: not yet dispensed; drafts not sent.
            ->where('metrics.pharmacy_orders', 3)->where('metrics.drafts', 2)
            // Medicines in stock, and the people they belong to.
            ->where('metrics.tracked', 5)->where('metrics.people', 2)
            ->where('metrics.controlled_hidden', 0)->where('controlled_left_out', false));
    }

    public function test_a_reader_without_controlled_view_counts_only_visible_medicines_and_is_told_so(): void
    {
        extract($this->house());
        $this->actingAs($reader)->get('/emar/stock/packs')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('metrics.running_low', 2)->where('metrics.running_low_first', 'Alpha low')
            ->where('metrics.expiring', 2)->where('metrics.expired', 1)
            ->where('metrics.to_receive', 2)->where('metrics.to_receive_due', 1)->where('metrics.to_receive_part', 1)
            ->where('metrics.pharmacy_orders', 2)->where('metrics.drafts', 1)
            ->where('metrics.tracked', 4)->where('metrics.people', 2)
            ->where('metrics.controlled_hidden', 1)->where('controlled_left_out', true)
            ->where('items.total', 4)
            ->where('items.data', fn ($rows) => collect($rows)->pluck('name')->doesntContain('Echo controlled')));
    }

    public function test_meters_follow_the_person_filter_and_running_low_opens_low_or_out(): void
    {
        extract($this->house());
        $this->actingAs($lead)->get('/emar/stock/packs?'.http_build_query(['client_id' => $aroha->id, 'site_id' => $site->id]))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('metrics.running_low', 1)->where('metrics.running_low_first', 'Alpha low')
                ->where('metrics.expiring', 1)->where('metrics.expired', 0)
                ->where('metrics.to_receive', 1)->where('metrics.to_receive_due', 0)->where('metrics.to_receive_part', 1)
                ->where('metrics.pharmacy_orders', 1)->where('metrics.drafts', 1)
                ->where('metrics.counts', 0)->where('metrics.tracked', 2)->where('metrics.people', 1));

        // The meter opens Show › Running low or out: low, out and controlled low, never the other house.
        $this->get('/emar/stock/packs?show=low')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('items.total', 3)
            ->where('items.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['Alpha low', 'Charlie expired', 'Echo controlled']));
    }

    /**
     * Approved P06 header "Receive a delivery" (Main, 20 Oct): a picker, not
     * a new flow — deliveries due from the pharmacy first, then the other
     * medicines at the house — handing over to the existing receive dialog
     * and its `receive` command.
     */
    public function test_receive_a_delivery_lists_deliveries_due_first_then_other_medicines_and_saves_with_the_receive_command(): void
    {
        extract($this->house());
        $choices = $this->actingAs($lead)->getJson('/emar/stock/packs/receive-choices')->assertOk();
        $this->assertStringContainsString('no-store', (string) $choices->headers->get('Cache-Control'));
        // Due by date first, then undated; never drafts, sent orders or the other house.
        $this->assertSame([$o['due']->id, $o['controlled_due']->id, $o['part']->id], array_column($choices->json('orders'), 'id'));
        $this->assertTrue($choices->json('orders.1.controlled'));
        // Then every other medicine that can be received at the house, by name.
        $this->assertSame(['Alpha low', 'Charlie expired'], array_column($choices->json('medicines'), 'name'));
        $this->assertFalse($choices->json('more'));

        $this->getJson('/emar/stock/packs/receive-choices?search=charlie')->assertOk()
            ->assertJsonPath('orders', [])->assertJsonPath('medicines.0.name', 'Charlie expired')->assertJsonCount(1, 'medicines');

        // The chosen delivery saves through the hub's own receive command.
        $receipt = ['action' => 'receive', 'client_medication_id' => $m['expiring']->id, 'request_uuid' => (string) Str::uuid(),
            'pharmacy_order_id' => $o['part']->id, 'source' => 'pharmacy', 'source_reference' => 'Pharmacy order #'.$o['part']->id,
            'label_checked' => true, 'packs' => [['quantity' => '18', 'batch_number' => 'SYN-REST', 'batch_not_printed' => false,
                'expiry_month' => '06/2027', 'expiry_not_printed' => false]]];
        $this->postJson('/emar/stock/packs/commands', $receipt)->assertOk();
        $this->assertSame('delivered', $o['part']->fresh()->status);
        $this->assertSame('28.00', ClientMedicationStock::where('client_medication_id', $m['expiring']->id)->value('on_hand'));
    }

    public function test_receive_a_delivery_keeps_the_list_scope_and_hides_controlled_receipts_from_readers_without_them(): void
    {
        extract($this->house());
        $choices = $this->actingAs($reader)->getJson('/emar/stock/packs/receive-choices?site_id='.$site->id)->assertOk();
        $this->assertSame([$o['due']->id, $o['part']->id], array_column($choices->json('orders'), 'id'));
        $this->assertSame(['Alpha low', 'Charlie expired'], array_column($choices->json('medicines'), 'name'));
        $this->assertStringNotContainsString('Echo controlled', $choices->getContent());

        $this->getJson('/emar/stock/packs/receive-choices?site_id='.$elsewhere->id)->assertNotFound();
        config(['medications.stock_lots_enabled' => false]);
        $this->getJson('/emar/stock/packs/receive-choices')->assertForbidden();
    }

    /**
     * Approved P06 stock table (Main, 20 Oct): whole-number quantities from
     * MedicationStockQuantity::display ("0" is a real balance, never a
     * missing one), the facts each row's state lines need — expired packs,
     * the next pack, the open order and the count to sign off — and the
     * people count for the "n shown · n people" caption.
     */
    public function test_stock_rows_carry_whole_number_quantities_and_the_facts_their_state_lines_need(): void
    {
        extract($this->house());
        // An order filed under the wrong person never reaches the medicine's row.
        MedicationPharmacyOrder::create(['client_id' => $tama->id, 'client_medication_id' => $m['low']->id, 'pharmacy_name' => 'Synthetic pharmacy',
            'quantity_ordered' => 5, 'quantity_received' => 0, 'status' => 'dispensed', 'ordered_by' => $lead->id]);
        $props = $this->actingAs($lead)->get('/emar/stock/packs')->assertOk()->inertiaProps();
        $rows = collect($props['items']['data'])->keyBy('name');
        $this->assertSame(2, $props['item_people']);

        $this->assertSame('3', $rows['Alpha low']['usable_display']);
        $this->assertSame('3', $rows['Alpha low']['physical_display']);
        $this->assertSame('5', $rows['Alpha low']['reorder_display']);
        $this->assertSame('0', $rows['Charlie expired']['usable_display']);
        $this->assertSame('6', $rows['Charlie expired']['physical_display']);
        $this->assertSame([['batch_number' => 'SYN-CHA', 'expiry_date' => '2026-10-01', 'quantity' => '6']], $rows['Charlie expired']['expired_lots']);
        $this->assertSame(0, $rows['Charlie expired']['usable_packs']);
        $this->assertSame(1, $rows['Bravo expiring']['usable_packs']);
        $this->assertSame(16, $rows['Bravo expiring']['next_expiry_in_days']);
        $this->assertSame('10', $rows['Bravo expiring']['next_quantity']);
        $this->assertNull($rows['Delta fine']['days_supply']);

        // The open order on the row: due deliveries before sent orders before drafts.
        $this->assertSame($o['due']->id, $rows['Delta fine']['open_order']['id']);
        $this->assertSame('dispensed', $rows['Delta fine']['open_order']['status']);
        $this->assertSame($o['part']->id, $rows['Bravo expiring']['open_order']['id']);
        $this->assertSame('18', $rows['Bravo expiring']['open_order']['still_to_come']);
        $this->assertSame($o['draft']->id, $rows['Alpha low']['open_order']['id']);
        $this->assertNull($rows['Charlie expired']['open_order']);
        $this->assertSame($countId, $rows['Delta fine']['count_to_sign_off']['id']);
        $this->assertNull($rows['Alpha low']['count_to_sign_off']);
        $this->assertContains('20', collect($props['movements']['data'])->pluck('quantity_display')->all());

        // Controlled concealment holds on every new fact.
        $hidden = $this->actingAs($reader)->get('/emar/stock/packs')->assertOk()->inertiaProps();
        $this->assertSame(['Alpha low', 'Bravo expiring', 'Charlie expired', 'Delta fine'], collect($hidden['items']['data'])->pluck('name')->all());
        $this->assertSame(2, $hidden['item_people']);
        $this->assertStringNotContainsString('Echo controlled', json_encode($hidden['items']).json_encode($hidden['movements']));
    }
}
