<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Services\GovernanceWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceWorkflowServiceTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_dashboard_workflow_prioritizes_overdue_resolution(): void
    {
        $admin = $this->createAdminUser();

        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->subDay(),
        ]);

        $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(20),
        ]);

        $service = app(GovernanceWorkflowService::class);
        $workflow = $service->dashboardWorkflow($admin);

        $this->assertNotEmpty($workflow['actions']);
        $first = $workflow['actions'][0];

        $this->assertSame("resolution:{$resolution->id}", $first['id']);
        $this->assertSame('overdue', $first['status']);
        $this->assertSame('critical', $first['priority']);
    }

    public function test_meeting_checklist_marks_pack_as_blocked_when_agenda_missing(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin, [
            'scheduled_at' => now()->addDays(3),
        ]);

        $service = app(GovernanceWorkflowService::class);
        $checklist = $service->meetingChecklist($meeting, $admin);

        $packGenerated = collect($checklist['items'])->firstWhere('key', 'pack_generated');
        $agenda = collect($checklist['items'])->firstWhere('key', 'agenda');

        $this->assertNotNull($packGenerated);
        $this->assertSame('blocked', $packGenerated['status']);
        $this->assertSame('Agenda is empty', $packGenerated['blocked_by']);

        $this->assertNotNull($agenda);
        $this->assertSame('todo', $agenda['status']);
        $this->assertSame('Agenda prepared', $checklist['next_step']['label']);
    }
}
