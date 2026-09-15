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

    public function test_a_5pm_nz_deadline_is_stored_as_the_real_instant_and_shown_back_as_5pm(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin, ['scheduled_at' => now()->addMonth()]);

        // 20 August is NZ standard time (UTC+12): 5:00 pm NZ = 5:00 am UTC.
        $this->actingAs($admin)->post('/governance/ceo-reports', [
            'governance_meeting_id' => $meeting->id,
            'deadline' => '2026-08-20T17:00',
        ])->assertRedirect();

        $report = CeoBoardReport::firstOrFail();
        $this->assertSame('2026-08-20 05:00:00', $report->deadline->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2026-08-20 17:00',
            $report->deadline->copy()->setTimezone('Pacific/Auckland')->format('Y-m-d H:i'),
        );

        $this->actingAs($admin)->get("/governance/ceo-reports/{$report->id}")
            ->assertInertia(fn ($page) => $page
                ->where('report.deadline', '2026-08-20T05:00:00+00:00')
                ->where('report.title', "CEO report — {$meeting->title}"));

        // Editing and saving the same wall time again keeps it at 5:00 pm.
        $this->actingAs($admin)->put("/governance/ceo-reports/{$report->id}", [
            'deadline' => '2026-08-20T17:00',
            'executive_summary' => 'Updated',
        ])->assertRedirect()->assertSessionHas('success', 'Changes saved.');

        $this->assertSame('2026-08-20 05:00:00', $report->fresh()->deadline->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_meeting_can_only_have_one_report_and_the_message_says_so(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin, ['scheduled_at' => now()->addWeek()]);
        CeoBoardReport::create([
            'governance_meeting_id' => $meeting->id,
            'submitted_by' => $admin->id,
            'status' => CeoBoardReport::STATUS_DRAFT,
        ]);

        $this->actingAs($admin)
            ->from('/governance/ceo-reports')
            ->post('/governance/ceo-reports', ['governance_meeting_id' => $meeting->id])
            ->assertSessionHasErrors([
                'governance_meeting_id' => 'That meeting already has a CEO report. Open it from the CEO reports list instead.',
            ]);

        $this->assertSame(1, CeoBoardReport::query()->count());
    }

    public function test_submitted_reports_are_locked_and_presented_only_after_submission(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin, ['scheduled_at' => now()->addWeek()]);
        $report = CeoBoardReport::create([
            'governance_meeting_id' => $meeting->id,
            'submitted_by' => $admin->id,
            'status' => CeoBoardReport::STATUS_DRAFT,
            'executive_summary' => 'Draft wording',
        ]);

        $this->actingAs($admin)->from("/governance/ceo-reports/{$report->id}")
            ->post("/governance/ceo-reports/{$report->id}/present")
            ->assertSessionHas('error', 'Submit the report to the board before marking it as presented.');
        $this->assertTrue($report->fresh()->isDraft());

        $this->actingAs($admin)->from("/governance/ceo-reports/{$report->id}")
            ->post("/governance/ceo-reports/{$report->id}/submit")
            ->assertSessionHas('success', "Report sent to the board. It can't be edited now.");
        $this->assertTrue($report->fresh()->isSubmitted());

        $this->actingAs($admin)->from("/governance/ceo-reports/{$report->id}")
            ->put("/governance/ceo-reports/{$report->id}", ['executive_summary' => 'Changed after submitting'])
            ->assertSessionHas('error', "This report has been sent to the board, so it can't be edited.");
        $this->assertSame('Draft wording', $report->fresh()->executive_summary);

        $this->actingAs($admin)->from("/governance/ceo-reports/{$report->id}")
            ->post("/governance/ceo-reports/{$report->id}/present")
            ->assertSessionHas('success', 'Report marked as presented to the board.');
        $this->assertTrue($report->fresh()->isPresented());
    }
}
