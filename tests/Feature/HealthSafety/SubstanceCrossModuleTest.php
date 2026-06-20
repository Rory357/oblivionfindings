<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HazardousSubstance;
use App\Models\SafetyDataSheet;
use App\Models\Site;
use App\Models\SubstanceStorageLocation;
use App\Services\HealthSafety\HsAnalyticsService;
use App\Services\HealthSafety\HsModuleSummaryService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-module wiring for the Chemical register: the analytics SDS-expiring badge
 * feed and the Site profile "Chemicals stored here" summary.
 */
class SubstanceCrossModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_analytics_build_counts_expiring_sds(): void
    {
        $expiring = HazardousSubstance::factory()->create();
        SafetyDataSheet::factory()->expiring()->create(['hazardous_substance_id' => $expiring->id]);

        $healthy = HazardousSubstance::factory()->create();
        SafetyDataSheet::factory()->create(['hazardous_substance_id' => $healthy->id, 'review_date' => now()->addYears(2)]);

        $payload = app(HsAnalyticsService::class)->build(null, now()->subMonths(3), now(), 'leadership');

        $this->assertArrayHasKey('sds_expiring', $payload);
        $this->assertSame(1, $payload['sds_expiring']);
    }

    public function test_site_chemicals_summary_reports_compliance(): void
    {
        $site = Site::factory()->create();
        $controlled = HazardousSubstance::factory()->controlled()->create();

        SubstanceStorageLocation::factory()->create([
            'hazardous_substance_id' => $controlled->id,
            'site_id' => $site->id,
            'segregation_compliant' => false,
        ]);

        $data = app(HsModuleSummaryService::class)->chemicalsStoredForSite($site->id);

        $this->assertCount(1, $data['rows']);
        $this->assertSame(1, $data['summary']['count']);
        $this->assertSame(1, $data['summary']['controlled']);
        $this->assertSame(1, $data['summary']['segregation_gaps']);
        // No current SDS on file ⇒ counts toward SDS-to-action.
        $this->assertSame('missing', $data['rows'][0]['sds_state']);
        $this->assertSame(1, $data['summary']['sds_to_action']);
    }
}
