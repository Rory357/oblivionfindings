<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsEvent;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\HealthSafety\HsEventService;
use App\Services\HealthSafety\HsInvestigationService;
use App\Services\HealthSafety\HsRiskAssessmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HsLegacyStorageCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_hs_services_own_the_inert_legacy_storage_value(): void
    {
        $legacyColumn = 'organization'.'_id';
        $site = Site::factory()->create();

        $event = app(HsEventService::class)->recordEvent([
            'source' => $site,
            'event_category' => HsEvent::CATEGORY_HAZARD,
            'severity' => HsEvent::SEVERITY_LOW,
            'site_id' => $site->id,
            $legacyColumn => 99,
        ]);

        $this->assertNotNull($event);
        $this->assertLegacyStorageIsWriteOnly($event, $legacyColumn);

        $investigation = app(HsInvestigationService::class)->create($event);
        $this->assertLegacyStorageIsWriteOnly($investigation, $legacyColumn);

        $assessmentService = app(HsRiskAssessmentService::class);
        $assessment = $assessmentService->create([
            'title' => 'Application storage compatibility',
            'likelihood' => 2,
            'consequence' => 2,
            $legacyColumn => 99,
        ]);
        $this->assertLegacyStorageIsWriteOnly($assessment, $legacyColumn);

        $assessmentService->activate($assessment);
        $replacement = $assessmentService->supersede($assessment, [
            'title' => 'Replacement application storage compatibility',
            'likelihood' => 1,
            'consequence' => 2,
        ]);
        $this->assertLegacyStorageIsWriteOnly($replacement, $legacyColumn);

        $action = app(HsCorrectiveActionService::class)->createStandalone($event, [
            'title' => 'Verify the compatibility boundary',
        ]);
        $this->assertLegacyStorageIsWriteOnly($action, $legacyColumn);
    }

    public function test_safeguarding_has_no_nonexistent_legacy_storage_contract(): void
    {
        $legacyColumn = 'organization'.'_id';
        $concern = new SafeguardingConcern;

        $this->assertNotContains($legacyColumn, $concern->getFillable());
        $this->assertFalse(method_exists($concern, 'organization'));
        $this->assertFalse(Schema::hasColumn($concern->getTable(), $legacyColumn));
    }

    private function assertLegacyStorageIsWriteOnly(Model $model, string $legacyColumn): void
    {
        $this->assertSame(1, (int) $model->getRawOriginal($legacyColumn));
        $this->assertNotContains($legacyColumn, $model->getFillable());
        $this->assertArrayNotHasKey($legacyColumn, $model->toArray());
    }
}
