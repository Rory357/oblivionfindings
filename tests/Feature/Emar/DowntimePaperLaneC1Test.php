<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDowntime;
use App\Models\MedicationFollowup;
use App\Models\MedicationPaperEntry;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Downtime\DowntimeService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * eMAR Lane C1 — downtime and paper records (EA-001 with Main's D2, EA-027,
 * EA-028, EA-090, EA-095, EA-194). Synthetic people and medicines only.
 */
class DowntimePaperLaneC1Test extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $lead;

    private User $reviewer;

    private ClientMedication $order;

    private ClientMedicationStock $stock;

    private MedicationStockLot $lot;

    private array $lastPreview = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 00:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        // The key comes from the 2026_10_09_230200 migration on a deployed database and
        // from MedicationDowntimePermissionsSeeder on a fresh one. Before the
        // fix it doesn't exist, so the reproduction run creates it here.
        Permission::query()->firstOrCreate(['key' => 'medications.downtime.manage'], ['description' => 'Run medication downtimes', 'group' => 'medications', 'module' => 'Clinical']);
        Permission::query()->firstOrCreate(['key' => 'medications.reports.view'], ['description' => 'View medication reports', 'group' => 'medications', 'module' => 'Clinical']);
        if (class_exists(\Database\Seeders\MedicationDowntimePermissionsSeeder::class)) {
            $this->seed(\Database\Seeders\MedicationDowntimePermissionsSeeder::class);
        }
        config(['medications.stock_lots_enabled' => true]);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->lead = $this->staff('team_lead');
        $this->reviewer = $this->staff('clinical_lead');
        $this->order = ClientMedication::factory()->create(['client_id' => $this->client->id, 'name' => 'Synthetic paper medicine', 'dosage' => '10 mg', 'dose_amount' => 10, 'dose_unit' => 'mg',
            'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'verified_at' => now(), 'start_date' => '2026-10-01', 'end_date' => null,
            'dose_times' => ['09:00'], 'route' => 'oral', 'frequency' => 'Once daily', 'is_prn' => false, 'controlled_drug' => false, 'witness_required' => false]);
        // Pack tracking started after the downtime: the stock wasn't tracked
        // when the 09:10 paper dose was given (Main's D2).
        $started = Carbon::parse('2026-10-03 11:00', 'Pacific/Auckland')->utc();
        $this->stock = ClientMedicationStock::forceCreate(['client_medication_id' => $this->order->id, 'on_hand' => 90, 'unit' => 'mg', 'lots_started_at' => $started]);
        $this->lot = MedicationStockLot::create(['client_medication_stock_id' => $this->stock->id, 'batch_number' => 'SYN-D2', 'expiry_date' => '2027-09-30',
            'quantity_received' => 90, 'quantity_remaining' => 90, 'source' => 'recorded_balance', 'received_by' => null, 'received_at' => $started, 'revision' => 0, 'state' => 'open']);
        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00', 'Pacific/Auckland')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_paper_dose_from_before_pack_tracking_posts_clinically_and_waits_for_a_counted_recount(): void
    {
        $this->cover($this->lead);
        $downtime = $this->downtime();
        $entry = $this->capture($downtime);
        $this->assertTrue($this->lastPreview['untracked_stock'] ?? false, 'Paper from before pack tracking needs only the clinical facts.');
        $this->assertNull($entry->stock_evidence);

        $preview = $this->actingAs($this->lead)->getJson($this->url($entry).'/reconciliation')->assertOk()
            ->assertJsonPath('untracked_stock', true)->assertJsonPath('can_reconcile', true)->json();
        $this->assertNotContains('stock_settlement', $preview['missing_evidence']);
        $this->actingAs($this->lead)->post($this->url($entry).'/reconcile', ['preview_token' => $preview['preview_token'], 'accountable_confirmation' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        $admin = ClientMedicationAdministration::sole();
        $this->assertSame('given', $admin->status);
        $this->assertSame('10.00', $admin->quantity_given);
        $this->assertTrue($admin->administered_at->equalTo($entry->given_at));
        // Nothing comes off stock for it: the counted recount settles the stock.
        $this->assertSame(0, MedicationStockMovement::query()->where('administration_id', $admin->id)->count());
        $this->assertSame('90.00', $this->lot->fresh()->quantity_remaining);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $recount = MedicationFollowup::query()->where('source_key', 'stock-discrepancy:paper-'.$entry->id)->sole();
        $this->assertNull($recount->completed_at);
        $this->assertSame((int) $this->order->id, (int) $recount->client_medication_id);

        // Default setting: count first, then finish.
        $this->actingAs($this->lead)->get('/emar/downtime/'.$downtime->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('can_finish', false)->where('recount_before_finish', true)
                ->where('recounts.0.paper_entry_id', $entry->id)->where('recounts.0.url', '/emar/stock/packs?view=counts&medication_id='.$this->order->id));
        $this->actingAs($this->lead)->post('/emar/downtime/'.$downtime->id.'/finish')->assertSessionHasErrors('finish');
        $this->assertNull($downtime->fresh()->finished_at);

        // The P06 count completes the recount; the downtime can then finish.
        $this->actingAs($this->reviewer)->postJson('/emar/stock/packs/commands', [
            'action' => 'count', 'client_medication_id' => $this->order->id, 'request_uuid' => (string) Str::uuid(),
            'lines' => [['lot_id' => $this->lot->id, 'revision' => $this->lot->fresh()->revision, 'quantity' => '80.00']],
            'reason' => 'Synthetic recount after the paper dose',
        ])->assertOk();
        $this->assertNotNull($recount->fresh()->completed_at);
        $this->actingAs($this->lead)->post('/emar/downtime/'.$downtime->id.'/finish')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($downtime->fresh()->finished_at);
    }

    public function test_with_the_recount_setting_off_the_downtime_finishes_and_the_recount_stays_in_follow_ups(): void
    {
        AppSetting::create(['key' => 'medications.downtime.recount_before_finish', 'value' => 'no']);
        $this->cover($this->lead);
        $downtime = $this->downtime();
        $entry = $this->capture($downtime);
        $this->post_($entry);

        $this->actingAs($this->lead)->post('/emar/downtime/'.$downtime->id.'/finish')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($downtime->fresh()->finished_at);
        $this->assertNull(MedicationFollowup::query()->where('source_key', 'stock-discrepancy:paper-'.$entry->id)->sole()->completed_at);
    }

    public function test_untracked_paper_has_no_pack_settlement_and_says_so_on_the_form(): void
    {
        $downtime = $this->downtime();
        $entry = $this->capture($downtime);
        $this->actingAs($this->reviewer)->post($this->url($entry).'/stock-evidence', ['request_uuid' => (string) Str::uuid(), 'settlement' => 'deduct_now', 'accountable_confirmation' => true,
            'lines' => [['lot_id' => $this->lot->id, 'revision' => 0, 'closing_quantity' => 80]]])->assertSessionHasErrors('settlement');
        $this->assertDatabaseCount('medication_paper_stock_evidence', 0);
    }

    public function test_downtime_authority_is_a_permission_that_also_makes_the_pack(): void
    {
        // EA-090: any role can be given it in Settings › Roles.
        $worker = $this->staff('support_worker', ['medications.downtime.manage']);
        $this->actingAs($worker)->post('/emar/downtime', $this->declaration())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, MedicationDowntime::query()->count());
        // A team lead without it can't run a downtime.
        $denied = $this->staff('team_lead');
        $denied->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'medications.downtime.manage')->firstOrFail()->id => ['allowed' => false]]);
        $this->actingAs($denied->fresh())->post('/emar/downtime', $this->declaration())->assertForbidden();
        // EA-027 (approved P10): house leads make the pack without report export.
        $this->assertFalse($this->lead->canDo('medications.reports.export'));
        $this->actingAs($this->lead)->get('/emar/downtime')->assertOk()->assertInertia(fn ($page) => $page->where('can_make_pack', true));
    }

    public function test_a_changed_order_is_reviewed_by_the_lead_so_the_downtime_can_finish(): void
    {
        $downtime = $this->downtime();
        $dose = $downtime->doses()->firstOrFail();
        ClientMedication::withoutEvents(fn () => $this->order->forceFill(['instructions' => 'Synthetic GP change after the downtime was listed'])->save());

        $this->actingAs($this->lead)->get('/emar/downtime/'.$downtime->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('doses.0.resolution_choices.0.kind', 'order_changed')->where('can_finish', false));
        $this->actingAs($this->lead)->post('/emar/downtime/'.$downtime->id.'/doses/'.$dose->id.'/resolve', [
            'kind' => 'order_changed', 'record_id' => null, 'accountable_confirmation' => true,
            'reason' => 'Checked the signed paper against the changed order; the paper dose is kept with the downtime for clinical review.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('medication_downtime_resolutions', ['downtime_dose_id' => $dose->id, 'administration_id' => null, 'paper_entry_id' => null, 'resolved_by' => $this->lead->id]);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->actingAs($this->lead)->post('/emar/downtime/'.$downtime->id.'/finish')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($downtime->fresh()->finished_at);
    }

    public function test_recovery_review_problems_come_back_as_form_errors(): void
    {
        $giver = $this->staff('support_worker');
        $downtime = $this->downtime();
        $entry = $this->capture($downtime, ['given_by' => $giver->id]);
        // The giver hasn't confirmed their paper yet: shown on the form, not as an error page.
        $this->actingAs($this->reviewer)->post($this->url($entry).'/recovery-authorization', ['reason' => 'Synthetic review', 'accountable_confirmation' => true])
            ->assertRedirect()->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('medication_paper_recovery_authorizations', 0);
    }

    public function test_downtime_list_meters_count_the_whole_scope_not_the_page(): void
    {
        $giver = $this->staff('support_worker');
        $first = $this->downtime();
        $this->capture($first, ['given_by' => $giver->id]);
        // The next day's downtime (dose history starts on 3 October).
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00', 'Pacific/Auckland')->utc());
        $this->downtime('2026-10-04T08:30', '2026-10-04T10:30');

        // Lead: the 4 Oct listed dose is still to enter; the giver still has to confirm.
        $this->actingAs($this->lead)->get('/emar/downtime')->assertOk()->assertInertia(fn ($page) => $page
            ->where('meters.to_enter', 1)->where('meters.to_confirm', 1)->where('meters.this_month', 2));
        // The giver sees their own confirmation, nothing to enter.
        $this->actingAs($giver)->get('/emar/downtime')->assertOk()->assertInertia(fn ($page) => $page
            ->where('meters.to_enter', 0)->where('meters.to_confirm', 1)->where('meters.this_month', 1));
    }

    private function post_(MedicationPaperEntry $entry): void
    {
        $preview = $this->actingAs($this->lead)->getJson($this->url($entry).'/reconciliation')->assertOk()->assertJsonPath('can_reconcile', true)->json();
        $this->actingAs($this->lead)->post($this->url($entry).'/reconcile', ['preview_token' => $preview['preview_token'], 'accountable_confirmation' => true])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    private function staff(string $role, array $extra = []): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2026-01-01']);
        $keys = ['medications.view', 'medications.administer.record', 'clients.viewAny', 'medications.controlled.view', 'medications.stock.update', 'medications.reports.view', ...$extra];
        $user->permissionOverrides()->sync(Permission::whereIn('key', $keys)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        MedicationCompetencyAssessment::create(['user_id' => $user->id, 'assessor_id' => User::factory()->create(['approved_at' => now()])->id,
            'assessment_type' => 'annual', 'status' => 'passed', 'assessment_date' => '2026-09-01', 'expiry_date' => '2027-09-01', 'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(), 'can_administer_unsupervised' => true]);

        return $user->fresh();
    }

    private function cover(User $user): Shift
    {
        return Shift::factory()->create(['user_id' => $user->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'starts_at' => Carbon::parse('2026-10-03 08:00', 'Pacific/Auckland')->utc(), 'ends_at' => Carbon::parse('2026-10-03 11:00', 'Pacific/Auckland')->utc(),
            'actual_starts_at' => Carbon::parse('2026-10-03 08:00', 'Pacific/Auckland')->utc(), 'actual_ends_at' => Carbon::parse('2026-10-03 11:00', 'Pacific/Auckland')->utc(), 'status' => 'completed']);
    }

    private function declaration(string $start = '2026-10-03T08:30', string $end = '2026-10-03T10:30'): array
    {
        return ['site_id' => $this->site->id, 'started_at' => $start, 'ended_at' => $end, 'description' => 'Synthetic connection interruption', 'request_uuid' => (string) Str::uuid()];
    }

    private function downtime(string $start = '2026-10-03T08:30', string $end = '2026-10-03T10:30'): MedicationDowntime
    {
        return app(DowntimeService::class)->declare($this->lead, $this->declaration($start, $end));
    }

    private function capture(MedicationDowntime $downtime, array $extra = []): MedicationPaperEntry
    {
        $data = array_merge(['client_medication_id' => $this->order->id, 'downtime_dose_id' => $downtime->doses()->firstOrFail()->id,
            'given_at' => '2026-10-03T09:10', 'given_by' => $this->lead->id, 'witness_id' => null, 'outcome' => 'given', 'dose_on_paper' => '10 mg', 'notes' => 'Synthetic signed paper',
            'observations' => [], 'clinical_facts' => ['quantity_given' => 10, 'amount_mode' => 'as_ordered']], $extra);
        $preview = $this->actingAs($this->lead)->postJson('/emar/downtime/'.$downtime->id.'/paper/preview', $data)->assertOk()->assertJsonPath('can_submit', true)->json();
        $this->lastPreview = $preview;
        $this->actingAs($this->lead)->post('/emar/downtime/'.$downtime->id.'/paper', $data + ['preview_token' => $preview['preview_token'], 'request_uuid' => (string) Str::uuid(), 'accountable_confirmation' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        return MedicationPaperEntry::latest('id')->firstOrFail();
    }

    private function url(MedicationPaperEntry $entry): string
    {
        return '/emar/downtime/'.$entry->downtime_id.'/paper/'.$entry->id;
    }
}
