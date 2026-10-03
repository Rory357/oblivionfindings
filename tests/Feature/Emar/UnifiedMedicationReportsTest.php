<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Reporting\MedicationReportAccess;
use App\Services\Medication\Reporting\MedicationReportPeriod;
use App\Services\Medication\Reporting\MedicationPdfDataset;
use App\Services\Medication\Reporting\MedicationReportDataset;
use App\Services\Medication\Reporting\RecordsReportingSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-09-29 12:00', 'Pacific/Auckland')->utc());
    $this->site = Site::factory()->create(['is_active' => true]);
    $this->person = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Synthetic', 'last_name' => 'Person']);
});
afterEach(fn () => Carbon::setTestNow());

function p09Reader(string $role, Site $site): User
{
    $user = User::factory()->create(['role' => $role]);
    $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
    ensureCanonicalHrStaffProfile($user, $site);

    return $user->fresh();
}
function p09Medicine(Client $client, array $attributes = []): ClientMedication
{
    return ClientMedication::query()->create(array_replace(['client_id' => $client->id, 'name' => 'Synthetic medicine', 'dosage' => '1 tablet', 'frequency' => 'Daily', 'dose_times' => ['07:00'], 'is_prn' => false, 'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'start_date' => '2026-09-29'], $attributes));
}
function p09ExportData(Site $site, string $type = 'doses'): array
{
    return ['type' => $type, 'site_id' => $site->id, 'period' => 'custom', 'date_from' => '2026-09-29', 'date_to' => '2026-09-29', 'purpose' => 'audit'];
}

it('uses the new medication report grant rather than generic reporting access', function () {
    $user = p09Reader('support_worker', $this->site);
    $ids = Permission::whereIn('key', ['reports.viewAny', 'medications.view'])->pluck('id');
    $user->permissionOverrides()->sync($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
    $this->actingAs($user->fresh())->get('/emar/reports')->assertForbidden();
    $this->get('/reports/modules/medication_administrations')->assertForbidden();
    $this->get('/reports/modules/medication_administrations/export')->assertForbidden();
    $combined = $this->get('/reports/combined/care-quality')->assertOk();
    expect(collect($combined->inertiaProps('metrics'))->pluck('label'))->not->toContain('Medication exceptions (7d)');
    expect(collect($combined->inertiaProps('sections'))->pluck('title'))->not->toContain('Recent Medication Exceptions');
    $csv = $this->get('/reports/combined/care-quality/export')->assertOk()->streamedContent();
    expect($csv)->not->toContain('Medication exceptions', 'Recent Medication Exceptions', 'Open controlled discrepancies');
    $this->get('/reports/combined/workforce-operations/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $this->actingAs(p09Reader('auditor', $this->site))->get('/emar/reports')->assertOk()->assertInertia(fn ($page) => $page->component('emar/reports/hub')->where('filters.report', 'doses')->where('data.totals.given_rate', null));
});

it('routes generic clinical medication downloads to the guarded purpose flow without releasing CSV', function () {
    $actor = p09Reader('admin', $this->site);
    $this->actingAs($actor)->get('/reports/modules/medication_administrations')->assertOk()->assertInertia(fn ($page) => $page->where('module.export_route', '/emar/reports?view=exports')->where('module.export_label', 'Print & exports'));
    $this->get('/reports/modules/medication_administrations/export')->assertRedirect('/emar/reports?view=exports');
    $this->get('/reports/modules/controlled_drug_discrepancies/export')->assertRedirect('/emar/reports?view=exports');
    $this->get('/reports/combined/care-quality')->assertOk()->assertInertia(fn ($page) => $page->where('report.export_route', '/emar/reports?view=exports'));
    $this->get('/reports/combined/care-quality/export')->assertRedirect('/emar/reports?view=exports');
    $csv = $this->get('/reports/combined/compliance-risk/export')->assertOk()->streamedContent();
    expect($csv)->not->toContain('Break-glass accesses');
    expect(MedicationEvent::count())->toBe(0);
});

it('keeps finance out of generic clinical rows even with generic reporting authority', function () {
    $actor = p09Reader('finance', $this->site);
    $id = Permission::where('key', 'reports.viewAny')->value('id');
    $actor->permissionOverrides()->syncWithoutDetaching([$id => ['allowed' => true]]);
    $this->actingAs($actor->fresh())->get('/reports/modules/medication_administrations')->assertForbidden();
    $this->get('/reports/modules/medication_administrations/export')->assertForbidden();
    $this->get('/reports/modules/controlled_drug_discrepancies')->assertForbidden();
    $combined = $this->get('/reports/combined/care-quality')->assertOk();
    expect(collect($combined->inertiaProps('report.modules')))->not->toContain('medication_administrations', 'controlled_drug_discrepancies');
    expect(collect($combined->inertiaProps('metrics'))->pluck('label'))->not->toContain('Medication exceptions (7d)');
    expect(collect($combined->inertiaProps('sections'))->pluck('title'))->not->toContain('Recent Medication Exceptions');
    $csv = $this->get('/reports/combined/care-quality/export')->assertOk()->streamedContent();
    expect($csv)->not->toContain('Medication exceptions', 'Recent Medication Exceptions', 'Open controlled discrepancies');
    expect(MedicationEvent::count())->toBe(0);
});

it('keeps finance stock aggregated and denies person and clinical reports', function () {
    $medicine = p09Medicine($this->person);
    ClientMedicationStock::create(['client_medication_id' => $medicine->id, 'on_hand' => 12, 'unit' => 'tablet']);
    $finance = p09Reader('finance', $this->site);
    $response = $this->actingAs($finance)->get('/emar/reports');
    $response->assertOk()->assertInertia(fn ($page) => $page->where('filters.report', 'stock')->where('finance', true)->where('page.data.0.on_hand', 12)->missing('page.data.0.client_id')->missing('page.data.0.person')->missing('page.data.0.reference'));
    $this->get('/emar/reports?report=doses')->assertForbidden();
    $this->get('/emar/reports?report=stock&client_id='.$this->person->id)->assertForbidden();
    $this->postJson('/emar/reports/export', p09ExportData($this->site, 'stock'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect(MedicationEvent::where('kind', 'export.created')->count())->toBe(1);
});

it('requires a purpose and gives the auditor audit-only export rights', function () {
    $actor = p09Reader('auditor', $this->site);
    $access = app(MedicationReportAccess::class);
    expect($access->canExport($actor, 'audit'))->toBeTrue()->and($access->canExport($actor, 'doses'))->toBeFalse();
    $this->actingAs(p09Reader('coordinator', $this->site))->postJson('/emar/reports/export', array_diff_key(p09ExportData($this->site), ['purpose' => true]))->assertUnprocessable()->assertJsonValidationErrors('purpose');
    expect(MedicationEvent::count())->toBe(0);
    $this->postJson('/emar/reports/export', p09ExportData($this->site))->assertOk();
    expect(MedicationEvent::where('kind', 'export.created')->first()->facts['purpose'])->toBe('Audit or inspection');
});

it('rejects a multi-person PDF when an included person moves during rendering', function () {
    p09Medicine($this->person);
    $other = Site::factory()->create(['is_active' => true]);
    $renderer = Mockery::mock(Barryvdh\DomPDF\PDF::class);
    Pdf::shouldReceive('setOption')->once()->andReturn($renderer);
    $renderer->shouldReceive('loadView')->once()->andReturnSelf();
    $renderer->shouldReceive('setPaper')->once()->andReturnSelf();
    $renderer->shouldReceive('output')->once()->andReturnUsing(function () use ($other) {
        $this->person->update(['site_id' => $other->id]);

        return '%PDF-sensitive-synthetic-person';
    });
    $response = $this->actingAs(p09Reader('admin', $this->site))->postJson('/emar/reports/export', p09ExportData($this->site, 'round_sheet'));
    $response->assertConflict();
    expect($response->getContent())->not->toContain('%PDF-sensitive')->and(MedicationEvent::count())->toBe(0);
});

it('buffers retained streaming routes before rechecking every person', function () {
    $other = Site::factory()->create(['is_active' => true]);
    Route::get('/p09-synthetic-export', function () use ($other) {
        return response()->streamDownload(function () use ($other) {
            $this->person->update(['site_id' => $other->id]);
            echo 'sensitive-person';
        }, 'synthetic.csv');
    })->middleware(['auth', App\Http\Middleware\MedicationExportGuard::class.':doses']);
    $this->actingAs(p09Reader('admin', $this->site))->getJson('/p09-synthetic-export?'.http_build_query(p09ExportData($this->site)))->assertConflict();
    expect(MedicationEvent::count())->toBe(0);
});

it('fails closed without releasing bytes when the export recorder fails', function () {
    $actor = p09Reader('admin', $this->site);
    MedicationEvent::creating(fn () => throw new RuntimeException('Synthetic export ledger failure'));
    $this->withoutExceptionHandling();
    try {
        expect(fn () => $this->actingAs($actor)->postJson('/emar/reports/export', p09ExportData($this->site)))->toThrow(RuntimeException::class, 'Synthetic export ledger failure');
        expect(MedicationEvent::count())->toBe(0)->and(DB::table('medication_event_heads')->where('site_id', $this->site->id)->exists())->toBeFalse();
    } finally {
        MedicationEvent::flushEventListeners();
        MedicationEvent::clearBootedModels();
    }
});

it('retains ceased and superseded orders in the historical MAR evidence', function () {
    $medicine = p09Medicine($this->person);
    // Synthetic historical snapshot; no clinical command or production write.
    DB::table('client_medications')->where('id', $medicine->id)->update(['active' => false, 'state' => 'ceased', 'ceased_at' => now()->toDateTimeString(), 'ceased_reason' => 'Synthetic stop']);
    $period = new MedicationReportPeriod('2026-09-29', '2026-09-29');
    $evidence = app(MedicationPdfDataset::class)->read(p09Reader('admin', $this->site), 'mar', $period, [$this->site->id], $this->person->id, null);
    expect($evidence['orders'])->toHaveCount(1)->and($evidence['orders'][0]['ceased_at'])->not->toBeNull()->and($evidence['dates'])->toBe(['2026-09-29']);
});

it('uses NZ day bounds across daylight saving without changing the submitted period', function () {
    $period = new MedicationReportPeriod('2026-09-27', '2026-09-27');
    expect($period->bounds()[0]->toIso8601String())->toBe('2026-09-26T12:00:00+00:00')->and($period->bounds()[1]->diffInSeconds($period->bounds()[0], true))->toBeGreaterThan(82799)->toBeLessThan(82800);
    $this->actingAs(p09Reader('admin', $this->site))->getJson('/emar/reports?period=custom&date_from=2025-01-01&date_to=2026-09-29')->assertUnprocessable();
});

it('keeps SAC off by default and requires an explicit severe-harm rating when enabled', function () {
    $settings = app(RecordsReportingSettings::class);
    expect($settings->enabled())->toBeFalse()->and($settings->confirmation('yes', 'severe_permanent', null))->toBeNull();
    App\Models\AppSetting::updateOrCreate(['key' => RecordsReportingSettings::SAC], ['value' => 'on']);
    expect($settings->preselection('yes', 'severe_permanent'))->toBeNull()->and($settings->confirmation('no', 'none', null))->toBeNull();
    expect(fn () => $settings->confirmation('yes', 'severe_permanent', 3))->toThrow(Illuminate\Validation\ValidationException::class);
    expect($settings->confirmation('yes', 'severe_permanent', 2))->toBe(2);
});

it('reports actual error occurrence rather than the later report time', function () {
    $actor = p09Reader('admin', $this->site);
    App\Models\MedicationError::create(['client_id' => $this->person->id, 'error_type' => 'wrong_dose', 'severity' => 'minor', 'reached_client' => 'yes', 'harm_level' => 'none', 'description' => 'Synthetic account', 'reported_by' => $actor->id, 'occurred_at' => Carbon::parse('2026-09-27 10:00', 'Pacific/Auckland')->utc(), 'reported_at' => now(), 'status' => 'reported', 'workflow_stage' => 'triage']);
    $data = app(MedicationReportDataset::class)->read($actor, 'errors', new MedicationReportPeriod('2026-09-27', '2026-09-27'), [$this->site->id]);
    expect($data['totals']['reached'])->toBe(1)->and($data['rows'][0]['date'])->toBe('2026-09-27')->and($data['rows'][0]['status'])->toBe('triage');
    expect(app(MedicationReportDataset::class)->read($actor, 'errors', new MedicationReportPeriod('2026-09-29', '2026-09-29'), [$this->site->id])['rows'])->toBe([]);
});

it('reports usable packs while retaining unknown cost and controlled physical balance', function () {
    $actor = p09Reader('admin', $this->site);
    $medicine = p09Medicine($this->person);
    $stock = ClientMedicationStock::create(['client_medication_id' => $medicine->id, 'on_hand' => 99, 'unit' => 'tablet', 'reorder_level' => 6]);
    $stock->forceFill(['lots_started_at' => now()])->save();
    foreach ([['open', '2026-10-05', 5], ['open', '2026-09-28', 7], ['quarantined', '2026-10-05', 9], ['closed', '2026-10-05', 11]] as [$state, $expiry, $quantity]) {
        App\Models\MedicationStockLot::create(['client_medication_stock_id' => $stock->id, 'state' => $state, 'expiry_date' => $expiry, 'quantity_received' => $quantity, 'quantity_remaining' => $quantity, 'source' => 'synthetic', 'received_at' => now()]);
    }
    $controlled = p09Medicine($this->person, ['name' => 'Synthetic controlled', 'controlled_drug' => true]);
    $cdStock = ClientMedicationStock::create(['client_medication_id' => $controlled->id, 'on_hand' => 8, 'unit' => 'tablet']);
    $cdStock->forceFill(['lots_started_at' => now()])->save();
    $data = app(MedicationReportDataset::class)->read($actor, 'stock', new MedicationReportPeriod('2026-09-29', '2026-09-29'), [$this->site->id]);
    $row = collect($data['rows'])->firstWhere('reference', 'stock:'.$stock->id);
    expect($row['on_hand'])->toBe(5.0)->and($row['expiry_date'])->toBe('2026-10-05')->and($row['low'])->toBe(1)->and($row['value_on_hand'])->toBeNull()->and(collect($data['rows'])->firstWhere('reference', 'stock:'.$cdStock->id)['on_hand'])->toBe(8.0);
});

it('uses the actual review date and a witnessed ledger entry as count evidence', function () {
    $actor = p09Reader('admin', $this->site);
    App\Models\MedicationReview::create(['client_id' => $this->person->id, 'review_type' => 'routine', 'status' => 'completed', 'scheduled_date' => '2026-09-10', 'completed_date' => '2026-09-28', 'happened_at' => Carbon::parse('2026-09-29 09:00', 'Pacific/Auckland')->utc()]);
    $period = new MedicationReportPeriod('2026-09-29', '2026-09-29');
    $reviews = app(MedicationReportDataset::class)->read($actor, 'reviews', $period, [$this->site->id]);
    expect($reviews['totals']['done'])->toBe(1)->and($reviews['rows'][0]['completed_date'])->toBe('2026-09-29');
    $medicine = p09Medicine($this->person, ['controlled_drug' => true]);
    App\Models\ClientControlledDrugEntry::create(['client_id' => $this->person->id, 'client_medication_id' => $medicine->id, 'entry_type' => 'balance_check', 'quantity' => 0, 'on_hand_before' => 8, 'on_hand_after' => 8, 'recorded_at' => now(), 'recorded_by' => $actor->id, 'witnessed_by' => p09Reader('coordinator', $this->site)->id]);
    expect(app(MedicationReportDataset::class)->read($actor, 'controlled', $period, [$this->site->id])['totals']['counts'])->toBe(1);
});

it('keeps proposed versions out of historical dose instructions and separates equal names', function () {
    $actor = p09Reader('admin', $this->site);
    foreach (['1 tablet', '2 tablets'] as $dose) {
        $medicine = p09Medicine($this->person);
        $version = App\Models\MedicationOrderVersion::create(['client_id' => $this->person->id, 'client_medication_id' => $medicine->id, 'version_number' => 1, 'name' => 'Synthetic medicine', 'dosage' => $dose, 'route' => 'oral', 'changed_at' => now()->subDay()]);
        App\Models\MedicationOrderRevision::create(['client_id' => $this->person->id, 'client_medication_id' => $medicine->id, 'medication_order_version_id' => $version->id, 'base_version' => 1, 'status' => 'checked', 'checked_at' => now()->subDay(), 'entered_by' => $actor->id]);
        $proposal = App\Models\MedicationOrderVersion::create(['client_id' => $this->person->id, 'client_medication_id' => $medicine->id, 'version_number' => 2, 'name' => 'Synthetic medicine', 'dosage' => '99 tablets', 'route' => 'oral', 'changed_at' => now()]);
        App\Models\MedicationOrderRevision::create(['client_id' => $this->person->id, 'client_medication_id' => $medicine->id, 'medication_order_version_id' => $proposal->id, 'base_version' => 1, 'status' => 'pending', 'entered_by' => $actor->id]);
        App\Models\MedicationDoseSlot::firstOrCreate(['client_medication_id' => $medicine->id, 'nz_date' => '2026-09-29', 'ordered_time' => '07:00'], ['client_id' => $this->person->id, 'due_at' => Carbon::parse('2026-09-29 07:00', 'Pacific/Auckland')->utc(), 'generated_at' => now(), 'controlled' => false]);
    }
    $data = app(MedicationPdfDataset::class)->read($actor, 'mar', new MedicationReportPeriod('2026-09-29', '2026-09-29'), [$this->site->id], $this->person->id, null);
    expect($data['chart'])->toHaveCount(2)->and(collect($data['chart'])->pluck('dose')->sort()->values()->all())->toBe(['1 tablet', '2 tablets']);
});

it('scopes both governance periods and keeps the target unconfigured and near misses without RAG', function () {
    $this->seed(Database\Seeders\GovernancePermissionsSeeder::class);
    $actor = p09Reader('team_lead', $this->site);
    $permissions = Permission::whereIn('key', ['governance.clinical.view', 'clinical.accessAllSites', 'sites.viewAll'])->get();
    $actor->permissionOverrides()->syncWithoutDetaching($permissions->mapWithKeys(fn ($p) => [$p->id => ['allowed' => $p->key === 'governance.clinical.view']])->all());
    $other = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id]);
    foreach ([[$this->person, '2026-09-15', 'yes'], [$this->person, '2026-09-15', 'no'], [$this->person, '2026-08-15', 'yes'], [$other, '2026-09-15', 'yes'], [$other, '2026-08-15', 'yes']] as [$person, $day, $reach]) {
        App\Models\MedicationError::create(['client_id' => $person->id, 'error_type' => 'wrong_dose', 'severity' => 'minor', 'reached_client' => $reach, 'harm_level' => 'none', 'description' => 'Synthetic account', 'reported_by' => $actor->id, 'occurred_at' => Carbon::parse($day.' 10:00', 'Pacific/Auckland')->utc(), 'reported_at' => now(), 'status' => 'reported']);
    }
    $response = $this->actingAs($actor->fresh())->get('/governance/clinical?site_id='.$this->site->id)->assertOk();
    $values = collect($response->inertiaProps('latestSnapshot.indicator_values'))->keyBy('indicator_code');
    expect($values['HCG-001']['value'])->toEqual(1)->and($values['HCG-001']['previous_value'])->toEqual(1)->and($values['HCG-001']['status'])->toBe('not_configured')->and($values['HCG-005']['value'])->toEqual(1)->and($values['HCG-005']['status'])->toBe('reported')->and($values['HCG-001']['source_href'])->toContain('site_id='.$this->site->id)->toContain('reached=yes');
    $this->get('/governance/clinical?site_id='.$other->site_id)->assertNotFound();
});

it('uses a rounds own window and keeps Away separate without writing missed records', function () {
    $actor = p09Reader('admin', $this->site);
    foreach ([['07:00', false, 'given'], ['07:00', true, null], ['09:00', false, null], ['07:00', false, 'away']] as [$time, $controlled, $outcome]) {
        $medicine = p09Medicine($this->person, ['dose_times' => [$time], 'controlled_drug' => $controlled]);
        $slot = App\Models\MedicationDoseSlot::firstOrCreate(['client_medication_id' => $medicine->id, 'nz_date' => '2026-09-29', 'ordered_time' => $time], ['client_id' => $this->person->id, 'due_at' => Carbon::parse('2026-09-29 '.$time, 'Pacific/Auckland')->utc(), 'controlled' => $controlled, 'generated_at' => now()]);
        $slot->update(['outcome' => $outcome, 'outcome_at' => $outcome ? Carbon::parse('2026-09-29 07:05', 'Pacific/Auckland')->utc() : null]);
    }
    $round = App\Models\MedicationRound::create(['site_id' => $this->site->id, 'name' => 'Synthetic morning', 'round_date' => '2026-09-29', 'scheduled_time' => '07:00', 'window_minutes' => 10, 'status' => 'pending']);
    $data = app(MedicationReportDataset::class)->read($actor, 'rounds', new MedicationReportPeriod('2026-09-29', '2026-09-29'), [$this->site->id]);
    expect($data['rows'])->toHaveCount(1)->and($data['rows'][0]['due'])->toBe(2)->and($data['rows'][0]['recorded'])->toBe(1)->and($data['rows'][0]['away'])->toBe(1)->and($data['rows'][0]['status'])->toBe('not_completed')->and($round->fresh()->status)->toBe('pending')->and(App\Models\ClientMedicationAdministration::count())->toBe(0);
});
