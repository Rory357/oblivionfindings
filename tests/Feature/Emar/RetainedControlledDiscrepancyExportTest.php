<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\MedicationEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RetainedControlledDiscrepancyExportTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    private Site $site;

    private Client $person;

    private ClientMedication $medicine;

    private const EXPORT_ROUTES = ['reports.medications.export_discrepancies', 'emar.reports.export_discrepancies'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-30 12:00', 'Pacific/Auckland')->utc());
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->person = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->reader->id,
            'primary_site_id' => $this->site->id,
            'is_active' => true,
            'start_date' => '2026-01-01',
        ]);
        $grants = ['medications.view', 'medications.reports.view', 'medications.reports.export', 'medications.controlled.view'];
        $denials = MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS;
        $permissions = Permission::query()->whereIn('key', [...$grants, ...$denials])->get();
        $this->assertCount(count($grants) + count($denials), $permissions);
        $this->reader->permissionOverrides()->sync($permissions->mapWithKeys(fn ($permission) => [
            $permission->id => ['allowed' => in_array($permission->key, $grants, true)],
        ])->all());
        $this->reader->refresh();
        $this->medicine = $this->medicine($this->person);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function nzDays(): array
    {
        return [
            'NZST' => ['2026-06-15', '2026-06-14 12:00:00', '2026-06-15 11:59:59', 'NZST', 'NZST'],
            'NZDT' => ['2026-09-29', '2026-09-28 11:00:00', '2026-09-29 10:59:59', 'NZDT', 'NZDT'],
            'DST ends (25-hour day)' => ['2026-04-05', '2026-04-04 11:00:00', '2026-04-05 11:59:59', 'NZDT', 'NZST'],
            'DST starts (23-hour day)' => ['2026-09-27', '2026-09-26 12:00:00', '2026-09-27 10:59:59', 'NZST', 'NZDT'],
        ];
    }

    #[DataProvider('nzDays')]
    public function test_both_retained_exports_match_the_reader_nz_day_and_canonical_person_scope(
        string $day,
        string $firstUtc,
        string $lastUtc,
        string $firstZone,
        string $lastZone,
    ): void {
        $this->discrepancy('Outside before NZ day', Carbon::parse($firstUtc, 'UTC')->subSecond()->toDateTimeString());
        $this->discrepancy('First NZ second', $firstUtc, ['status' => 'closed', 'resolved_at' => $firstUtc, 'resolved_by' => $this->reader->id]);
        $this->discrepancy('Last NZ second', $lastUtc);
        $this->discrepancy('Outside after NZ day', Carbon::parse($lastUtc, 'UTC')->addSecond()->toDateTimeString());
        $foreign = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id]);
        $foreignMedicine = $this->medicine($foreign);
        $this->discrepancy('FOREIGN PERSON SENTINEL', $firstUtc, ['client_id' => $foreign->id, 'client_medication_id' => $foreignMedicine->id]);
        $this->discrepancy('FORGED MEDICINE OWNER SENTINEL', $firstUtc, ['client_medication_id' => $foreignMedicine->id]);
        $canonical = ['period' => 'custom', 'date_from' => $day, 'date_to' => $day];
        $scope = ['site_id' => $this->site->id, 'client_id' => $this->person->id];

        $this->actingAs($this->reader)->get(route('emar.reports', $scope + $canonical + ['report' => 'controlled']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('emar/reports/hub')
            ->where('filters.date_from', $day)->where('filters.date_to', $day)
            ->where('data.totals.discrepancies', 2));
        $this->assertSame(0, MedicationEvent::where('kind', 'export.created')->count());

        foreach ([$canonical, ['from' => $day, 'to' => $day]] as $dates) {
            foreach (self::EXPORT_ROUTES as $route) {
                $csv = $this->get(route($route, $scope + $dates + ['purpose' => 'audit']))
                    ->assertOk()->getContent();
                $rows = $this->csvRows($csv);
                $this->assertCount(3, $rows);
                $this->assertSame('Reported At (Pacific/Auckland)', $rows[0][0]);
                $this->assertSame('Resolved At (Pacific/Auckland)', $rows[0][11]);
                $this->assertSame($day.' 00:00:00 '.$firstZone, $rows[1][0]);
                $this->assertSame($day.' 23:59:59 '.$lastZone, $rows[2][0]);
                $this->assertSame($day.' 00:00:00 '.$firstZone, $rows[1][11]);
                $this->assertSame('', $rows[2][11]);
                $this->assertSame('First NZ second', $rows[1][7]);
                $this->assertSame('Last NZ second', $rows[2][7]);
                foreach (['Outside before NZ day', 'Outside after NZ day', 'FOREIGN PERSON SENTINEL', 'FORGED MEDICINE OWNER SENTINEL'] as $excluded) {
                    $this->assertStringNotContainsString($excluded, $csv);
                }
            }
        }
        $exports = MedicationEvent::where('kind', 'export.created')->get();
        $this->assertCount(4, $exports);
        foreach ($exports as $export) {
            $this->assertSame($day, $export->facts['date_from']);
            $this->assertSame($day, $export->facts['date_to']);
            $this->assertSame($this->person->id, $export->client_id);
            $this->assertSame($this->site->id, $export->site_id);
            $this->assertTrue($export->controlled);
        }
    }

    public function test_retained_discrepancy_exports_require_each_exact_read_export_and_controlled_grant(): void
    {
        $this->discrepancy('LOCAL PRIVATE DISCREPANCY', '2026-09-29 23:00:00');
        foreach (['medications.reports.view', 'medications.reports.export', 'medications.controlled.view'] as $key) {
            $id = Permission::where('key', $key)->sole()->id;
            $this->reader->permissionOverrides()->syncWithoutDetaching([$id => ['allowed' => false]]);
            $this->reader->refresh();
            $this->assertFalse($this->reader->canDo($key));
            foreach (self::EXPORT_ROUTES as $route) {
                $response = $this->actingAs($this->reader)->get(route($route, [
                    'site_id' => $this->site->id, 'client_id' => $this->person->id, 'period' => 'today', 'purpose' => 'audit',
                ]))->assertForbidden();
                $this->assertStringNotContainsString('LOCAL PRIVATE DISCREPANCY', $response->getContent());
            }
            $this->assertSame(0, MedicationEvent::where('kind', 'export.created')->count());
            $this->reader->permissionOverrides()->syncWithoutDetaching([$id => ['allowed' => true]]);
            $this->reader->refresh();
        }
    }

    public function test_discrepancy_csv_exports_every_row_across_the_500_row_chunk_boundary(): void
    {
        $rows = array_map(fn ($number) => [
            'client_id' => $this->person->id,
            'client_medication_id' => $this->medicine->id,
            'reported_by' => $this->reader->id,
            'reported_at' => '2026-09-29 23:00:00',
            'status' => 'open',
            'reason' => 'Complete discrepancy '.$number,
            'created_at' => '2026-09-29 23:00:00',
            'updated_at' => '2026-09-29 23:00:00',
        ], range(1, 501));
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('client_controlled_drug_discrepancies')->insert($chunk);
        }
        $csv = $this->actingAs($this->reader)->get(route('reports.medications.export_discrepancies', [
            'site_id' => $this->site->id, 'client_id' => $this->person->id, 'period' => 'today', 'purpose' => 'audit',
        ]))->assertOk()->getContent();
        $exported = $this->csvRows($csv);
        $this->assertCount(502, $exported);
        $reasons = array_column(array_slice($exported, 1), 7);
        $this->assertCount(501, array_unique($reasons));
        $this->assertContains('Complete discrepancy 1', $reasons);
        $this->assertContains('Complete discrepancy 501', $reasons);
        $this->assertSame(1, MedicationEvent::where('kind', 'export.created')->count());
    }

    private function medicine(Client $person): ClientMedication
    {
        $now = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00', 'Pacific/Auckland')->utc());
        try {
            return ClientMedication::factory()->create([
                'client_id' => $person->id, 'name' => 'Controlled date boundary medicine',
                'controlled_drug' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
                'is_prn' => true, 'dose_times' => null, 'start_date' => '2026-01-01', 'end_date' => null,
            ]);
        } finally {
            Carbon::setTestNow($now);
        }
    }

    private function discrepancy(string $reason, string $utcTime, array $overrides = []): void
    {
        ClientControlledDrugDiscrepancy::query()->create(array_replace([
            'client_id' => $this->person->id, 'client_medication_id' => $this->medicine->id,
            'reported_at' => $utcTime, 'reported_by' => $this->reader->id,
            'status' => 'open', 'difference' => -1, 'reason' => $reason,
        ], $overrides));
    }

    private function csvRows(string $csv): array
    {
        return array_map(fn ($row) => str_getcsv($row, ',', '"', ''), preg_split('/\r\n|\n|\r/', trim($csv)));
    }
}
