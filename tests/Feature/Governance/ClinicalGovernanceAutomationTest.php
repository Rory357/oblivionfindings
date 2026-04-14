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
            ->where('sourceHint', 'Auto-fed from Health & Clinical clinical events and eMAR medication errors.')
        );

        $snapshot = ClinicalGovernanceSnapshot::query()->sole();
        $values = collect($snapshot->indicator_values)->keyBy('indicator_code');
        $indicatorCodes = collect($response->inertiaProps('indicators'))->pluck('indicator_code')->all();

        $this->assertSame(['HCG-001', 'HCG-002', 'HCG-003', 'HCG-004'], $indicatorCodes);
        $this->assertSame(1.0, $values->get('HCG-001')['value']);
        $this->assertSame(1.0, $values->get('HCG-002')['value']);
        $this->assertSame(1.0, $values->get('HCG-003')['value']);
        $this->assertSame(1.0, $values->get('HCG-004')['value']);

        $currentValues = collect($response->inertiaProps('latestSnapshot.indicator_values'))->keyBy('indicator_code');

        $this->assertSame('/emar/errors?date_from=' . now()->startOfMonth()->toDateString() . '&date_to=' . now()->toDateString(), $currentValues->get('HCG-001')['source_href']);
        $this->assertSame('/health-clinical/events?event_type=fall&date_from=' . now()->startOfMonth()->toDateString() . '&date_to=' . now()->toDateString(), $currentValues->get('HCG-002')['source_href']);
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
}
