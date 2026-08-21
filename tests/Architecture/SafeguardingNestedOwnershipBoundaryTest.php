<?php

namespace Tests\Architecture;

use Tests\TestCase;

class SafeguardingNestedOwnershipBoundaryTest extends TestCase
{
    public function test_nested_mutations_resolve_children_through_the_authorized_concern(): void
    {
        $investigations = file_get_contents(app_path('Http/Controllers/SafeguardingInvestigationController.php'));
        $externalReports = file_get_contents(app_path('Http/Controllers/SafeguardingExternalReportController.php'));
        $actionPlans = file_get_contents(app_path('Http/Controllers/SafeguardingActionPlanController.php'));

        $this->assertIsString($investigations);
        $this->assertIsString($externalReports);
        $this->assertIsString($actionPlans);

        $this->assertStringContainsString("authorize('investigate', \$concern)", $investigations);
        $this->assertStringContainsString('$concern->investigations()->findOrFail($investigation)', $investigations);
        $this->assertStringNotContainsString('SafeguardingInvestigation $investigation', $investigations);

        $this->assertStringContainsString("authorize('reportExternal', \$concern)", $externalReports);
        $this->assertStringContainsString('$concern->externalReports()->findOrFail($report)', $externalReports);
        $this->assertStringNotContainsString('SafeguardingExternalReport $report', $externalReports);

        $this->assertSame(3, substr_count($actionPlans, "authorize('update', \$concern)"));
        $this->assertSame(2, substr_count($actionPlans, '$concern->actionPlans()->findOrFail($actionPlan)'));
        $this->assertStringNotContainsString('SafeguardingActionPlan $actionPlan', $actionPlans);
    }
}
