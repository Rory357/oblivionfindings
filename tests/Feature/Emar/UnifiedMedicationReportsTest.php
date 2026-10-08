<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\MedicationExportGuard;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCompetencyExemption;
use App\Models\MedicationDoseSlot;
use App\Models\MedicationError;
use App\Models\MedicationErrorEntry;
use App\Models\MedicationEvent;
use App\Models\MedicationOrderRevision;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationReview;
use App\Models\MedicationRound;
use App\Models\MedicationStockLot;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\CompetencyAcknowledgement;
use App\Services\Medication\Downtime\DowntimePackService;
use App\Services\Medication\MedicationAdministratorCompetencyPolicy;
use App\Services\Medication\Reporting\MedicationBuilderSource;
use App\Services\Medication\Reporting\MedicationGovernanceReports;
use App\Services\Medication\Reporting\MedicationPdfDataset;
use App\Services\Medication\Reporting\MedicationReportAccess;
use App\Services\Medication\Reporting\MedicationReportDataset;
use App\Services\Medication\Reporting\MedicationReportPeriod;
use App\Services\Medication\Reporting\RecordsReportingSettings;
use App\Services\Medication\StaffEligibilityRegister;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

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

function p09CompetencyReader(Site $site): User
{
    $actor = p09Reader('auditor', $site);
    $actor->permissionOverrides()->syncWithoutDetaching(Permission::whereIn('key', [
        'medications.administer.record', 'clinical.accessAllSites', 'sites.viewAll',
    ])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());

    return $actor->fresh();
}

function p09CompetencyStaff(Site $site, string $name, bool $records = true, array $profile = [], array $attributes = []): User
{
    $user = User::factory()->create(array_replace(['name' => $name, 'role' => 'support_worker', 'approved_at' => now()], $attributes));
    $user->permissionOverrides()->attach(Permission::where('key', 'medications.administer.record')->firstOrFail(), ['allowed' => $records]);
    HrEmployeeProfile::factory()->create(array_replace([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'position_role' => 'support_worker', 'employment_type' => 'full_time',
        'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null,
        'created_by' => $user->id, 'updated_by' => $user->id,
    ], $profile));

    return $user->fresh();
}

function p09CompetencyAssessment(User $user, User $assessor, array $attributes = []): MedicationCompetencyAssessment
{
    return MedicationCompetencyAssessment::query()->forceCreate(array_replace(
        collect(array_keys(CompetencyAcknowledgement::AREAS))->mapWithKeys(fn ($key) => [$key => true])->all(),
        [
            'user_id' => $user->id, 'assessor_id' => $assessor->id, 'assessment_type' => 'annual',
            'status' => 'passed', 'assessment_date' => '2026-08-29', 'expiry_date' => '2027-08-29',
            'total_score' => 12, 'pass_threshold' => 10,
            'assessor_declared_at' => now()->subDays(2), 'staff_acknowledged_at' => now()->subDay(),
        ],
        $attributes,
    ))->fresh();
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

it('opens a report person link in the canonical medication record at the report start date', function () {
    $actor = p09Reader('admin', $this->site);
    $medicine = p09Medicine($this->person, ['is_prn' => true]);
    ClientMedicationAdministration::withoutEvents(fn () => ClientMedicationAdministration::create([
        'client_id' => $this->person->id, 'client_medication_id' => $medicine->id, 'administered_by' => $actor->id,
        'administered_at' => Carbon::parse('2026-09-29 10:00', 'Pacific/Auckland')->utc(), 'status' => 'given',
    ]));
    $reportUrl = '/emar/reports?report=prn&site_id='.$this->site->id.'&period=custom&date_from=2026-09-29&date_to=2026-09-29';
    $response = $this->actingAs($actor)->get($reportUrl)->assertOk();
    $href = $response->inertiaProps('page.data.0.href');
    expect($href)->toBe('/emar/mar?client_id='.$this->person->id.'&date=2026-09-29');
    $this->get($href)->assertOk();
    $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'medications.view')->value('id') => ['allowed' => false]]);
    $this->actingAs($actor->fresh())->get($reportUrl)->assertOk()->assertInertia(fn ($page) => $page->where('page.data.0.href', null));
    $this->get($href)->assertForbidden();
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

it('offers the guarded downtime pack only within the existing clinical export boundary', function () {
    $this->actingAs(p09Reader('coordinator', $this->site))->get('/emar/reports?view=exports')->assertOk()->assertInertia(fn ($page) => $page
        ->where('downtime_pack.allowed', true)->where('downtime_pack.today', '2026-09-29')->where('downtime_pack.tomorrow', '2026-09-30')
        ->where('downtime_pack.purpose', DowntimePackService::PURPOSE));
    $this->actingAs(p09Reader('auditor', $this->site))->get('/emar/reports?view=exports')->assertOk()->assertInertia(fn ($page) => $page->where('downtime_pack.allowed', false));
    $this->actingAs(p09Reader('finance', $this->site))->get('/emar/reports?view=exports')->assertOk()->assertInertia(fn ($page) => $page->where('downtime_pack', null));
    expect(MedicationEvent::count())->toBe(0);
});

it('rejects a multi-person PDF when an included person moves during rendering', function () {
    $now = Carbon::getTestNow();
    Carbon::setTestNow(Carbon::parse('2026-09-28 12:00', 'Pacific/Auckland')->utc());
    try {
        p09Medicine($this->person);
    } finally {
        Carbon::setTestNow($now);
    }
    $other = Site::factory()->create(['is_active' => true]);
    $renderer = Mockery::mock(Barryvdh\DomPDF\PDF::class);
    Pdf::shouldReceive('setOption')->once()->andReturn($renderer);
    $renderer->shouldReceive('loadView')->once()->with('pdf.medication-report', Mockery::on(function (array $payload) {
        expect(collect($payload['evidence']['rows'])->pluck(0)->all())->toContain($this->person->full_name);

        return true;
    }))->andReturnSelf();
    $renderer->shouldReceive('setPaper')->once()->andReturnSelf();
    $renderer->shouldReceive('output')->once()->andReturnUsing(function () use ($other) {
        $this->person->update(['site_id' => $other->id]);

        return '%PDF-sensitive-synthetic-person';
    });
    $response = $this->actingAs(p09Reader('admin', $this->site))->postJson('/emar/reports/export', p09ExportData($this->site, 'round_sheet'));
    $response->assertConflict();
    expect($response->getContent())->not->toContain('%PDF-sensitive')->and(MedicationEvent::count())->toBe(0);
});

it('withholds buffered report bytes when account approval is withdrawn during rendering', function () {
    $actor = p09Reader('admin', $this->site);
    $renderer = Mockery::mock(Barryvdh\DomPDF\PDF::class);
    Pdf::shouldReceive('setOption')->once()->andReturn($renderer);
    $renderer->shouldReceive('loadView')->once()->andReturnSelf();
    $renderer->shouldReceive('setPaper')->once()->andReturnSelf();
    $renderer->shouldReceive('output')->once()->andReturnUsing(function () use ($actor) {
        DB::table('users')->where('id', $actor->id)->update(['approved_at' => null]);

        return '%PDF-sensitive-synthetic-person';
    });
    $response = $this->actingAs($actor)->postJson('/emar/reports/export', p09ExportData($this->site, 'round_sheet'));
    $response->assertForbidden();
    expect($response->getContent())->not->toContain('%PDF-sensitive-synthetic-person');
    expect(MedicationEvent::where('kind', 'export.created')->count())->toBe(0);
});

it('buffers retained streaming routes before rechecking every person', function () {
    $other = Site::factory()->create(['is_active' => true]);
    Route::get('/p09-synthetic-export', function () use ($other) {
        return response()->streamDownload(function () use ($other) {
            $this->person->update(['site_id' => $other->id]);
            echo 'sensitive-person';
        }, 'synthetic.csv');
    })->middleware(['auth', MedicationExportGuard::class.':doses']);
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
    AppSetting::updateOrCreate(['key' => RecordsReportingSettings::SAC], ['value' => 'on']);
    expect($settings->preselection('yes', 'severe_permanent'))->toBeNull()->and($settings->confirmation('no', 'none', null))->toBeNull();
    expect(fn () => $settings->confirmation('yes', 'severe_permanent', 3))->toThrow(ValidationException::class);
    expect($settings->confirmation('yes', 'severe_permanent', 2))->toBe(2);
});

it('reports actual error occurrence rather than the later report time', function () {
    $actor = p09Reader('admin', $this->site);
    MedicationError::create(['client_id' => $this->person->id, 'error_type' => 'wrong_dose', 'severity' => 'minor', 'reached_client' => 'yes', 'harm_level' => 'none', 'description' => 'Synthetic account', 'reported_by' => $actor->id, 'occurred_at' => Carbon::parse('2026-09-27 10:00', 'Pacific/Auckland')->utc(), 'reported_at' => now(), 'status' => 'reported', 'workflow_stage' => 'triage']);
    $data = app(MedicationReportDataset::class)->read($actor, 'errors', new MedicationReportPeriod('2026-09-27', '2026-09-27'), [$this->site->id]);
    expect($data['totals']['reached'])->toBe(1)->and($data['rows'][0]['date'])->toBe('2026-09-27')->and($data['rows'][0]['status'])->toBe('triage');
    expect(app(MedicationReportDataset::class)->read($actor, 'errors', new MedicationReportPeriod('2026-09-29', '2026-09-29'), [$this->site->id])['rows'])->toBe([]);
});

it('applies the error reporter and account scope before report builder and governance totals', function () {
    $actor = p09Reader('support_worker', $this->site);
    $id = Permission::where('key', 'medications.reports.view')->value('id');
    $actor->permissionOverrides()->syncWithoutDetaching([$id => ['allowed' => true]]);
    $controlledPermission = Permission::where('key', 'medications.controlled.view')->firstOrFail();
    $actor->permissionOverrides()->syncWithoutDetaching([$controlledPermission->id => ['allowed' => false]]);
    $actor = $actor->fresh();
    $other = p09Reader('coordinator', $this->site);
    $make = fn (array $extra = []) => MedicationError::create(array_replace(['client_id' => $this->person->id, 'error_type' => 'wrong_dose', 'severity' => 'minor', 'reached_client' => 'yes', 'harm_level' => 'none', 'description' => 'Synthetic account', 'reported_by' => $other->id, 'occurred_at' => now(), 'reported_at' => now(), 'status' => 'resolved', 'workflow_stage' => 'actions'], $extra));
    $own = $make(['reported_by' => $actor->id]);
    $account = $make();
    MedicationErrorEntry::create(['medication_error_id' => $account->id, 'kind' => 'account', 'actor_id' => $actor->id, 'text' => 'Synthetic account', 'created_at' => now()]);
    $make();
    $controlled = p09Medicine($this->person, ['controlled_drug' => true]);
    $make(['reported_by' => $actor->id, 'client_medication_id' => $controlled->id]);
    DB::table('client_medications')->where('id', $controlled->id)->update(['deleted_at' => now()]);
    $period = new MedicationReportPeriod('2026-09-29', '2026-09-29');
    $data = app(MedicationReportDataset::class)->read($actor, 'errors', $period, [$this->site->id]);
    expect(array_column($data['rows'], 'reference'))->toBe([$own->reference_number, $account->reference_number])
        ->and($data['totals']['reached'])->toBe(2)->and($data['totals']['open'])->toBe(2)
        ->and($data['rows'][0]['href'])->toBe('/emar/errors?error='.$own->id);
    $builder = app(MedicationBuilderSource::class)->read($actor, ['source' => 'medication_errors', 'date_from' => $period->from, 'date_to' => $period->to], [$this->site->id]);
    expect(array_column($builder['rows'], 'reference'))->toBe([$own->reference_number, $account->reference_number]);
    $governance = app(MedicationGovernanceReports::class)->values($actor, $period->from, $period->to, $this->site->id);
    expect($governance['HCG-001']['value'])->toBe(2);
});

it('reports usable packs while retaining unknown cost and controlled physical balance', function () {
    $actor = p09Reader('admin', $this->site);
    $medicine = p09Medicine($this->person);
    $stock = ClientMedicationStock::create(['client_medication_id' => $medicine->id, 'on_hand' => 99, 'unit' => 'tablet', 'reorder_level' => 6]);
    $stock->forceFill(['lots_started_at' => now()])->save();
    foreach ([['open', '2026-10-05', 5], ['open', '2026-09-28', 7], ['quarantined', '2026-10-05', 9], ['closed', '2026-10-05', 11]] as [$state, $expiry, $quantity]) {
        MedicationStockLot::create(['client_medication_stock_id' => $stock->id, 'state' => $state, 'expiry_date' => $expiry, 'quantity_received' => $quantity, 'quantity_remaining' => $quantity, 'source' => 'synthetic', 'received_at' => now()]);
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
    $review = MedicationReview::create(['client_id' => $this->person->id, 'review_type' => 'routine', 'status' => 'completed', 'scheduled_date' => '2026-09-10', 'completed_date' => '2026-09-28', 'happened_at' => Carbon::parse('2026-09-29 09:00', 'Pacific/Auckland')->utc()]);
    $period = new MedicationReportPeriod('2026-09-29', '2026-09-29');
    $reviews = app(MedicationReportDataset::class)->read($actor, 'reviews', $period, [$this->site->id]);
    expect($reviews['totals']['done'])->toBe(1)->and($reviews['rows'][0]['completed_date'])->toBe('2026-09-29')->and($reviews['rows'][0]['href'])->toBe('/emar/reviews?review='.$review->id);
    $medicine = p09Medicine($this->person, ['controlled_drug' => true]);
    ClientControlledDrugEntry::create(['client_id' => $this->person->id, 'client_medication_id' => $medicine->id, 'entry_type' => 'balance_check', 'quantity' => 0, 'on_hand_before' => 8, 'on_hand_after' => 8, 'recorded_at' => now(), 'recorded_by' => $actor->id, 'witnessed_by' => p09Reader('coordinator', $this->site)->id]);
    expect(app(MedicationReportDataset::class)->read($actor, 'controlled', $period, [$this->site->id])['totals']['counts'])->toBe(1);
});

it('keeps proposed versions out of historical dose instructions and separates equal names', function () {
    $actor = p09Reader('admin', $this->site);
    foreach (['1 tablet', '2 tablets'] as $dose) {
        $medicine = p09Medicine($this->person);
        $version = MedicationOrderVersion::create(['client_id' => $this->person->id, 'client_medication_id' => $medicine->id, 'version_number' => 1, 'name' => 'Synthetic medicine', 'dosage' => $dose, 'route' => 'oral', 'changed_by' => $actor->id, 'changed_at' => now()->subDay()]);
        MedicationOrderRevision::create(['client_id' => $this->person->id, 'client_medication_id' => $medicine->id, 'medication_order_version_id' => $version->id, 'base_version' => 1, 'status' => 'checked', 'checked_at' => now()->subDay(), 'entered_by' => $actor->id]);
        $proposal = MedicationOrderVersion::create(['client_id' => $this->person->id, 'client_medication_id' => $medicine->id, 'version_number' => 2, 'name' => 'Synthetic medicine', 'dosage' => '99 tablets', 'route' => 'oral', 'changed_by' => $actor->id, 'changed_at' => now()]);
        MedicationOrderRevision::create(['client_id' => $this->person->id, 'client_medication_id' => $medicine->id, 'medication_order_version_id' => $proposal->id, 'base_version' => 1, 'status' => 'pending', 'entered_by' => $actor->id]);
        MedicationDoseSlot::firstOrCreate(['client_medication_id' => $medicine->id, 'nz_date' => '2026-09-29', 'ordered_time' => '07:00'], ['client_id' => $this->person->id, 'due_at' => Carbon::parse('2026-09-29 07:00', 'Pacific/Auckland')->utc(), 'generated_at' => now(), 'controlled' => false]);
    }
    $data = app(MedicationPdfDataset::class)->read($actor, 'mar', new MedicationReportPeriod('2026-09-29', '2026-09-29'), [$this->site->id], $this->person->id, null);
    expect($data['chart'])->toHaveCount(2)->and(collect($data['chart'])->pluck('dose')->sort()->values()->all())->toBe(['1 tablet', '2 tablets']);
});

it('scopes both governance periods and keeps the target unconfigured and near misses without RAG', function () {
    $this->seed(GovernancePermissionsSeeder::class);
    $actor = p09Reader('team_lead', $this->site);
    $permissions = Permission::whereIn('key', ['governance.clinical.view', 'clinical.accessAllSites', 'sites.viewAll'])->get();
    $actor->permissionOverrides()->syncWithoutDetaching($permissions->mapWithKeys(fn ($p) => [$p->id => ['allowed' => $p->key === 'governance.clinical.view']])->all());
    $other = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id]);
    foreach ([[$this->person, '2026-09-15', 'yes'], [$this->person, '2026-09-15', 'no'], [$this->person, '2026-08-15', 'yes'], [$other, '2026-09-15', 'yes'], [$other, '2026-08-15', 'yes']] as [$person, $day, $reach]) {
        MedicationError::create(['client_id' => $person->id, 'error_type' => 'wrong_dose', 'severity' => 'minor', 'reached_client' => $reach, 'harm_level' => 'none', 'description' => 'Synthetic account', 'reported_by' => $actor->id, 'occurred_at' => Carbon::parse($day.' 10:00', 'Pacific/Auckland')->utc(), 'reported_at' => now(), 'status' => 'reported']);
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
        $slot = MedicationDoseSlot::firstOrCreate(['client_medication_id' => $medicine->id, 'nz_date' => '2026-09-29', 'ordered_time' => $time], ['client_id' => $this->person->id, 'due_at' => Carbon::parse('2026-09-29 '.$time, 'Pacific/Auckland')->utc(), 'controlled' => $controlled, 'generated_at' => now()]);
        $slot->update(['outcome' => $outcome, 'outcome_at' => $outcome ? Carbon::parse('2026-09-29 07:05', 'Pacific/Auckland')->utc() : null]);
    }
    $round = MedicationRound::create(['site_id' => $this->site->id, 'name' => 'Synthetic morning', 'round_date' => '2026-09-29', 'scheduled_time' => '07:00', 'window_minutes' => 10, 'status' => 'pending']);
    $data = app(MedicationReportDataset::class)->read($actor, 'rounds', new MedicationReportPeriod('2026-09-29', '2026-09-29'), [$this->site->id]);
    expect($data['rows'])->toHaveCount(1)->and($data['rows'][0]['due'])->toBe(2)->and($data['rows'][0]['recorded'])->toBe(1)->and($data['rows'][0]['away'])->toBe(1)->and($data['rows'][0]['status'])->toBe('not_completed')->and($round->fresh()->status)->toBe('pending')->and(ClientMedicationAdministration::count())->toBe(0);
});

it('counts the canonical current staff population including unassessed and secondary house recorders', function () {
    $actor = p09CompetencyReader($this->site);
    $other = Site::factory()->create(['is_active' => true]);
    $primary = p09CompetencyStaff($this->site, 'Primary current');
    $primaryUnassessed = p09CompetencyStaff($this->site, 'Primary unassessed');
    $secondaryUnassessed = p09CompetencyStaff($other, 'Secondary unassessed', profile: ['secondary_site_ids' => [(string) $this->site->id, $this->site->id]]);
    $secondary = p09CompetencyStaff($other, 'Secondary current', profile: ['secondary_site_ids' => [$this->site->id]]);
    $onFile = p09CompetencyStaff($this->site, 'Assessment without recording grant', records: false);
    foreach ([$primary, $secondary, $onFile] as $staff) {
        p09CompetencyAssessment($staff, $actor);
    }
    $denied = [
        p09CompetencyStaff($other, 'Outside house'),
        p09CompetencyStaff($this->site, 'Inactive profile', profile: ['is_active' => false]),
        p09CompetencyStaff($this->site, 'Ended employment', profile: ['end_date' => '2026-09-28']),
        p09CompetencyStaff($this->site, 'Future employment', profile: ['start_date' => '2026-09-30']),
        p09CompetencyStaff($this->site, 'Unapproved account', attributes: ['approved_at' => null]),
        p09CompetencyStaff($this->site, 'Deleted profile', profile: ['deleted_at' => now()]),
        p09CompetencyStaff($this->site, 'Legacy portal account', attributes: ['role' => 'client']),
    ];
    $portal = p09CompetencyStaff($this->site, 'RBAC portal account');
    $portal->roles()->attach(Role::where('name', 'client')->firstOrFail());
    $denied[] = $portal;
    $noProfile = User::factory()->create(['name' => 'Missing profile', 'role' => 'support_worker', 'approved_at' => now()]);
    $noProfile->permissionOverrides()->attach(Permission::where('key', 'medications.administer.record')->firstOrFail(), ['allowed' => true]);
    $denied[] = $noProfile;
    foreach ($denied as $staff) {
        p09CompetencyAssessment($staff, $actor);
    }
    p09CompetencyStaff($this->site, 'No recording grant or assessment', records: false);
    $before = DB::table('medication_competency_assessments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    $data = app(MedicationReportDataset::class)->read($actor, 'competency', new MedicationReportPeriod('2026-09-29', '2026-09-29'), [$this->site->id]);
    $expectedNames = collect([$primary, $primaryUnassessed, $secondaryUnassessed, $secondary, $onFile])->pluck('name')->sort()->values()->all();
    expect(collect($data['rows'])->pluck('staff')->sort()->values()->all())->toBe($expectedNames)
        ->and($data['totals'])->toBe(['staff' => 5, 'assessed' => 3, 'current' => 3, 'current_pct' => 60.0]);
    foreach ([$primaryUnassessed, $secondaryUnassessed] as $staff) {
        $row = collect($data['rows'])->firstWhere('staff', $staff->name);
        expect($row['reference'])->toBe('staff:'.$staff->id)->and($row['status'])->toBe('unassessed')
            ->and($row['assessment_status'])->toBeNull()->and($row['date'])->toBeNull()
            ->and($row['expiry_date'])->toBeNull()->and($row['current'])->toBe(0);
    }
    expect(app(StaffEligibilityRegister::class)->rows([$this->site->id])->pluck('name')->sort()->values()->all())->toBe($expectedNames);
    $this->actingAs($actor)->get('/emar/reports?report=competency&site_id='.$this->site->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('data.totals.staff', 5)->where('data.totals.assessed', 3)
            ->where('data.totals.current', 3)->where('data.totals.current_pct', 60)->has('page.data', 5));
    expect(DB::table('medication_competency_assessments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
});

it('does not report an unestablished or invalid assessment as current', function (array $attributes, string $state) {
    $actor = p09CompetencyReader($this->site);
    $worker = p09CompetencyStaff($this->site, 'Synthetic competency subject');
    if (isset($attributes['self_assessed'])) {
        unset($attributes['self_assessed']);
        $attributes['assessor_id'] = $worker->id;
    }
    $assessment = p09CompetencyAssessment($worker, $actor, $attributes);
    $before = $assessment->getRawOriginal();
    $data = app(MedicationReportDataset::class)->read($actor, 'competency', new MedicationReportPeriod('2026-01-01', '2026-01-01'), [$this->site->id]);
    expect($data['totals'])->toBe(['staff' => 1, 'assessed' => 1, 'current' => 0, 'current_pct' => 0.0])
        ->and($data['rows'][0]['reference'])->toBe('assessment:'.$assessment->id)
        ->and($data['rows'][0]['status'])->toBe($state)->and($data['rows'][0]['assessment_status'])->toBe($assessment->status)
        ->and($data['rows'][0]['current'])->toBe(0)->and($assessment->fresh()->getRawOriginal())->toBe($before);
})->with([
    'self assessed' => [['self_assessed' => true], 'unassessed'],
    'missing independent assessor' => [['assessor_id' => null], 'unassessed'],
    'undeclared' => [['assessor_declared_at' => null], 'unassessed'],
    'awaiting staff acknowledgement' => [['staff_acknowledged_at' => null], 'unassessed'],
    'future assessor declaration' => [['assessor_declared_at' => '2026-09-29 00:01:00'], 'unassessed'],
    'future staff acknowledgement' => [['staff_acknowledged_at' => '2026-09-29 00:01:00'], 'unassessed'],
    'future clinical assessment date' => [['assessment_date' => '2026-09-30'], 'unassessed'],
    'no finite expiry' => [['expiry_date' => null], 'missing_expiry'],
    'expired clinical date' => [['expiry_date' => '2026-09-28'], 'expired'],
    'independently established failure' => [['status' => 'failed'], 'failed'],
]);

it('reports the effective prior assessment while a replacement is unestablished', function (array $replacementAttributes, bool $current) {
    $actor = p09CompetencyReader($this->site);
    $worker = p09CompetencyStaff($this->site, 'Renewing subject');
    $prior = p09CompetencyAssessment($worker, $actor);
    $replacement = p09CompetencyAssessment($worker, $actor, array_replace(['assessment_date' => '2026-09-28'], $replacementAttributes));
    $before = DB::table('medication_competency_assessments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $data = app(MedicationReportDataset::class)->read($actor, 'competency', new MedicationReportPeriod('2026-09-29', '2026-09-29'), [$this->site->id]);
    $effective = $current ? $prior : $replacement;
    expect($data['totals'])->toBe(['staff' => 1, 'assessed' => 1, 'current' => $current ? 1 : 0, 'current_pct' => $current ? 100.0 : 0.0])
        ->and($data['rows'][0]['reference'])->toBe('assessment:'.$effective->id)
        ->and($data['rows'][0]['date'])->toBe($effective->assessment_date->toDateString())
        ->and($data['rows'][0]['expiry_date'])->toBe($effective->expiry_date->toDateString())
        ->and($data['rows'][0]['status'])->toBe($current ? 'valid' : 'failed')
        ->and($data['rows'][0]['assessment_status'])->toBe($current ? 'passed' : 'failed')
        ->and($data['rows'][0]['current'])->toBe($current ? 1 : 0);
    expect(DB::table('medication_competency_assessments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
})->with([
    'passed renewal awaiting acknowledgement' => [['staff_acknowledged_at' => null], true],
    'failed replacement awaiting acknowledgement' => [['status' => 'failed', 'staff_acknowledged_at' => null], true],
    'undeclared failed replacement' => [['status' => 'failed', 'assessor_declared_at' => null], true],
    'replacement declaration is in the future' => [['assessor_declared_at' => '2026-09-29 00:01:00'], true],
    'acknowledged failure supersedes the prior pass' => [['status' => 'failed'], false],
]);

it('uses the worker clinical date for current competency at UTC midnight boundaries', function (string $utcClock, string $state, int $current) {
    $originalClock = Carbon::getTestNow();
    Carbon::setTestNow(Carbon::parse($utcClock, 'UTC'));
    try {
        $actor = p09CompetencyReader($this->site);
        $worker = p09CompetencyStaff($this->site, 'Clinical date subject');
        $assessment = p09CompetencyAssessment($worker, $actor, [
            'assessment_date' => '2026-09-28', 'expiry_date' => '2026-09-28',
            'assessor_declared_at' => '2026-09-27 20:00:00', 'staff_acknowledged_at' => '2026-09-27 21:00:00',
        ]);
        $data = app(MedicationReportDataset::class)->read($actor, 'competency', new MedicationReportPeriod('2026-09-28', '2026-09-28'), [$this->site->id]);
        expect($data['rows'][0]['status'])->toBe($state)->and($data['rows'][0]['current'])->toBe($current)
            ->and($data['rows'][0]['expiry_date'])->toBe('2026-09-28')->and($data['rows'][0]['reference'])->toBe('assessment:'.$assessment->id)
            ->and($data['totals']['current_pct'])->toBe($current ? 100.0 : 0.0);
    } finally {
        Carbon::setTestNow($originalClock);
    }
})->with([
    'expiry clinical day still applies' => ['2026-09-28 10:30:00', 'valid', 1],
    'UTC still yesterday but NZ expiry day has ended' => ['2026-09-28 12:30:00', 'expired', 0],
]);

it('keeps a real temporary house exemption separate from current assessment coverage', function () {
    $actor = p09CompetencyReader($this->site);
    $worker = p09CompetencyStaff($this->site, 'Temporarily exempt subject');
    $exemption = MedicationCompetencyExemption::create([
        'user_id' => $worker->id, 'site_id' => $this->site->id, 'scope' => MedicationCompetencyExemption::SCOPE_ADMINISTRATION,
        'reason' => 'Synthetic assessor leave with renewal booked', 'approved_by' => $actor->id, 'approved_at' => now()->subHour(),
        'starts_at' => now()->subHour(), 'expires_at' => now()->addDays(2),
    ]);
    $exemption->refresh();
    $before = $exemption->getRawOriginal();
    expect(app(MedicationAdministratorCompetencyPolicy::class)->evaluate($worker, $this->site->id, now())['state'])->toBe('exempt');
    $data = app(MedicationReportDataset::class)->read($actor, 'competency', new MedicationReportPeriod('2026-01-01', '2026-01-01'), [$this->site->id]);
    expect($data['totals'])->toBe(['staff' => 1, 'assessed' => 0, 'current' => 0, 'current_pct' => 0.0])
        ->and($data['rows'][0]['reference'])->toBe('staff:'.$worker->id)->and($data['rows'][0]['status'])->toBe('unassessed')
        ->and($data['rows'][0]['assessment_status'])->toBeNull()->and($data['rows'][0]['date'])->toBeNull()
        ->and($data['rows'][0]['expiry_date'])->toBeNull()->and($data['rows'][0]['current'])->toBe(0)
        ->and($data['notice'])->toContain('as at now', 'independent assessor declaration', 'staff acknowledgement', 'exemptions are not current assessments')
        ->and($exemption->fresh()->getRawOriginal())->toBe($before)->and(MedicationCompetencyAssessment::count())->toBe(0);
});

it('returns no current rate when the canonical competency staff population is empty', function () {
    $actor = p09CompetencyReader($this->site);
    p09CompetencyStaff($this->site, 'No recording authority or assessment', records: false);
    $data = app(MedicationReportDataset::class)->read($actor, 'competency', new MedicationReportPeriod('2026-09-29', '2026-09-29'), [$this->site->id]);
    expect($data['rows'])->toBe([])->and($data['totals'])->toBe(['staff' => 0, 'assessed' => 0, 'current' => 0, 'current_pct' => null])
        ->and(app(StaffEligibilityRegister::class)->rows([])->all())->toBe([]);
});

it('keeps competency report grants and house concealment boundaries with no mutation', function () {
    $actor = p09CompetencyReader($this->site);
    $other = Site::factory()->create(['is_active' => true]);
    $local = p09CompetencyStaff($this->site, 'Visible current subject');
    $foreign = p09CompetencyStaff($other, 'Concealed foreign subject');
    p09CompetencyAssessment($local, $actor);
    p09CompetencyAssessment($foreign, $actor);
    $before = DB::table('medication_competency_assessments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $beforeEvents = MedicationEvent::count();
    $response = $this->actingAs($actor)->get('/emar/reports?report=competency&site_id='.$this->site->id)->assertOk();
    expect(collect($response->inertiaProps('page.data'))->pluck('staff')->all())->toBe([$local->name]);
    $this->get('/emar/reports?report=competency&site_id='.$other->id)->assertNotFound()->assertDontSee($foreign->name);
    $this->get('/emar/reports?report=competency&site_id=999999999')->assertNotFound()->assertDontSee($foreign->name);
    $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'medications.reports.view')->firstOrFail()->id => ['allowed' => false]]);
    $this->actingAs($actor->fresh())->get('/emar/reports?report=competency&site_id='.$this->site->id)->assertForbidden()->assertDontSee($local->name);
    expect(DB::table('medication_competency_assessments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before)
        ->and(MedicationEvent::count())->toBe($beforeEvents);
});

it('opens canonical staff eligibility only when the report reader has medication read access', function () {
    $actor = p09CompetencyReader($this->site);
    $worker = p09CompetencyStaff($this->site, 'Eligibility link subject');
    p09CompetencyAssessment($worker, $actor);
    $url = '/emar/reports?report=competency&site_id='.$this->site->id;
    $response = $this->actingAs($actor)->get($url)->assertOk();
    $href = $response->inertiaProps('page.data.0.href');
    expect($href)->toBe('/emar/safety/eligibility');
    $this->get($href)->assertOk()->assertInertia(fn ($page) => $page->component('emar/StaffEligibility')
        ->where('people', fn ($people) => collect($people)->contains(fn ($person) => $person['id'] === $worker->id)));
    $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'medications.view')->firstOrFail()->id => ['allowed' => false]]);
    $this->actingAs($actor->fresh())->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('page.data.0.href', null));
    $this->get($href)->assertForbidden();
});

it('prints the exact round window and rejects mismatched house or historical day', function () {
    $actor = p09Reader('admin', $this->site);
    $context = ServiceContext::factory()->create(['type' => 'residential', 'site_id' => $this->site->id, 'is_active' => true]);
    $this->person->update(['service_context_id' => $context->id]);
    foreach (['07:00', '09:00'] as $time) {
        $medicine = p09Medicine($this->person, ['name' => 'Synthetic '.$time.' medicine', 'dose_times' => [$time]]);
        MedicationDoseSlot::firstOrCreate(['client_medication_id' => $medicine->id, 'nz_date' => '2026-09-29', 'ordered_time' => $time],
            ['client_id' => $this->person->id, 'due_at' => Carbon::parse('2026-09-29 '.$time, 'Pacific/Auckland')->utc(), 'controlled' => false, 'generated_at' => now()]);
    }
    $excluded = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null]);
    $excludedMedicine = p09Medicine($excluded, ['name' => 'Different service context sentinel']);
    MedicationDoseSlot::firstOrCreate(['client_medication_id' => $excludedMedicine->id, 'nz_date' => '2026-09-29', 'ordered_time' => '07:00'],
        ['client_id' => $excluded->id, 'due_at' => Carbon::parse('2026-09-29 07:00', 'Pacific/Auckland')->utc(), 'controlled' => false, 'generated_at' => now()]);
    $round = MedicationRound::create(['site_id' => $this->site->id, 'service_context_id' => $context->id, 'name' => 'Synthetic selected morning', 'round_date' => '2026-09-29',
        'scheduled_time' => '07:00', 'window_minutes' => 10, 'status' => 'pending']);
    $evidence = app(MedicationPdfDataset::class)->read($actor, 'round_sheet', new MedicationReportPeriod('2026-09-29', '2026-09-29'), [$this->site->id], null, null, true, $round->id);
    expect($evidence['rows'])->toHaveCount(1)->and($evidence['rows'][0][2])->toBe('Synthetic 07:00 medicine')->and($evidence['round']['id'])->toBe($round->id);
    $renderer = Mockery::mock(Barryvdh\DomPDF\PDF::class);
    Pdf::shouldReceive('setOption')->once()->andReturn($renderer);
    $renderer->shouldReceive('loadView')->once()->with('pdf.medication-report', Mockery::on(fn ($payload) => $payload['evidence']['round']['id'] === $round->id && count($payload['evidence']['rows']) === 1))->andReturnSelf();
    $renderer->shouldReceive('setPaper')->once()->andReturnSelf();
    $renderer->shouldReceive('output')->once()->andReturn('%PDF-exact-synthetic-round');
    $this->actingAs($actor)->getJson('/emar/pdf/round-sheet?'.http_build_query(['round_id' => $round->id, 'purpose' => 'audit']))->assertOk();
    expect(MedicationEvent::where('kind', 'export.created')->sole()->facts['round_id'])->toBe($round->id);
    $this->getJson('/emar/pdf/round-sheet?'.http_build_query(['round_id' => $round->id, 'site_id' => Site::factory()->create(['is_active' => true])->id, 'purpose' => 'audit']))->assertNotFound();
    $this->getJson('/emar/pdf/round-sheet?'.http_build_query(['round_id' => $round->id, 'date' => '2026-09-28', 'purpose' => 'audit']))->assertNotFound();
});

it('links controlled report rows to their exact register medicine and NZ day', function () {
    $actor = p09Reader('admin', $this->site);
    $medicine = p09Medicine($this->person, ['controlled_drug' => true]);
    $entry = ClientControlledDrugEntry::create(['client_id' => $this->person->id, 'client_medication_id' => $medicine->id,
        'entry_type' => 'receipt', 'quantity' => 2, 'unit' => 'tablets', 'on_hand_before' => 0, 'on_hand_after' => 2,
        'recorded_at' => Carbon::parse('2026-09-29 00:05', 'Pacific/Auckland')->utc(), 'recorded_by' => $actor->id]);
    $rows = app(MedicationReportDataset::class)->read($actor, 'controlled', new MedicationReportPeriod('2026-09-01', '2026-09-29'), [$this->site->id])['rows'];
    $row = collect($rows)->firstWhere('reference', 'register:'.$entry->id);
    expect($row['href'])->toContain('/emar/controlled?')->toContain('date=2026-09-29')->toContain('client_medication_id='.$medicine->id)->toContain('entry_id='.$entry->id);
    $this->actingAs($actor)->get($row['href'])->assertOk()->assertInertia(fn ($page) => $page->where('product.selected_entry_id', $entry->id));
});

it('loads exact round export options with canonical house and historical day without exporting', function () {
    $actor = p09Reader('admin', $this->site);
    Site::factory()->create(['is_active' => true]);
    $round = MedicationRound::create(['site_id' => $this->site->id, 'name' => 'Synthetic historical round',
        'round_date' => '2026-09-28', 'scheduled_time' => '07:00', 'status' => 'pending']);
    $before = $round->fresh()->getRawOriginal();
    $beforeEvents = MedicationEvent::count();
    $query = ['type' => 'round_sheet', 'round_id' => $round->id];
    $response = $this->actingAs($actor)->getJson('/emar/reports/export-options?'.http_build_query($query))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('filters.period', 'custom')
        ->assertJsonPath('filters.site_id', $this->site->id)
        ->assertJsonPath('filters.round_id', $round->id)
        ->assertJsonPath('filters.date_from', '2026-09-28')
        ->assertJsonPath('filters.date_to', '2026-09-28')
        ->assertJsonPath('selected_round', ['id' => $round->id, 'name' => $round->name, 'site_id' => $this->site->id, 'date' => '2026-09-28'])
        ->assertJsonPath('exports.0.allowed', true)
        ->assertJsonPath('purposes.care', 'Care and handover');
    expect(array_column($response->json('sites'), 'id'))->toBe([$this->site->id]);
    $this->getJson('/emar/reports/export-options?'.http_build_query($query + [
        'site_id' => $this->site->id, 'period' => 'custom', 'date_from' => '2026-09-28', 'date_to' => '2026-09-28',
    ]))->assertOk()->assertJsonPath('selected_round.id', $round->id);
    // Options never invent a purpose or authorize releasing a file by themselves.
    $this->postJson('/emar/reports/export', $query + ['site_id' => $this->site->id,
        'period' => 'custom', 'date_from' => '2026-09-28', 'date_to' => '2026-09-28'])
        ->assertUnprocessable()->assertJsonValidationErrors('purpose');
    expect($round->fresh()->getRawOriginal())->toBe($before)->and(MedicationEvent::count())->toBe($beforeEvents);
});

it('rejects contradictory house day period and malformed exact round export options', function () {
    $actor = p09Reader('admin', $this->site);
    $otherSite = Site::factory()->create(['is_active' => true]);
    $round = MedicationRound::create(['site_id' => $this->site->id, 'name' => 'Synthetic exact options round',
        'round_date' => '2026-09-28', 'scheduled_time' => '07:00', 'status' => 'pending']);
    $query = ['type' => 'round_sheet', 'round_id' => $round->id];
    foreach ([['site_id' => $otherSite->id], ['date_from' => '2026-09-29'], ['date_to' => '2026-09-29'],
        ['date_from' => '2026-09-27', 'date_to' => '2026-09-28'], ['period' => 'today']] as $contradiction) {
        $this->actingAs($actor)->getJson('/emar/reports/export-options?'.http_build_query($query + $contradiction))->assertNotFound();
    }
    foreach ([['round_id' => 0], ['round_id' => 'not-a-round'], ['type' => 'mar']] as $invalid) {
        $this->getJson('/emar/reports/export-options?'.http_build_query(array_replace($query, $invalid)))
            ->assertUnprocessable()->assertJsonValidationErrors('round_id');
    }
    $this->getJson('/emar/reports/export-options?'.http_build_query($query + ['date_from' => 'invalid']))
        ->assertUnprocessable()->assertJsonValidationErrors('date_from');
    expect(MedicationEvent::count())->toBe(0);
});

it('keeps exact round export options within current report export and approved house grants', function () {
    $actor = p09Reader('coordinator', $this->site);
    $actor->permissionOverrides()->syncWithoutDetaching(Permission::whereIn('key', ['clinical.accessAllSites', 'sites.viewAll'])
        ->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
    $local = MedicationRound::create(['site_id' => $this->site->id, 'name' => 'Synthetic local options',
        'round_date' => '2026-09-29', 'scheduled_time' => '07:00', 'status' => 'pending']);
    $foreign = MedicationRound::create(['site_id' => Site::factory()->create(['is_active' => true])->id,
        'name' => 'Foreign round sentinel', 'round_date' => '2026-09-29', 'scheduled_time' => '07:00', 'status' => 'pending']);
    $this->actingAs($actor->fresh())->getJson('/emar/reports/export-options?'.http_build_query(['type' => 'round_sheet', 'round_id' => $local->id]))
        ->assertOk()->assertJsonPath('selected_round.id', $local->id);
    $this->getJson('/emar/reports/export-options?'.http_build_query(['type' => 'round_sheet', 'round_id' => $foreign->id]))
        ->assertNotFound()->assertDontSee('Foreign round sentinel');
    $this->getJson('/emar/reports/export-options?'.http_build_query(['type' => 'round_sheet', 'round_id' => 999999999]))->assertNotFound();
    $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'medications.reports.export')->value('id') => ['allowed' => false]]);
    $this->actingAs($actor->fresh())->getJson('/emar/reports/export-options?'.http_build_query(['type' => 'round_sheet', 'round_id' => $local->id]))->assertForbidden();
    expect(MedicationEvent::count())->toBe(0);
});
