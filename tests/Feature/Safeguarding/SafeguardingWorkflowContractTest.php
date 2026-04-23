<?php

namespace Tests\Feature\Safeguarding;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingActionPlan;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingExternalReport;
use App\Models\SafeguardingInvestigation;
use App\Models\SafeguardingRiskAssessment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SafeguardingWorkflowContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function makeSafeguardingUser(array $permissionKeys): User
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);

        $adminRole = Role::query()->where('name', 'admin')->first();
        if ($adminRole) {
            $user->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                [
                    'description' => str_replace('.', ' ', $permissionKey),
                    'group' => explode('.', $permissionKey)[0],
                ]
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }

    public function test_safeguarding_show_serializes_related_records_for_the_frontend(): void
    {
        $user = $this->makeSafeguardingUser([
            'safeguarding.viewAny',
            'safeguarding.update',
            'safeguarding.investigate',
            'safeguarding.report.external',
        ]);
        $assignee = User::factory()->create(['approved_at' => now()]);

        $concern = SafeguardingConcern::factory()->create([
            'reported_by_user_id' => $user->id,
            'assigned_to_user_id' => $assignee->id,
            'assigned_at' => now(),
        ]);

        SafeguardingInvestigation::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'investigation_type' => 'internal',
            'lead_investigator_id' => $user->id,
            'started_at' => now()->subDay(),
            'status' => 'paused',
            'evidence_collected' => ['Door camera footage', 'Whanau call log'],
            'findings' => 'Findings captured for QA.',
            'recommendations' => 'Continue weekly monitoring.',
            'created_by' => $user->id,
        ]);

        SafeguardingExternalReport::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'authority_type' => 'police',
            'authority_name' => 'NZ Police',
            'reported_at' => now()->subHours(2),
            'reported_by_user_id' => $user->id,
            'report_method' => 'phone',
            'report_summary' => 'Initial safeguarding notification made.',
            'acknowledgement_received' => true,
            'acknowledged_at' => now()->subHour(),
            'acknowledgement_reference' => 'ACK-123',
            'created_by' => $user->id,
        ]);

        SafeguardingRiskAssessment::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'assessor_id' => $user->id,
            'assessed_at' => now()->subHours(4),
            'risk_factors' => ['Door unsecured', 'Escalating behaviour'],
            'protective_factors' => ['Family support'],
            'risk_to_self' => 'high',
            'risk_to_others' => 'medium',
            'risk_from_others' => 'high',
            'overall_risk_level' => 'high',
            'capacity_assessed' => false,
            'multi_agency_required' => false,
            'protective_measures' => ['Increased observations'],
            'created_by' => $user->id,
        ]);

        SafeguardingActionPlan::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'action_description' => 'Call whanau and confirm support steps.',
            'action_type' => 'protective_measure',
            'assigned_to_user_id' => $assignee->id,
            'due_date' => now()->addDay(),
            'priority' => 2,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get("/safeguarding/{$concern->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('safeguarding/show')
                ->where('canUpdate', true)
                ->where('canInvestigate', true)
                ->where('canReportExternal', true)
                ->where('concern.reportedBy.name', $user->name)
                ->where('concern.assignedTo.name', $assignee->name)
                ->where('concern.investigations.0.status', 'paused')
                ->where('concern.investigations.0.evidence_summary', "Door camera footage\nWhanau call log")
                ->where('concern.externalReports.0.acknowledgment_received', true)
                ->where('concern.externalReports.0.acknowledgment_reference', 'ACK-123')
                ->where('concern.riskAssessments.0.risk_factors', "Door unsecured\nEscalating behaviour")
                ->where('concern.actionPlans.0.assigned_to.name', $assignee->name)
            );
    }

    public function test_action_plan_store_defaults_priority_and_normalizes_legacy_action_type(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.update']);
        $assignee = User::factory()->create(['approved_at' => now()]);
        $concern = SafeguardingConcern::factory()->create();

        $this->actingAs($user)
            ->post("/safeguarding/{$concern->id}/action-plans", [
                'action_description' => 'Call whanau and confirm immediate support steps.',
                'action_type' => 'immediate',
                'assigned_to_user_id' => $assignee->id,
                'due_date' => now()->addDay()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('safeguarding_action_plans', [
            'safeguarding_concern_id' => $concern->id,
            'action_type' => 'protective_measure',
            'priority' => 3,
        ]);
    }

    public function test_investigation_update_normalizes_legacy_status_and_evidence_summary(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.investigate']);
        $concern = SafeguardingConcern::factory()->create();
        $investigation = SafeguardingInvestigation::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'investigation_type' => 'internal',
            'lead_investigator_id' => $user->id,
            'started_at' => now()->subDay(),
            'status' => 'planned',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->put("/safeguarding/{$concern->id}/investigations/{$investigation->id}", [
                'status' => 'on_hold',
                'evidence_summary' => "Door camera footage\nWhanau call log",
                'findings' => 'Findings verified during QA.',
            ])
            ->assertRedirect();

        $investigation->refresh();

        $this->assertSame('paused', $investigation->status);
        $this->assertSame(['Door camera footage', 'Whanau call log'], $investigation->evidence_collected);
    }

    public function test_external_report_update_accepts_acknowledgment_alias_fields(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.report.external']);
        $concern = SafeguardingConcern::factory()->create();
        $report = SafeguardingExternalReport::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'authority_type' => 'police',
            'authority_name' => 'NZ Police',
            'reported_at' => now()->subDay(),
            'reported_by_user_id' => $user->id,
            'report_method' => 'phone',
            'report_summary' => 'Initial report lodged.',
            'acknowledgement_received' => false,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->put("/safeguarding/{$concern->id}/external-reports/{$report->id}", [
                'acknowledgment_received' => 1,
                'acknowledgment_date' => now()->toDateTimeString(),
                'acknowledgment_reference' => 'ACK-456',
            ])
            ->assertRedirect();

        $report->refresh();

        $this->assertTrue($report->acknowledgement_received);
        $this->assertSame('ACK-456', $report->acknowledgement_reference);
        $this->assertNotNull($report->acknowledged_at);
    }
}
