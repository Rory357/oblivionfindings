<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\CeoBoardReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceCeoReportsTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_create_deep_link_opens_the_index_wizard_with_meeting_options(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin, ['scheduled_at' => now()->addWeek()]);

        $this->actingAs($admin)->get('/governance/ceo-reports/create')
            ->assertRedirect('/governance/ceo-reports?create=1');

        $this->actingAs($admin)->get('/governance/ceo-reports?create=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/CeoReports/Index')
                ->where('can_create', true)
                ->where('meetings.0.id', $meeting->id)
                ->has('summary')
                ->has('filters'));
    }

    public function test_view_only_members_get_no_wizard_options_or_create_link(): void
    {
        $admin = $this->createAdminUser();
        $this->createMeeting($admin, ['scheduled_at' => now()->addWeek()]);
        $member = $this->createUserWithRole('board_member');

        $this->actingAs($member)->get('/governance/ceo-reports/create')->assertForbidden();
        $this->actingAs($member)->get('/governance/ceo-reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_create', false)
                ->where('meetings', []));
    }

    public function test_header_filters_narrow_the_list_but_not_the_totals(): void
    {
        $admin = $this->createAdminUser();
        $overdueMeeting = $this->createMeeting($admin, ['title' => 'March board', 'scheduled_at' => now()->addDays(2)]);
        $submittedMeeting = $this->createMeeting($admin, ['title' => 'April board', 'scheduled_at' => now()->addDays(20)]);

        CeoBoardReport::create([
            'governance_meeting_id' => $overdueMeeting->id,
            'submitted_by' => $admin->id,
            'status' => CeoBoardReport::STATUS_DRAFT,
            'deadline' => now()->subDay(),
        ]);
        CeoBoardReport::create([
            'governance_meeting_id' => $submittedMeeting->id,
            'submitted_by' => $admin->id,
            'status' => CeoBoardReport::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)->get('/governance/ceo-reports?status=overdue')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('reports.data', 1)
                ->where('reports.data.0.is_overdue', true)
                ->where('filters.status', 'overdue')
                ->where('summary.total', 2)
                ->where('summary.overdue', 1)
                ->where('summary.submitted', 1));

        $this->actingAs($admin)->get('/governance/ceo-reports?search=April')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('reports.data', 1)
                ->where('reports.data.0.status', CeoBoardReport::STATUS_SUBMITTED));
    }

    public function test_wizard_store_can_submit_immediately(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin, ['scheduled_at' => now()->addWeek()]);

        $response = $this->actingAs($admin)->post('/governance/ceo-reports', [
            'governance_meeting_id' => $meeting->id,
            'executive_summary' => 'Headline outcomes',
            'decisions_sought' => [['title' => 'Approve FY27 budget', 'detail' => '', 'recommendation' => 'Approve']],
            'matters_arising' => [],
            'submit_immediately' => true,
        ]);

        $report = CeoBoardReport::firstOrFail();
        $response->assertRedirect("/governance/ceo-reports/{$report->id}");
        $this->assertSame(CeoBoardReport::STATUS_SUBMITTED, $report->status);
        $this->assertSame('Approve FY27 budget', $report->decisions_sought[0]['title']);
    }
}
