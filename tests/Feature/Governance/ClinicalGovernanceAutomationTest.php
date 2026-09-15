<?php

namespace Tests\Feature\Governance;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Governance\Models\ClinicalGovernanceIndicator;
use App\Domain\Governance\Models\ClinicalGovernanceSnapshot;
use App\Models\Client;
use App\Models\MedicationError;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\ClinicalPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class ClinicalGovernanceAutomationTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedGovernance();
        $this->seed(ClinicalPermissionsSeeder::class);

        $adminRole = Role::query()->where('name', 'admin')->first();
        $adminRole?->permissions()->sync(Permission::query()->pluck('id'));
    }

    public function test_governance_clinical_automation_pipeline_syncs_and_surfaces_snapshot_data(): void
    {
        // 12:00 on 15 May in New Zealand.
        Carbon::setTestNow(Carbon::parse('2026-05-15 00:00:00'));
        $this->beforeApplicationDestroyed(fn () => Carbon::setTestNow());

        $admin = $this->createAdminUser();
        $client = Client::factory()->create();
        $reporter = User::factory()->create();

        ClinicalGovernanceIndicator::create([
            'indicator_code' => 'LEGACY-001',
            'category' => 'complaints',
            'name' => 'Legacy Manual Indicator',
            'unit' => 'count',
            'frequency' => 'monthly',
            'is_automated' => false,
            'is_active' => true,
        ]);

        ClinicalEvent::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $reporter->id,
            'event_type' => ClinicalEventType::Fall,
            'occurred_at' => now()->subDays(2),
            'reported_at' => now()->subDays(2),
        ]);

        ClinicalEvent::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $reporter->id,
            'event_type' => ClinicalEventType::SkinIntegrity,
            'occurred_at' => now()->subDay(),
            'reported_at' => now()->subDay(),
        ]);

        ClinicalEvent::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $reporter->id,
            'event_type' => ClinicalEventType::InfectionSign,
            'occurred_at' => now()->subHours(3),
            'reported_at' => now()->subHours(3),
        ]);

        ClinicalEvent::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $reporter->id,
            'event_type' => ClinicalEventType::Fall,
            'occurred_at' => now()->subMonth()->startOfMonth(),
            'reported_at' => now()->subMonth()->startOfMonth(),
        ]);

        MedicationError::create([
            'client_id' => $client->id,
            'error_type' => 'wrong_dose',
            'severity' => 'minor',
            'description' => 'Incorrect dose recorded.',
            'reported_by' => $reporter->id,
            'reported_at' => now()->subHours(5),
            'status' => 'reported',
        ]);

        MedicationError::create([
            'client_id' => $client->id,
            'error_type' => 'wrong_time',
            'severity' => 'minor',
            'description' => 'Older month medication error.',
            'reported_by' => $reporter->id,
            'reported_at' => now()->subMonth()->startOfMonth(),
            'status' => 'reported',
        ]);

        $response = $this->actingAs($admin)->get('/governance/clinical');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Governance/Clinical/Dashboard')
            ->has('indicators', 4)
            ->where('sourceHint', 'Counted automatically from medication errors in eMAR and from falls, skin injuries and signs of infection recorded in Health & clinical.')
            ->where('latestSnapshot.period_label', 'May so far (1–15 May)')
            ->where('latestSnapshot.short_label', 'May 2026 (so far)')
            ->where('latestSnapshot.compared_with_label', '1–15 Apr')
        );

        $snapshot = ClinicalGovernanceSnapshot::query()->sole();
        $values = collect($snapshot->indicator_values)->keyBy('indicator_code');
        $indicatorCodes = collect($response->inertiaProps('indicators'))->pluck('indicator_code')->all();

        $this->assertSame(['HCG-001', 'HCG-002', 'HCG-003', 'HCG-004'], $indicatorCodes);
        $this->assertSame(['Medication errors', 'Falls', 'Skin injuries', 'Signs of infection'], collect($response->inertiaProps('indicators'))->pluck('name')->all());
        $this->assertEquals(1, $values->get('HCG-001')['value']);
        $this->assertEquals(1, $values->get('HCG-002')['value']);
        $this->assertEquals(1, $values->get('HCG-003')['value']);
        $this->assertEquals(1, $values->get('HCG-004')['value']);
        // The generated narrative only repeated the numbers.
        $this->assertNull($snapshot->narrative);

        $currentValues = collect($response->inertiaProps('latestSnapshot.indicator_values'))->keyBy('indicator_code');

        $this->assertSame('/emar/errors?date_from=2026-05-01&date_to=2026-05-15', $currentValues->get('HCG-001')['source_href']);
        $this->assertSame('/health-clinical/events?event_type=fall&date_from=2026-05-01&date_to=2026-05-15', $currentValues->get('HCG-002')['source_href']);
        // Compared with the same days last month (1–15 April): one fall then, one now.
        $this->assertEquals(1, $currentValues->get('HCG-002')['previous_value']);
        $this->assertSame('stable', $currentValues->get('HCG-002')['trend']);
        $this->assertEquals(0, $currentValues->get('HCG-003')['previous_value']);
        $this->assertSame('up', $currentValues->get('HCG-003')['trend']);
        $this->assertTrue($currentValues->get('HCG-001')['recorded']);
        $this->actingAs($admin)->get('/governance/clinical')->assertOk();

        $trendsResponse = $this->actingAs($admin)->get('/governance/clinical/trends');

        $trendsResponse->assertOk();
        $trendsResponse->assertInertia(fn (Assert $page) => $page
            ->component('Governance/Clinical/Trends')
            ->has('indicators', 4)
            ->has('snapshots', 1)
        );

        ClinicalEvent::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $reporter->id,
            'event_type' => ClinicalEventType::Fall,
            'occurred_at' => now()->subHours(2),
            'reported_at' => now()->subHours(2),
        ]);

        $this->artisan('governance:sync-clinical-data')->assertSuccessful();

        $snapshot = ClinicalGovernanceSnapshot::query()->sole();
        $values = collect($snapshot->indicator_values)->keyBy('indicator_code');

        $this->assertDatabaseCount('clinical_governance_snapshots', 1);
        $this->assertEquals(2, $values->get('HCG-002')['value']);
    }

    public function test_care_quality_counts_nz_calendar_days_and_finishes_last_month(): void
    {
        $admin = $this->createAdminUser();
        $client = Client::factory()->create();
        $reporter = User::factory()->create();
        $fall = fn (string $utc) => ClinicalEvent::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $reporter->id,
            'event_type' => ClinicalEventType::Fall,
            'occurred_at' => Carbon::parse($utc),
            'reported_at' => Carbon::parse($utc),
        ]);

        // 12:00 on 10 May in New Zealand: May is counted part-way.
        Carbon::setTestNow(Carbon::parse('2026-05-10 00:00:00'));
        $this->beforeApplicationDestroyed(fn () => Carbon::setTestNow());
        $fall('2026-05-02 00:00:00');
        $this->actingAs($admin)->get('/governance/clinical')->assertOk();

        // Later in May, after that visit.
        $fall('2026-05-20 00:00:00');
        // 23:00 on 31 May in New Zealand — still May.
        $fall('2026-05-31 11:00:00');
        // 01:00 on 1 June in New Zealand — June, although it is still 31 May in UTC.
        $fall('2026-05-31 13:00:00');

        // 12:30 on 1 June in New Zealand.
        Carbon::setTestNow(Carbon::parse('2026-06-01 00:30:00'));

        $this->actingAs($admin)
            ->get('/governance/clinical')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('latestSnapshot.period_label', 'June so far (1 Jun)')
                ->where('latestSnapshot.compared_with_label', '1 May'));

        $may = ClinicalGovernanceSnapshot::query()->whereDate('period_start', '2026-05-01')->sole();
        $june = ClinicalGovernanceSnapshot::query()->whereDate('period_start', '2026-06-01')->sole();

        $this->assertSame('2026-05-31', $may->period_end->toDateString());
        $this->assertEquals(3, collect($may->indicator_values)->firstWhere('indicator_code', 'HCG-002')['value']);
        $this->assertEquals(1, collect($june->indicator_values)->firstWhere('indicator_code', 'HCG-002')['value']);

        $this->actingAs($admin)
            ->get('/governance/clinical/trends')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('snapshots', 2)
                ->where('snapshots.0.short_label', 'Jun 2026 (so far)')
                ->where('snapshots.1.period_label', 'May 2026')
                ->where('snapshots.1.is_complete', true)
                ->where('snapshots.1.compared_with_label', 'April 2026'));
    }

    public function test_care_quality_says_no_data_yet_until_anything_is_recorded(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/governance/clinical?status=no_data')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'no_data')
            ->has('latestSnapshot.indicator_values', 4));

        foreach ($response->inertiaProps('latestSnapshot.indicator_values') as $value) {
            $this->assertFalse($value['recorded'], "{$value['indicator_code']} should have no data yet.");
        }
    }

    public function test_governance_clinical_manage_route_stores_manual_indicator_with_schema_compatible_fields(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/governance/clinical/indicators', [
            'name' => 'Complaints escalated to governance',
            'category' => 'complaints',
            'description' => 'Manual indicator tracked outside the automated H&C feed.',
            'target_value' => 2,
            'target_direction' => 'below',
            'unit' => 'count',
            'reporting_frequency' => 'monthly',
        ]);

        $response->assertRedirect();

        $indicator = ClinicalGovernanceIndicator::query()->sole();

        $this->assertSame('HCG-MANUAL-001', $indicator->indicator_code);
        $this->assertSame('complaints', $indicator->category);
        $this->assertSame('Complaints escalated to governance', $indicator->name);
        $this->assertSame('Manual indicator tracked outside the automated H&C feed.', $indicator->definition);
        $this->assertSame('Manual governance input', $indicator->data_source);
        $this->assertSame('count', $indicator->unit);
        $this->assertSame('monthly', $indicator->frequency);
        $this->assertFalse((bool) $indicator->is_automated);
        $this->assertTrue((bool) $indicator->is_active);
    }
}
