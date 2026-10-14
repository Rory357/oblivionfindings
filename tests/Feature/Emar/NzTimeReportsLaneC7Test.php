<?php

namespace Tests\Feature\Emar;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditLogViewService;
use App\Services\Compliance\ComplianceMetricsService;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\Reporting\MedicationReportAccess;
use App\Services\MedicationAlertService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Lane C7: reports and the NZ-time sweep (EA-061, EA-062, EA-064, EA-077,
 * EA-081, EA-082, EA-202). Now is 09:00 NZDT on Friday 9 October 2026,
 * which is 20:00 UTC on 8 October: a UTC day reads the wrong NZ day.
 */
class NzTimeReportsLaneC7Test extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-09 09:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active', 'first_name' => 'Aroha', 'last_name' => 'Ngata']);
        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->admin = $this->admin->fresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** EA-061: the generic module report filters NZ days and shows NZ times. */
    public function test_module_report_dates_are_nz_days_and_rows_show_nz_time(): void
    {
        $morning = ClientIncident::factory()->create(['client_id' => $this->client->id, 'occurred_at' => Carbon::parse('2026-10-09 08:30', 'Pacific/Auckland')->utc()]);
        ClientIncident::factory()->create(['client_id' => $this->client->id, 'occurred_at' => Carbon::parse('2026-10-10 08:30', 'Pacific/Auckland')->utc()]);
        ClientIncident::factory()->create(['client_id' => $this->client->id, 'occurred_at' => Carbon::parse('2026-10-08 20:00', 'Pacific/Auckland')->utc()]);

        $this->actingAs($this->admin)->get('/reports/modules/incidents?date_from=2026-10-09&date_to=2026-10-09')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('rows.data', 1)
                ->where('rows.data.0.id', (string) $morning->id)
                ->where('rows.data.0.occurred_at', '2026-10-09 08:30 NZDT'));
    }

    /** EA-077: the audit log's date filter and Today / This week / This month are NZ days. */
    public function test_audit_log_date_filter_and_counters_use_nz_days(): void
    {
        $at = fn (string $nz) => Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
        $log = fn (string $action) => AuditLog::query()->create(['user_id' => $this->admin->id, 'action' => $action, 'auditable_type' => User::class, 'auditable_id' => $this->admin->id, 'meta' => []]);
        $at('2026-10-09 08:30');
        $today = $log('synthetic.today');
        $at('2026-10-08 14:00');
        $log('synthetic.yesterday');
        $at('2026-10-05 09:00'); // Monday, this week
        $log('synthetic.monday');
        $at('2026-10-01 09:00');
        $log('synthetic.this_month');
        $at('2026-10-09 09:00');

        $service = app(AuditLogViewService::class);
        $filters = ['date_from' => '2026-10-09', 'date_to' => '2026-10-09'];
        $this->assertSame([$today->id], $service->query($filters, $this->admin)->where('action', 'like', 'synthetic.%')->pluck('id')->all());
        $stats = $service->stats(['search' => 'synthetic.'], $this->admin);
        $this->assertSame(['today' => 1, 'this_week' => 3, 'this_month' => 4], $stats);
    }

    /** EA-081 / EA-082: compliance buckets by NZ day and links to the canonical eMAR views. */
    public function test_compliance_sparklines_bucket_nz_days_and_kpis_link_to_emar_views(): void
    {
        $service = app(ComplianceMetricsService::class);
        Carbon::setTestNow(Carbon::parse('2026-10-09 08:30', 'Pacific/Auckland')->utc());
        AuditLog::query()->create(['user_id' => $this->admin->id, 'action' => 'synthetic.bucket', 'auditable_type' => User::class, 'auditable_id' => $this->admin->id, 'meta' => []]);
        Carbon::setTestNow(Carbon::parse('2026-10-09 09:00', 'Pacific/Auckland')->utc());

        $daily = new ReflectionMethod(ComplianceMetricsService::class, 'dailyCounts');
        $series = $daily->invoke($service, AuditLog::query()->where('action', 'synthetic.bucket'), 'created_at', 3);
        $this->assertSame([0, 0, 1], $series, 'An 08:30 NZDT event belongs to today, not the UTC yesterday.');

        $hrefs = collect($service->exceptionKpis($this->admin, '14d'))->pluck('href', 'key');
        $this->assertSame('/emar/mar?date=2026-10-09', $hrefs['mar']);
        $this->assertSame('/emar/controlled?view=discrepancies', $hrefs['cd']);
    }

    /** EA-202: the legacy Today's Medications widget counts the NZ day. */
    public function test_legacy_todays_summary_counts_the_nz_day(): void
    {
        $order = ClientMedication::query()->create(['client_id' => $this->client->id, 'name' => 'Synthetic twice daily', 'dosage' => '1 tablet', 'frequency' => 'Twice daily',
            'dose_times' => ['08:00', '20:00'], 'active' => true, 'state' => 'active', 'is_prn' => false, 'approval_status' => 'verified', 'start_date' => '2026-10-01']);
        foreach (['2026-10-09 08:00', '2026-10-08 20:00'] as $nz) {
            ClientMedicationAdministration::query()->create(['client_id' => $this->client->id, 'client_medication_id' => $order->id, 'administered_by' => $this->admin->id,
                'status' => 'given', 'scheduled_for' => Carbon::parse($nz, 'Pacific/Auckland')->utc(), 'administered_at' => Carbon::parse($nz, 'Pacific/Auckland')->utc(), 'dose_given' => '1 tablet']);
        }

        $summary = app(MedicationAlertService::class)->getGlobalDashboardWidgets(null, [(int) $this->site->id])['todays_summary'];
        $this->assertSame(2, $summary['total_scheduled']);
        $this->assertSame(1, $summary['completed'], 'Last night\'s 8 pm dose is not today\'s.');
    }

    /** EA-062: an archived person stays in the audit reader's reports, and their events keep them. */
    public function test_an_archived_person_stays_in_the_audit_trail(): void
    {
        DB::table('clients')->where('id', $this->client->id)->update(['deleted_at' => now()->subDay()]);
        $this->assertContains((int) $this->client->id, app(MedicationReportAccess::class)->clientIds($this->admin, [(int) $this->site->id]));

        DB::transaction(fn () => app(MedicationEventRecorder::class)->append(new MedicationEventData(
            siteId: (int) $this->site->id, kind: 'followup.source_completed', subjectType: 'medication_followup', subjectId: '1',
            actorId: (int) $this->admin->id, occurredAt: CarbonImmutable::now('UTC'), summary: 'Synthetic closing event.', facts: [],
            clientId: (int) $this->client->id,
        )));
        $this->assertSame((int) $this->client->id, (int) MedicationEvent::query()->sole()->client_id);

        $this->actingAs($this->admin)->get('/emar/reports?view=audit&site_id='.$this->site->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('people', fn ($people) => collect($people)->contains('name', 'Aroha Ngata (archived)')));
    }

    /** EA-064: a Doses file that starts before scheduled dose records exist says so. */
    public function test_a_doses_export_before_slot_coverage_states_the_gap(): void
    {
        $response = $this->actingAs($this->admin)->post('/emar/reports/export', [
            'type' => 'doses', 'site_id' => $this->site->id, 'period' => 'custom', 'date_from' => '2026-09-01', 'date_to' => '2026-10-09', 'purpose' => 'audit',
        ])->assertOk();
        $lines = preg_split('/\r?\n/', ltrim((string) $response->getContent(), "\xEF\xBB\xBF"));
        $this->assertStringContainsString('Note: scheduled doses are not available before 9 October 2026', $lines[0]);
        $this->assertStringContainsString('only as-needed doses before that day', $lines[0]);
        $event = MedicationEvent::query()->where('kind', 'export.created')->sole();
        $this->assertSame('2026-10-09', $event->facts['scheduled_doses_from'] ?? null);

        // A period inside coverage has no note.
        $inside = $this->actingAs($this->admin)->post('/emar/reports/export', [
            'type' => 'doses', 'site_id' => $this->site->id, 'period' => 'custom', 'date_from' => '2026-10-09', 'date_to' => '2026-10-09', 'purpose' => 'audit',
        ])->assertOk();
        $this->assertStringNotContainsString('Note:', (string) $inside->getContent());
    }
}
