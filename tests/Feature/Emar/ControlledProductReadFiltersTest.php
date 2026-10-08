<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\ControlledDrugLossReport;
use App\Models\MedicationDestruction;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Controlled\ControlledProductPayload;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlledProductReadFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    private Site $site;

    private Client $person;

    private Client $other;

    private ClientMedication $medicine;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->reader->id, 'primary_site_id' => $this->site->id,
            'is_active' => true, 'start_date' => '2026-01-01',
        ]);
        // Exact read grants, approved Site, and explicit person assignments.
        $grants = ['medications.view', 'medications.controlled.view'];
        $deny = [...MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
            'clients.viewAny', 'medications.stock.update', 'medications.audit.view',
            'medications.reports.export', 'medications.reports.view'];
        $permissions = Permission::query()->whereIn('key', array_unique([...$grants, ...$deny]))->get();
        $this->reader->permissionOverrides()->sync($permissions->mapWithKeys(fn ($p) => [
            $p->id => ['allowed' => in_array($p->key, $grants, true)],
        ])->all());
        $this->reader = $this->reader->fresh();
        $this->person = Client::factory()->create(['site_id' => $this->site->id]);
        $this->other = Client::factory()->create(['site_id' => $this->site->id]);
        foreach ([$this->person, $this->other] as $person) {
            $person->supportWorkers()->attach($this->reader->id);
        }
        $this->medicine = $this->medicine($this->person);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_selected_house_branding_reaches_the_page_and_product_without_changing_evidence(): void
    {
        $this->site->update(['brand_colour' => '#2E7D32']);
        $this->entry();
        $before = $this->clinicalSnapshot();
        $filters = ['site_id' => $this->site->id, 'client_id' => $this->person->id];

        $product = $this->product($filters)->assertOk()
            ->assertJsonPath('site_brand_colour', '#2E7D32')
            ->assertJsonPath('sites.0', ['id' => $this->site->id, 'name' => $this->site->name])
            ->assertJsonCount(1, 'medicines')
            ->assertJsonPath('medicines.0.client_id', $this->person->id)
            ->json();
        $page = $this->actingAs($this->reader)->get(route('emar.controlled', $filters))->assertOk();
        $this->assertSame('#2E7D32', $page->inertiaProps('site_brand_colour'));
        $this->assertSame('#2E7D32', $page->inertiaProps('product.site_brand_colour'));
        $this->assertSame($product['can'], $page->inertiaProps('product.can'));
        $this->assertSame($before, $this->clinicalSnapshot(), 'Branding readers must not change clinical evidence');
    }

    public function test_all_house_branding_is_null_and_foreign_house_branding_is_concealed(): void
    {
        $this->site->update(['brand_colour' => '#2E7D32']);
        $foreign = Site::factory()->create(['is_active' => true, 'brand_colour' => '#FF00FF']);

        $product = $this->actingAs($this->reader)->getJson(route('emar.controlled.product'))->assertOk()
            ->assertJsonPath('site_brand_colour', null)
            ->assertJsonCount(1, 'sites')
            ->assertDontSee('#FF00FF')->json();
        $page = $this->actingAs($this->reader)->get(route('emar.controlled'))->assertOk();
        $this->assertNull($page->inertiaProps('site_brand_colour'));
        $this->assertNull($page->inertiaProps('product.site_brand_colour'));
        $this->assertSame($product['can'], $page->inertiaProps('product.can'));

        $this->product(['site_id' => $foreign->id])->assertNotFound()->assertDontSee('#FF00FF');
        $this->actingAs($this->reader)->get(route('emar.controlled', ['site_id' => $foreign->id]))
            ->assertNotFound()->assertDontSee('#FF00FF');
    }

    public function test_person_scope_and_nz_day_boundaries_include_dst_transition_days(): void
    {
        $otherMedicine = $this->medicine($this->other);
        foreach (['2026-07-01', '2026-10-01', '2026-04-05', '2026-09-27'] as $day) {
            $start = CarbonImmutable::parse($day, 'Pacific/Auckland')->startOfDay();
            $end = $start->addDay();
            $this->entry(['recorded_at' => $start->subSecond()->utc(), 'notes' => 'before-day-sentinel']);
            $first = $this->entry(['recorded_at' => $start->utc()]);
            $last = $this->entry(['recorded_at' => $end->subSecond()->utc()]);
            $this->entry(['recorded_at' => $end->utc(), 'notes' => 'next-day-sentinel']);
            $this->entry(['client_id' => $this->other->id, 'client_medication_id' => $otherMedicine->id,
                'recorded_at' => $start->utc(), 'notes' => 'other-person-sentinel']);

            $response = $this->product(['client_id' => $this->person->id, 'date' => $day])->assertOk()
                ->assertJsonCount(2, 'entries')->assertJsonCount(1, 'medicines')
                ->assertJsonPath('filters.client_id', $this->person->id)->assertJsonPath('filters.date', $day)
                ->assertDontSee('before-day-sentinel')->assertDontSee('next-day-sentinel')->assertDontSee('other-person-sentinel');
            $this->assertEqualsCanonicalizing([$first->id, $last->id], $response->json('entries.*.id'));
            $this->assertEqualsCanonicalizing([$this->person->id, $this->other->id], $response->json('people.*.id'));
        }
        $page = $this->actingAs($this->reader)->get(route('emar.controlled', [
            'site_id' => $this->site->id, 'client_id' => $this->person->id, 'date' => '2026-09-27',
        ]))->assertOk();
        $this->assertSame($this->person->id, $page->inertiaProps('product.filters.client_id'));
        $this->assertSame('2026-09-27', $page->inertiaProps('product.filters.date'));
    }

    public function test_day_filter_precedes_the_history_limit_and_never_replaces_current_stock_or_outstanding_work(): void
    {
        $old = $this->entry(['recorded_at' => Carbon::parse('2020-01-01 12:00', 'Pacific/Auckland')->utc(), 'on_hand_after' => 2]);
        $row = $this->entry()->getAttributes();
        unset($row['id']);
        DB::table('client_controlled_drug_entries')->insert(array_fill(0, ControlledProductPayload::HISTORY_LIMIT + 1, $row));
        $count = $this->entry(['entry_type' => 'balance_check', 'on_hand_after' => 18]);
        ClientMedicationStock::create(['client_medication_id' => $this->medicine->id, 'on_hand' => 18, 'unit' => 'tablets']);
        ClientControlledDrugDiscrepancy::create(['client_id' => $this->person->id, 'client_medication_id' => $this->medicine->id,
            'status' => 'open', 'notes' => 'Current discrepancy', 'reported_at' => now()]);
        ControlledDrugLossReport::create(['client_id' => $this->person->id, 'client_medication_id' => $this->medicine->id,
            'medication_name' => $this->medicine->name, 'quantity_lost' => 1, 'circumstances' => 'Current loss',
            'discovered_by' => $this->reader->id, 'discovered_at' => now(), 'investigation_status' => 'reported']);
        MedicationDestruction::create(['client_id' => $this->person->id, 'client_medication_id' => $this->medicine->id,
            'site_id' => $this->site->id, 'medication_name' => $this->medicine->name, 'quantity' => 1,
            'reason' => 'expired', 'disposal_method' => 'pharmacy_return', 'is_controlled_drug' => true,
            'destroyed_by' => $this->reader->id, 'witness_1_id' => $this->reader->id, 'destroyed_at' => now()]);
        $before = $this->clinicalSnapshot();
        $current = $this->product(['client_id' => $this->person->id])->assertOk()
            ->assertJsonCount(ControlledProductPayload::HISTORY_LIMIT, 'entries')->assertJsonPath('history_has_more.entries', true)->json();
        $history = $this->product(['client_id' => $this->person->id, 'date' => '2020-01-01'])->assertOk()
            ->assertJsonCount(1, 'entries')->assertJsonPath('entries.0.id', $old->id)->assertJsonPath('history_has_more.entries', false)->json();
        $this->assertSame($count->id, $history['medicines'][0]['entry_version']);
        $this->assertSame($count->id, $history['medicines'][0]['count']['last_entry_id']);
        $this->assertEquals(18, $history['medicines'][0]['balance']);
        foreach (['medicines', 'discrepancies', 'losses', 'destructions', 'requests', 'overrides', 'meters'] as $key) {
            $this->assertSame($current[$key], $history[$key], $key.' must stay current');
        }
        foreach (['discrepancies', 'losses', 'destructions'] as $key) {
            $this->assertCount(1, $history[$key]);
        }
        $this->assertSame($before, $this->clinicalSnapshot(), 'Readers must not mutate clinical evidence');
    }

    public function test_foreign_unassigned_missing_and_mismatched_person_filters_are_concealed(): void
    {
        $hidden = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Unassigned-sentinel']);
        $foreign = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id,
            'first_name' => 'Foreign-sentinel']);
        $foreign->supportWorkers()->attach($this->reader->id);
        foreach ([$hidden->id, $foreign->id, 999999999] as $id) {
            $this->product(['client_id' => $id])->assertNotFound()->assertDontSee('sentinel');
            $this->actingAs($this->reader)->get(route('emar.controlled', ['client_id' => $id]))->assertNotFound();
        }
        $this->product(['client_id' => $this->other->id, 'client_medication_id' => $this->medicine->id])->assertNotFound();
        $this->product()->assertOk()->assertJsonCount(2, 'people')->assertDontSee('sentinel');
        foreach (['2026-02-30', '03/10/2026', '2026-10-03T12:00:00Z'] as $date) {
            $this->product(['date' => $date])->assertUnprocessable()->assertJsonValidationErrors('date');
        }
    }

    public function test_legacy_reader_redirects_retain_validated_scope_and_day(): void
    {
        $filters = ['site_id' => $this->site->id, 'client_medication_id' => $this->medicine->id,
            'client_id' => $this->person->id, 'date' => '2020-01-01'];
        $page = $this->actingAs($this->reader)->get(route('emar.destructions', $filters))->assertOk();
        $this->assertEquals($filters, $page->inertiaProps('filters'));
        $this->assertSame([], $page->inertiaProps('destructions'));
        $this->actingAs($this->reader)->get(route('emar.destructions', ['client_id' => 999999999]))->assertNotFound();
        foreach (['emar.cd_loss.index' => 'losses'] as $route => $view) {
            $this->actingAs($this->reader)->get(route($route, $filters))->assertRedirect(
                '/emar/controlled?'.http_build_query(['view' => $view, ...$filters]),
            );
            $this->actingAs($this->reader)->get(route($route, ['client_id' => 999999999]))->assertNotFound();
        }
    }

    public function test_ordinary_stock_reader_sees_only_ordinary_disposals_and_keeps_person_and_site_boundaries(): void
    {
        $ordinary = ClientMedication::factory()->create(['client_id' => $this->person->id, 'name' => 'Synthetic ordinary medicine',
            'controlled_drug' => false, 'state' => 'active', 'active' => true, 'approval_status' => 'verified']);
        foreach ([$ordinary, $this->medicine] as $medicine) {
            MedicationDestruction::create(['client_id' => $this->person->id, 'site_id' => $this->site->id,
                'client_medication_id' => $medicine->id, 'medication_name' => $medicine->name, 'quantity' => 1,
                'unit' => 'tablet', 'reason' => 'expired', 'disposal_method' => 'pharmacy_return',
                'is_controlled_drug' => $medicine->controlled_drug, 'witness_1_id' => $this->reader->id, 'destroyed_by' => $this->reader->id, 'destroyed_at' => now()]);
        }
        $all = $this->actingAs($this->reader)->get(route('emar.destructions'))->assertOk();
        $this->assertCount(2, $all->inertiaProps('destructions'));
        $this->reader->permissionOverrides()->syncWithoutDetaching(Permission::whereIn('key', ['medications.controlled.view', 'medications.controlled.record', 'medications.stock.update'])
            ->get()->mapWithKeys(fn ($p) => [$p->id => ['allowed' => $p->key === 'medications.stock.update']])->all());
        Cache::flush();
        $this->reader = $this->reader->fresh();
        $before = $this->clinicalSnapshot();
        $page = $this->actingAs($this->reader)->get(route('emar.destructions'))->assertOk();
        $this->assertSame([$ordinary->id], array_column($page->inertiaProps('medications'), 'id'));
        $this->assertSame(['Synthetic ordinary medicine'], array_column($page->inertiaProps('destructions'), 'medication_name'));
        $this->assertFalse($page->inertiaProps('can_record'));
        $this->assertSame([], $page->inertiaProps('staff'));
        $this->actingAs($this->reader)->get(route('emar.destructions', ['client_medication_id' => $this->medicine->id]))->assertNotFound();
        $foreign = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id]);
        $this->actingAs($this->reader)->get(route('emar.destructions', ['client_id' => $foreign->id]))->assertNotFound();
        $this->assertSame($before, $this->clinicalSnapshot());
        $this->actingAs($this->reader)->post(route('emar.destructions.store'), ['client_medication_id' => $ordinary->id])->assertForbidden();
    }

    public function test_exact_entry_selection_survives_history_limit_and_rejects_mismatched_person_and_day(): void
    {
        $old = $this->entry();
        $row = $old->getAttributes();
        unset($row['id']);
        DB::table('client_controlled_drug_entries')->insert(array_fill(0, ControlledProductPayload::HISTORY_LIMIT + 1, $row));
        $filters = ['client_id' => $this->person->id, 'client_medication_id' => $this->medicine->id, 'entry_id' => $old->id];
        $product = $this->product($filters)->assertOk()->assertJsonPath('selected_entry_id', $old->id);
        $this->assertContains($old->id, $product->json('entries.*.id'));
        $this->product([...$filters, 'client_id' => $this->other->id])->assertNotFound();
        $this->product([...$filters, 'date' => '2020-01-01'])->assertNotFound();
    }

    public function test_exact_ordinary_disposal_selection_survives_limit_without_disclosing_controlled_records(): void
    {
        $ordinary = ClientMedication::factory()->create(['client_id' => $this->person->id, 'controlled_drug' => false]);
        $disposal = MedicationDestruction::create(['client_id' => $this->person->id, 'site_id' => $this->site->id,
            'client_medication_id' => $ordinary->id, 'medication_name' => $ordinary->name, 'quantity' => 1,
            'unit' => 'tablet', 'reason' => 'expired', 'disposal_method' => 'pharmacy_return', 'is_controlled_drug' => false, 'witness_1_id' => $this->reader->id,
            'destroyed_by' => $this->reader->id, 'destroyed_at' => now()->subDay()]);
        $row = $disposal->getAttributes();
        unset($row['id']);
        $row['destroyed_at'] = now()->toDateTimeString();
        DB::table('medication_destructions')->insert(array_fill(0, 301, $row));
        $controlled = $disposal->replicate()->fill(['client_medication_id' => $this->medicine->id, 'is_controlled_drug' => true]);
        $controlled->save();
        $this->reader->permissionOverrides()->syncWithoutDetaching(Permission::whereIn('key', ['medications.controlled.view', 'medications.stock.update'])
            ->get()->mapWithKeys(fn ($p) => [$p->id => ['allowed' => $p->key === 'medications.stock.update']])->all());
        Cache::flush();
        $this->reader = $this->reader->fresh();
        $page = $this->actingAs($this->reader)->get(route('emar.destructions', ['client_id' => $this->person->id, 'destruction_id' => $disposal->id]))->assertOk();
        $this->assertSame($disposal->id, $page->inertiaProps('selected_destruction_id'));
        $this->assertContains($disposal->id, array_column($page->inertiaProps('destructions'), 'id'));
        $this->get(route('emar.destructions', ['destruction_id' => $controlled->id]))->assertNotFound();
        $this->get(route('emar.destructions', ['client_id' => $this->other->id, 'destruction_id' => $disposal->id]))->assertNotFound();
    }

    private function product(array $filters = [])
    {
        return $this->actingAs($this->reader)->getJson(route('emar.controlled.product', ['site_id' => $this->site->id, ...$filters]));
    }

    private function medicine(Client $person): ClientMedication
    {
        return ClientMedication::factory()->create(['client_id' => $person->id, 'name' => 'Synthetic controlled medicine '.$person->id,
            'controlled_drug' => true, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
            'frequency' => 'PRN', 'end_date' => null]);
    }

    private function entry(array $overrides = []): ClientControlledDrugEntry
    {
        return ClientControlledDrugEntry::create(array_replace(['client_id' => $this->person->id,
            'client_medication_id' => $this->medicine->id, 'entry_type' => 'receipt', 'quantity' => 1,
            'unit' => 'tablets', 'on_hand_before' => 1, 'on_hand_after' => 2, 'recorded_at' => now(),
            'recorded_by' => $this->reader->id, 'witnessed_by' => $this->reader->id], $overrides));
    }

    private function clinicalSnapshot(): array
    {
        return collect(['client_medications', 'client_medication_stocks', 'client_controlled_drug_entries',
            'client_controlled_drug_discrepancies', 'controlled_drug_loss_reports', 'medication_destructions'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
