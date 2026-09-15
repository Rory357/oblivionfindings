<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\MeetingAgendaItem;
use App\Domain\Governance\Services\GovernanceWorkflowService;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * GOV-R12/R17 — a meeting whose paper has a resolution follow-up action must
 * render for members with an explicit, audience-filtered action and document
 * payload. GOV-R02/R10 — readiness counts use the same visible agenda records
 * as the member meeting workspace.
 */
class GovernanceMeetingPaperFollowUpTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
        Cache::flush();
    }

    private function createOrdinaryMember(): User
    {
        $user = $this->createUserWithRole('board_member');
        $this->createBoardMember($user);

        return $user;
    }

    private function denyPermission(User $user, string $key): void
    {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->attach($permission->id, ['allowed' => false]);
    }

    public function test_meeting_administration_priorities_reach_only_people_who_can_do_them(): void
    {
        $chair = $this->createAdminUser();
        $member = $this->createOrdinaryMember();
        // A past meeting with no agenda, attendance or minutes: every admin task applies.
        $meeting = $this->createMeeting($chair, [
            'title' => 'Past Board Meeting',
            'scheduled_at' => now()->subDays(3),
            'status' => 'scheduled',
        ]);

        $service = app(GovernanceWorkflowService::class);
        $adminTaskIds = [
            "meeting:{$meeting->id}:agenda",
            "meeting:{$meeting->id}:quorum",
            "meeting:{$meeting->id}:minutes-draft",
        ];

        $chairActions = collect($service->dashboardWorkflow($chair)['actions'])->keyBy('id');
        $chairIds = $chairActions->keys()->all();
        foreach ($adminTaskIds as $id) {
            $this->assertContains($id, $chairIds);
        }

        // Plain wording that leads with the meeting's own title.
        $this->assertSame('Record who attended Past Board Meeting', $chairActions["meeting:{$meeting->id}:quorum"]['title']);
        $this->assertSame('Record attendance', $chairActions["meeting:{$meeting->id}:quorum"]['action_label']);
        $this->assertSame('Add agenda items for Past Board Meeting', $chairActions["meeting:{$meeting->id}:agenda"]['title']);
        $this->assertSame('Write the minutes for Past Board Meeting', $chairActions["meeting:{$meeting->id}:minutes-draft"]['title']);
        $this->assertSame('', $chairActions["meeting:{$meeting->id}:quorum"]['source']['reference']);

        $memberWorkflow = $service->dashboardWorkflow($member);
        $memberIds = collect($memberWorkflow['actions'])->pluck('id')->all();
        foreach ($adminTaskIds as $id) {
            $this->assertNotContains($id, $memberIds);
        }
        $this->assertSame(count($memberIds), $memberWorkflow['summary']['total']);

        // Attendance can't be recorded before the meeting day, so a meeting
        // next week raises its agenda task but no attendance task yet.
        $nextWeek = $this->createMeeting($chair, [
            'title' => 'Next Week Board Meeting',
            'scheduled_at' => now()->addDays(5),
            'status' => 'scheduled',
        ]);
        $laterIds = collect($service->dashboardWorkflow($chair)['actions'])->pluck('id')->all();
        $this->assertContains("meeting:{$nextWeek->id}:agenda", $laterIds);
        $this->assertNotContains("meeting:{$nextWeek->id}:quorum", $laterIds);
    }

    public function test_in_meeting_paper_wizard_options_reach_only_paper_authors(): void
    {
        $chair = $this->createAdminUser();
        $member = $this->createOrdinaryMember();
        $meeting = $this->createMeeting($chair, ['title' => 'Ordinary Board Meeting']);

        $this->actingAs($member)
            ->get("/governance/meetings/{$meeting->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Meetings/Show')
                ->where('users', [])
                ->where('committees', [])
                ->where('authoritySubjects', null)
                ->where('authoritySubjectGroups', [])
                ->where('canPublishPapers', false));

        $this->actingAs($chair)
            ->get("/governance/meetings/{$meeting->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('users.0', fn ($user) => $user->hasAll(['id', 'name'])->missing('email')->etc())
                ->has('authoritySubjects')
                ->has('authoritySubjectGroups.0', fn ($group) => $group->hasAll(['key', 'subject_type', 'label'])));
    }

    public function test_member_meeting_with_resolution_follow_up_action_renders_typed_action_and_document_payload(): void
    {
        Storage::fake('local');

        $chair = $this->createAdminUser();
        $member = $this->createOrdinaryMember();
        $otherAssignee = $this->createUserWithRole('board_secretary');
        $meeting = $this->createMeeting($chair, ['title' => 'Ordinary Board Meeting']);

        $attachmentId = (string) Str::uuid();
        $storedPath = "governance/resolutions/briefing-{$attachmentId}.pdf";
        Storage::disk('local')->put($storedPath, '%PDF-1.4 synthetic briefing');

        $resolution = $this->createResolution($chair, [
            'governance_meeting_id' => $meeting->id,
            'title' => 'Approve equipment funding',
            'status' => 'closed',
            'outcome' => 'carried',
            'attachments' => [[
                'id' => $attachmentId,
                'path' => $storedPath,
                'original_name' => 'Equipment briefing.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 2048,
                'uploaded_at' => now()->toIso8601String(),
                'uploaded_by_name' => $chair->name,
            ]],
        ]);

        $mine = $this->createActionItem($chair, $member, [
            'action_reference' => 'ACT-FOLLOWUP-MINE',
            'title' => 'Arrange equipment delivery',
            'source_type' => 'resolution',
            'source_id' => $resolution->id,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        $theirs = $this->createActionItem($chair, $otherAssignee, [
            'action_reference' => 'ACT-FOLLOWUP-THEIRS',
            'title' => 'Confidential supplier negotiation',
            'source_type' => 'resolution',
            'source_id' => $resolution->id,
        ]);

        $response = $this->actingAs($member)
            ->get("/governance/meetings/{$meeting->id}?tab=resolutions&paper={$resolution->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Meetings/Show')
            ->has('resolutions', 1)
            ->where('resolutions.0.id', $resolution->id)
            ->where('resolutions.0.restricted_action_items_count', 0)
            ->has('resolutions.0.action_items', 2)
            ->where('resolutions.0.action_items', function ($actions) use ($mine, $member) {
                $row = collect($actions)->firstWhere('id', $mine->id);

                return $row !== null
                    && $row['reference'] === 'ACT-FOLLOWUP-MINE'
                    && $row['title'] === 'Arrange equipment delivery'
                    && $row['assignee_name'] === $member->name
                    && $row['is_mine'] === true
                    && $row['can_open'] === true
                    && $row['open_url'] === "/governance/actions/{$mine->id}"
                    && array_keys($row) === ['id', 'reference', 'title', 'status', 'priority', 'due_date', 'due_label', 'assignee_name', 'is_mine', 'can_open', 'open_url'];
            })
            ->has('resolutions.0.attachments', 1)
            ->where('resolutions.0.attachments.0.original_name', 'Equipment briefing.pdf')
            ->where('resolutions.0.attachments.0.size_bytes', 2048)
            ->where('resolutions.0.attachments.0.download_url', "/governance/resolutions/{$resolution->id}/attachments/{$attachmentId}/download")
            ->missing('resolutions.0.attachments.0.path')
            // The nested meeting copy must not carry the raw relation or storage paths.
            ->missing('meeting.resolutions.0.action_items')
            ->missing('meeting.resolutions.0.attachments')
        );

        // The rendered controls resolve for this member.
        $this->actingAs($member)->get("/governance/actions/{$mine->id}")->assertOk();
        // Re-assert the managed file immediately before downloading: parallel
        // suites in the same checkout share (and wipe) the fake local disk.
        Storage::disk('local')->put($storedPath, '%PDF-1.4 synthetic briefing');
        $this->actingAs($member)
            ->get("/governance/resolutions/{$resolution->id}/attachments/{$attachmentId}/download")
            ->assertOk();

        $this->assertNotSame($mine->id, $theirs->id);
    }

    public function test_follow_up_actions_the_member_cannot_view_are_counted_but_never_titled(): void
    {
        $chair = $this->createAdminUser();
        $member = $this->createOrdinaryMember();
        $this->denyPermission($member, 'governance.actions.view');
        $otherAssignee = $this->createUserWithRole('board_secretary');
        $meeting = $this->createMeeting($chair);

        $resolution = $this->createResolution($chair, [
            'governance_meeting_id' => $meeting->id,
            'status' => 'closed',
            'outcome' => 'carried',
        ]);

        $mine = $this->createActionItem($chair, $member, [
            'title' => 'My visible follow-up',
            'source_type' => 'resolution',
            'source_id' => $resolution->id,
        ]);
        $this->createActionItem($chair, $otherAssignee, [
            'title' => 'PRIVATE follow-up title',
            'source_type' => 'resolution',
            'source_id' => $resolution->id,
        ]);

        $response = $this->actingAs($member)->get("/governance/meetings/{$meeting->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Meetings/Show')
            ->has('resolutions.0.action_items', 1)
            ->where('resolutions.0.action_items.0.id', $mine->id)
            // Without the action route permission no open control is issued.
            ->where('resolutions.0.action_items.0.can_open', false)
            ->where('resolutions.0.action_items.0.open_url', null)
            ->where('resolutions.0.restricted_action_items_count', 1)
        );

        $this->assertStringNotContainsString('PRIVATE follow-up title', $response->getContent());
    }

    public function test_readiness_counts_exclude_confidential_agenda_items_the_member_cannot_see(): void
    {
        $chair = $this->createAdminUser();
        $member = $this->createOrdinaryMember();
        $meeting = $this->createMeeting($chair, [
            'title' => 'Next Board Meeting',
            'scheduled_at' => now()->addDays(5),
        ]);

        MeetingAgendaItem::create([
            'governance_meeting_id' => $meeting->id,
            'order' => 1,
            'title' => 'CONFIDENTIAL acquisition briefing',
            'duration_minutes' => 30,
            'item_type' => 'decision',
            'is_confidential' => true,
        ]);

        // Member meeting workspace: Agenda (0) and the checklist agrees.
        $this->actingAs($member)->get("/governance/meetings/{$meeting->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Meetings/Show')
                ->has('meeting.agenda_items', 0)
                ->where('workflowChecklist.items', fn ($items) => collect($items)->firstWhere('key', 'agenda')['status'] === 'todo')
            );

        // Service contract: counts derive from the member-visible agenda.
        $memberChecklist = app(GovernanceWorkflowService::class)->meetingChecklist($meeting->fresh(), $member);
        $agenda = collect($memberChecklist['items'])->firstWhere('key', 'agenda');
        $this->assertSame('todo', $agenda['status']);
        $this->assertStringNotContainsString('1 agenda item', $agenda['detail']);

        // Home (dashboard) next-meeting readiness uses the same records.
        $home = $this->actingAs($member)->getJson('/governance/dashboard/data?period=month&fresh=1');
        $home->assertOk();
        $this->assertSame($meeting->id, $home->json('cockpit.next_meeting.meeting.id'));
        $homeAgenda = collect($home->json('cockpit.next_meeting.checklist'))->firstWhere('key', 'agenda');
        $this->assertSame('todo', $homeAgenda['status']);
        $this->assertStringNotContainsString('1 agenda item', $homeAgenda['detail']);
        $this->assertStringNotContainsString('CONFIDENTIAL acquisition briefing', $home->getContent());

        // A viewer entitled to the confidential item still sees it counted.
        $chairChecklist = app(GovernanceWorkflowService::class)->meetingChecklist($meeting->fresh(), $chair);
        $chairAgenda = collect($chairChecklist['items'])->firstWhere('key', 'agenda');
        $this->assertSame('done', $chairAgenda['status']);
        $this->assertStringContainsString('1 agenda item', $chairAgenda['detail']);
    }
}
