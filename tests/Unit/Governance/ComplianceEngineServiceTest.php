<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Services\ComplianceEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class ComplianceEngineServiceTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    public function test_create_obligation_defaults(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        $service = new ComplianceEngineService();
        $obligation = $service->createObligation(
            'privacy_act',
            'Privacy review',
            'Review privacy controls',
            'annual',
            $admin,
            now()->addMonths(2),
            'PRIV-100',
            [30, 7]
        );

        $this->assertInstanceOf(ComplianceObligation::class, $obligation);
        $this->assertEquals('privacy_act', $obligation->framework);
        $this->assertEquals('PRIV-100', $obligation->obligation_code);
        $this->assertEquals($admin->id, $obligation->owner_id);
        $this->assertEquals([30, 7], $obligation->reminder_days);
    }

    public function test_complete_obligation_schedules_next_occurrence(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        $service = new ComplianceEngineService();
        $obligation = $service->createObligation(
            'privacy_act',
            'Privacy review',
            'Review privacy controls',
            'annual',
            $admin,
            now()->addMonths(2),
            'PRIV-101',
            [30, 7]
        );

        $service->completeObligation($obligation, $admin);

        $obligation->refresh();
        $this->assertEquals('complete', $obligation->status);
        $this->assertEquals($admin->id, $obligation->completed_by);

        $this->assertEquals(2, ComplianceObligation::where('obligation_code', 'PRIV-101')->count());
    }

    public function test_schedule_reminders_creates_pending_entries(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        $service = new ComplianceEngineService();
        $obligation = $service->createObligation(
            'privacy_act',
            'Privacy review',
            'Review privacy controls',
            'annual',
            $admin,
            now()->addDays(10),
            'PRIV-102',
            [7, 1]
        );

        $service->scheduleReminders($obligation);

        $this->assertDatabaseCount('compliance_reminders', 2);
    }
}
