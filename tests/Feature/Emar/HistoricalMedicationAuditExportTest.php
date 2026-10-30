<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
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

class HistoricalMedicationAuditExportTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    private Client $person;

    private ClientMedication $medicine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-30 12:00', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['is_active' => true]);
        $this->person = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->reader->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'start_date' => today()->subYear(),
        ]);
        $grants = ['medications.view', 'medications.reports.view', 'medications.audit.view', 'medications.audit.export'];
        $denials = ['medications.controlled.view', ...MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS];
        $permissions = Permission::query()->whereIn('key', [...$grants, ...$denials])->get();
        $this->assertCount(count($grants) + count($denials), $permissions);
        $this->reader->permissionOverrides()->sync($permissions->mapWithKeys(fn ($permission) => [
            $permission->id => ['allowed' => in_array($permission->key, $grants, true)],
        ])->all());
        $this->reader->refresh();
        $this->medicine = ClientMedication::factory()->create([
            'client_id' => $this->person->id,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'is_prn' => true,
            'start_date' => '2026-06-01',
            'end_date' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function nzDays(): array
    {
        return [
            'NZ standard time' => ['2026-06-15', '2026-06-14 12:00:00', '2026-06-15 11:59:59', 'NZST', 'NZST'],
            'daylight saving starts (23-hour day)' => ['2026-09-27', '2026-09-26 12:00:00', '2026-09-27 10:59:59', 'NZST', 'NZDT'],
            'NZ daylight time' => ['2026-09-29', '2026-09-28 11:00:00', '2026-09-29 10:59:59', 'NZDT', 'NZDT'],
        ];
    }

    #[DataProvider('nzDays')]
    public function test_reader_and_both_retained_exports_use_the_same_nz_calendar_day(
        string $day,
        string $firstUtc,
        string $lastUtc,
        string $firstZone,
        string $lastZone,
    ): void {
        $this->insertLog('Outside before NZ day', Carbon::parse($firstUtc, 'UTC')->subSecond()->toDateTimeString());
        $firstId = $this->insertLog('First NZ second', $firstUtc);
        $lastId = $this->insertLog('Last NZ second', $lastUtc);
        $this->insertLog('Outside after NZ day', Carbon::parse($lastUtc, 'UTC')->addSecond()->toDateTimeString());
        $scope = ['client_id' => $this->person->id, 'user_id' => $this->reader->id];
        $canonical = ['period' => 'custom', 'date_from' => $day, 'date_to' => $day];
        $legacy = ['from' => $day, 'to' => $day];

        foreach ([$canonical, $legacy] as $dates) {
            $this->actingAs($this->reader)
                ->get(route('emar.reports.history_logs', $scope + $dates))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('emar/reports/history-logs')
                    ->where('filters.period', 'custom')
                    ->where('filters.date_from', $day)
                    ->where('filters.date_to', $day)
                    ->has('logs', 2)
                    ->where('logs.0.id', $lastId)
                    ->where('logs.1.id', $firstId));

            foreach (['medications.audit.export', 'emar.audit.export'] as $route) {
                $csv = $this->get(route($route, $scope + $dates + ['purpose' => 'audit']))
                    ->assertOk()->getContent();
                $this->assertStringContainsString('Time (Pacific/Auckland)', $csv);
                $this->assertStringContainsString($day.' 00:00:00 '.$firstZone, $csv);
                $this->assertStringContainsString($day.' 23:59:59 '.$lastZone, $csv);
                $this->assertStringContainsString('First NZ second', $csv);
                $this->assertStringContainsString('Last NZ second', $csv);
                $this->assertStringNotContainsString('Outside before NZ day', $csv);
                $this->assertStringNotContainsString('Outside after NZ day', $csv);
            }
        }

        $exports = MedicationEvent::query()->where('kind', 'export.created')->get();
        $this->assertCount(4, $exports);
        foreach ($exports as $export) {
            $this->assertSame($day, $export->facts['date_from']);
            $this->assertSame($day, $export->facts['date_to']);
        }
    }

    public function test_history_csv_keeps_all_5001_rows_beyond_the_old_silent_cap(): void
    {
        // Bulk insertion keeps this regression practical while exercising the
        // real scoped query, CSV response and successful export audit gate.
        foreach (array_chunk(range(1, 5001), 500) as $numbers) {
            DB::table('audit_logs')->insert(array_map(fn ($number) => $this->logRow(
                'Complete history row '.$number,
                '2026-09-29 23:00:00',
            ), $numbers));
        }
        $query = [
            'client_id' => $this->person->id,
            'user_id' => $this->reader->id,
            'period' => 'custom',
            'date_from' => '2026-09-30',
            'date_to' => '2026-09-30',
        ];
        $this->actingAs($this->reader)->get(route('emar.reports.history_logs', $query))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('logs', 200));
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.created')->count());
        $csv = $this->get(route('medications.audit.export', $query + ['purpose' => 'audit']))
            ->assertOk()->getContent();
        $rows = preg_split('/\r\n|\n|\r/', trim($csv));
        $this->assertCount(5002, $rows);
        $actions = array_map(fn ($row) => str_getcsv($row)[1], array_slice($rows, 1));
        $this->assertCount(5001, array_unique($actions));
        $this->assertContains('Complete history row 1', $actions);
        $this->assertContains('Complete history row 5001', $actions);
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'export.created')->count());
    }

    private function insertLog(string $action, string $utcTime): int
    {
        return DB::table('audit_logs')->insertGetId($this->logRow($action, $utcTime));
    }

    private function logRow(string $action, string $utcTime): array
    {
        return [
            'client_id' => $this->person->id,
            'user_id' => $this->reader->id,
            'auditable_type' => ClientMedication::class,
            'auditable_id' => $this->medicine->id,
            'action' => $action,
            'meta' => json_encode(['fields' => ['dose_times']], JSON_THROW_ON_ERROR),
            'created_at' => $utcTime,
            'updated_at' => $utcTime,
        ];
    }
}
