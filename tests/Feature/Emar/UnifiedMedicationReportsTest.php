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
    $this->actingAs(p09Reader('auditor', $this->site))->get('/emar/reports')->assertOk()->assertInertia(fn ($page) => $page->component('emar/reports/hub')->where('filters.report', 'doses')->where('data.totals.given_rate', null));
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
