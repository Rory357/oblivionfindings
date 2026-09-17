<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\CommitteeMembership;
use App\Domain\Governance\Models\DashboardSnapshot;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\MeetingAttendance;
use App\Domain\Governance\Models\MeetingMinute;
use App\Domain\Governance\Services\MeetingMinuteService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * The meeting workspace, register and scheduling wizard after the
 * plain-language rebuild: members see their own preparation (never the
 * chair and secretary's checklist or other members' personal notes), edits
 * can't skip the minutes controls, committees match their meeting type, and
 * quorum and reply receipts tell the truth.
 */
class GovernanceMeetingWorkspaceTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    private function createOrdinaryMember(): User
    {
        $user = $this->createUserWithRole('board_member');
        $this->createBoardMember($user);

        return $user->fresh();
    }

    public function test_members_get_their_own_preparation_and_never_the_admin_checklist(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createOrdinaryMember();
        $meeting = $this->createMeeting($admin, ['title' => 'October board meeting', 'scheduled_at' => now()->addDays(5)]);

        $this->actingAs($member)->get("/governance/meetings/{$meeting->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Meetings/Show')
                ->where('workflowChecklist.items', [])
                ->where('workflowChecklist.next_step', null)
                ->where('meetingCockpit.cards', [])
                ->where('boardMembers', [])
                ->where('viewerCanRsvp', true)
                ->where('canViewRecordDetails', false)
                ->missing('meeting.ceo_report')
            );

        $this->actingAs($admin)->get("/governance/meetings/{$meeting->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('workflowChecklist.items', 10)
                // Every step says its status in words — never a raw key.
                ->where('workflowChecklist.items', fn ($items) => collect($items)->every(
                    fn (array $item) => is_string($item['status_label']) && ! str_contains($item['status_label'], '_')
                ))
                ->where('meetingCockpit.cards', fn ($cards) => collect($cards)->contains('key', 'ceo_report'))
                ->where('canViewRecordDetails', true)
            );
    }

    public function test_apology_reasons_and_dietary_needs_are_shared_only_with_people_running_the_meeting(): void
    {
        $admin = $this->createAdminUser();
        $away = $this->createOrdinaryMember();
        $viewer = $this->createOrdinaryMember();
        $meeting = $this->createMeeting($admin, ['scheduled_at' => now()->addDays(3)]);

        $this->actingAs($away)->post("/governance/meetings/{$meeting->id}/rsvp", [
            'response' => 'declined',
            'decline_reason' => 'Private medical appointment',
        ])->assertRedirect();
        $this->actingAs($viewer)->post("/governance/meetings/{$meeting->id}/rsvp", [
            'response' => 'accepted',
            'dietary_requirements' => true,
            'dietary_notes' => 'Vegetarian',
        ])->assertRedirect();
        MeetingAttendance::create([
            'governance_meeting_id' => $meeting->id,
            'board_member_id' => $away->boardMember->id,
            'status' => 'apology',
            'apology_reason' => 'Private medical appointment',
            'marked_at' => now(),
            'marked_by' => $admin->id,
        ]);

        $awayId = $away->boardMember->id;
        $memberView = $this->actingAs($viewer)->get("/governance/meetings/{$meeting->id}");
        $memberView->assertOk()->assertInertia(fn ($page) => $page
            ->where('meeting.rsvps', function ($rsvps) use ($awayId) {
                $theirs = collect($rsvps)->firstWhere('board_member_id', $awayId);

                return $theirs['response'] === 'declined'
                    && ! array_key_exists('decline_reason', $theirs)
                    && ! array_key_exists('dietary_notes', $theirs);
            })
            ->where('meeting.attendances', fn ($attendances) => ! array_key_exists(
                'apology_reason',
                collect($attendances)->firstWhere('board_member_id', $awayId)
            ))
            // Their own reply keeps its note.
            ->where('viewerRsvp.response', 'accepted')
            ->where('viewerRsvp.dietary_notes', 'Vegetarian')
        );
        $this->assertStringNotContainsString('Private medical appointment', $memberView->getContent());

        $this->actingAs($admin)->get("/governance/meetings/{$meeting->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('meeting.rsvps', fn ($rsvps) => collect($rsvps)
                    ->firstWhere('board_member_id', $awayId)['decline_reason'] === 'Private medical appointment')
            );
    }

    public function test_reply_confirmation_shows_the_reference_of_the_stored_reply(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createOrdinaryMember();
        $meeting = $this->createMeeting($admin);

        $this->actingAs($member)
            ->post("/governance/meetings/{$meeting->id}/rsvp", ['response' => 'tentative'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Your reply has been recorded.');
        $receipt = session('receipt_id');
        $this->assertMatchesRegularExpression('/^RSVP-\d+-\d+-\d{14}$/', (string) $receipt);

        $this->actingAs($member)->get("/governance/meetings/{$meeting->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('viewerRsvp.response', 'tentative')
                ->where('viewerRsvp.receipt_id', $receipt)
            );
    }

    public function test_changing_a_reply_clears_the_note_that_no_longer_applies(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createOrdinaryMember();
        $meeting = $this->createMeeting($admin);
        $url = "/governance/meetings/{$meeting->id}/rsvp";

        $this->actingAs($member)->post($url, [
            'response' => 'accepted',
            'dietary_requirements' => true,
            'dietary_notes' => 'Gluten free',
        ])->assertRedirect();

        // Sending apologies keeps the reason and drops the dietary needs.
        $this->actingAs($member)->post($url, [
            'response' => 'declined',
            'decline_reason' => 'Overseas that week',
            'dietary_requirements' => true,
            'dietary_notes' => 'Gluten free',
        ])->assertRedirect();
        $this->assertDatabaseHas('meeting_rsvps', [
            'governance_meeting_id' => $meeting->id,
            'response' => 'declined',
            'decline_reason' => 'Overseas that week',
            'dietary_requirements' => 0,
            'dietary_notes' => null,
        ]);

        // Attending again drops the apology reason.
        $this->actingAs($member)->post($url, [
            'response' => 'accepted',
            'decline_reason' => 'Overseas that week',
        ])->assertRedirect();
        $this->assertDatabaseHas('meeting_rsvps', [
            'governance_meeting_id' => $meeting->id,
            'response' => 'accepted',
            'decline_reason' => null,
        ]);
    }

    public function test_minutes_integrity_codes_only_reach_auditors_and_the_people_who_approve_them(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createOrdinaryMember();
        $meeting = $this->createMeeting($admin, ['scheduled_at' => now()->subDay()]);
        app(MeetingMinuteService::class)->storeMinutes(
            $meeting,
            [['heading' => 'Welcome and apologies', 'content' => 'Everyone was present.']],
            $admin,
        );
        $hash = $meeting->fresh()->minutes->content_hash;

        $memberView = $this->actingAs($member)->get("/governance/meetings/{$meeting->id}");
        $memberView->assertOk()->assertInertia(fn ($page) => $page
            ->where('canViewRecordDetails', false)
            ->missing('meeting.minutes.content_hash')
            ->missing('meeting.minutes.drafted_by')
            ->where('meeting.minutes.drafter_name', $admin->name)
            ->where('meeting.minutes.version_history.0.version', 1)
            ->where('meeting.minutes.version_history.0.note', 'First draft started')
            ->missing('meeting.minutes.version_history.0.content_hash')
        );
        $this->assertStringNotContainsString($hash, $memberView->getContent());

        $this->actingAs($admin)->get("/governance/meetings/{$meeting->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canViewRecordDetails', true)
                ->where('meeting.minutes.content_hash', $hash)
                ->has('meeting.minutes.version_history.0.content_hash')
            );
    }

    public function test_a_minutes_edit_that_crossed_someone_elses_save_is_a_conflict(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);
        MeetingMinute::create([
            'governance_meeting_id' => $meeting->id,
            'content_blocks' => [['heading' => 'General business', 'content' => 'Saved by someone else']],
            'status' => 'draft',
            'version_number' => 2,
            'drafted_by' => $admin->id,
            'drafted_at' => now(),
        ]);

        $this->actingAs($admin)->putJson("/governance/meetings/{$meeting->id}/minutes", [
            'content_blocks' => [['heading' => 'General business', 'content' => 'My older edit']],
            'expected_version' => 1,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error', fn (string $message) => str_starts_with($message, 'Someone else saved these minutes while you were editing'));
    }

    public function test_recording_attendance_keeps_the_quorum_result_and_the_register_truthful(): void
    {
        $admin = $this->createAdminUser();
        $first = $this->createBoardMember($this->createUserWithRole('board_member'));
        $second = $this->createBoardMember($this->createUserWithRole('board_member'));

        $held = $this->createMeeting($admin, ['title' => 'Held with quorum', 'scheduled_at' => now()->subDays(2), 'quorum_required' => 50]);
        $short = $this->createMeeting($admin, ['title' => 'Held without quorum', 'scheduled_at' => now()->subDays(3), 'quorum_required' => 100]);
        $this->createMeeting($admin, ['title' => 'Held, attendance not recorded', 'scheduled_at' => now()->subDays(4)]);
        $this->createMeeting($admin, ['title' => 'Coming up', 'scheduled_at' => now()->addDays(4)]);

        $this->actingAs($admin)->post("/governance/meetings/{$held->id}/attendance", [
            'attendance' => [['board_member_id' => $first->id, 'status' => 'present']],
        ])->assertSessionHas('success', 'Attendance saved.');
        $this->assertTrue($held->fresh()->quorum_met);

        $this->actingAs($admin)->post("/governance/meetings/{$short->id}/attendance", [
            'attendance' => [
                ['board_member_id' => $first->id, 'status' => 'present'],
                ['board_member_id' => $second->id, 'status' => 'apology'],
            ],
        ])->assertRedirect();
        $this->assertFalse($short->fresh()->quorum_met);

        $response = $this->actingAs($admin)->get('/governance/meetings');
        $states = [];
        $response->assertOk()->assertInertia(function ($page) use (&$states) {
            $states = collect($page->toArray()['props']['meetings']['data'])->pluck('quorum_state', 'title')->all();
            $page->where('summary.held', 3)
                ->where('summary.held_recorded', 2)
                ->where('summary.held_quorum_met', 1);
        });

        ksort($states);
        $this->assertSame([
            'Coming up' => 'upcoming',
            'Held with quorum' => 'met',
            'Held without quorum' => 'not_met',
            'Held, attendance not recorded' => 'not_recorded',
        ], $states);
    }

    public function test_editing_a_meeting_can_only_keep_it_scheduled_or_cancel_it(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin, ['title' => 'November board meeting']);

        $this->actingAs($admin)
            ->from("/governance/meetings/{$meeting->id}")
            ->put("/governance/meetings/{$meeting->id}", ['status' => 'minutes_signed'])
            ->assertSessionHasErrors(['status' => 'Editing a meeting can only keep it scheduled or cancel it. The other stages follow from the board pack and the minutes.']);
        $this->assertSame('scheduled', $meeting->fresh()->status);

        // A meeting already at a later stage can still have its details saved.
        $staged = $this->createMeeting($admin, ['title' => 'Agenda ready meeting', 'status' => 'agenda_final']);
        $this->actingAs($admin)
            ->put("/governance/meetings/{$staged->id}", ['title' => 'Renamed meeting', 'status' => 'agenda_final'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Meeting details saved.');
        $this->assertSame('Renamed meeting', $staged->fresh()->title);

        $this->actingAs($admin)
            ->put("/governance/meetings/{$meeting->id}", ['status' => 'cancelled'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Meeting cancelled.');
        $this->assertSame('cancelled', $meeting->fresh()->status);
    }

    public function test_the_committee_must_match_the_type_of_meeting(): void
    {
        $admin = $this->createAdminUser();
        $finance = BoardCommittee::create(['name' => 'Finance Committee', 'committee_type' => 'finance', 'is_active' => true]);
        $people = BoardCommittee::create(['name' => 'People Committee', 'committee_type' => 'people', 'is_active' => true]);
        $base = [
            'title' => 'Budget review',
            'scheduled_at' => now()->addDays(10)->toIso8601String(),
            'duration_minutes' => 90,
            'quorum_required' => 50,
        ];

        $this->actingAs($admin)
            ->post('/governance/meetings', [...$base, 'meeting_type' => 'full_board', 'board_committee_id' => $finance->id])
            ->assertSessionHasErrors(['board_committee_id' => "This type of meeting is for the whole board, so it can't belong to a committee. Remove the committee, or choose the committee's own meeting type."]);

        $this->actingAs($admin)
            ->post('/governance/meetings', [...$base, 'meeting_type' => 'finance'])
            ->assertSessionHasErrors(['board_committee_id' => 'Choose which finance committee this meeting is for.']);

        $this->actingAs($admin)
            ->post('/governance/meetings', [...$base, 'meeting_type' => 'finance', 'board_committee_id' => $people->id])
            ->assertSessionHasErrors(['board_committee_id' => "The committee you chose isn't the finance committee. Choose the finance committee, or change the type of meeting."]);

        $this->actingAs($admin)
            ->post('/governance/meetings', [...$base, 'meeting_type' => 'audit_risk'])
            ->assertSessionHasErrors(['board_committee_id' => "There's no audit and risk committee set up yet, so this meeting can't be scheduled for it. Ask an administrator to add the committee, or choose another type of meeting."]);

        $this->assertDatabaseCount('governance_meetings', 0);

        $this->actingAs($admin)
            ->post('/governance/meetings', [...$base, 'meeting_type' => 'finance', 'board_committee_id' => $finance->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Meeting scheduled.');
        $this->assertDatabaseHas('governance_meetings', ['title' => 'Budget review', 'board_committee_id' => $finance->id]);

        // An edit that leaves the type and committee alone isn't blocked by an older record.
        $legacy = $this->createMeeting($admin, ['meeting_type' => 'finance', 'board_committee_id' => null]);
        $this->actingAs($admin)
            ->put("/governance/meetings/{$legacy->id}", ['title' => 'Renamed'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->put("/governance/meetings/{$legacy->id}", ['meeting_type' => 'full_board', 'board_committee_id' => $people->id])
            ->assertSessionHasErrors('board_committee_id');
    }

    public function test_the_register_offers_the_checklist_only_to_people_who_run_the_meeting(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createOrdinaryMember();
        $this->createMeeting($admin);

        $this->actingAs($member)->get('/governance/meetings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('meetings.data.0.can_run', false)
                ->where('meetingTypes.full_board', 'Full board meeting')
            );

        $this->actingAs($admin)->get('/governance/meetings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('meetings.data.0.can_run', true));
    }

    public function test_the_wizard_knows_who_counts_towards_the_quorum(): void
    {
        $admin = $this->createAdminUser();
        $current = $this->createBoardMember($this->createUserWithRole('board_member'));
        $ended = $this->createBoardMember($this->createUserWithRole('board_member'), [
            'term_start' => now()->subYears(3)->toDateString(),
            'term_end' => now()->subDay()->toDateString(),
        ]);
        $finance = BoardCommittee::create(['name' => 'Finance Committee', 'committee_type' => 'finance', 'is_active' => true]);
        CommitteeMembership::create([
            'board_committee_id' => $finance->id,
            'board_member_id' => $current->id,
            'role' => 'member',
            'appointed_at' => now()->subMonth(),
            'is_active' => true,
        ]);

        $this->actingAs($admin)->get('/governance/meetings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('formOptions.board_members', function ($members) use ($current, $ended) {
                    $byId = collect($members)->keyBy('id');

                    return $byId[$current->id]['counts_for_quorum'] === true
                        && $byId[$ended->id]['counts_for_quorum'] === false;
                })
                ->where('formOptions.committees.0.member_ids', [$current->id])
            );
    }

    public function test_the_calendar_uses_plain_meeting_types_and_the_viewers_own_sources(): void
    {
        $member = $this->createOrdinaryMember();

        $this->actingAs($member)->get('/governance/meetings/calendar')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('meetingTypes.0.label', 'All types')
                ->where('meetingTypes.1.label', 'Full board meeting')
                ->where('calendarSources', fn ($sources) => collect($sources)->contains('meetings'))
            );
    }

    public function test_the_readiness_summary_says_what_applies_and_when(): void
    {
        $admin = $this->createAdminUser();
        $committee = BoardCommittee::create(['name' => 'Finance Committee', 'committee_type' => 'finance', 'is_active' => true]);
        $meeting = $this->createMeeting($admin, [
            'meeting_type' => 'finance',
            'board_committee_id' => $committee->id,
            'scheduled_at' => now()->addDays(6),
        ]);

        $this->actingAs($admin)->get("/governance/meetings/{$meeting->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('meetingCockpit.cards', function ($cards) {
                $byKey = collect($cards)->keyBy('key');

                return $byKey['ceo_report']['status'] === 'not_applicable'
                    && $byKey['ceo_report']['value'] === 'Not needed'
                    && $byKey['quorum']['value'] === 'On the day'
                    && str_starts_with($byKey['quorum']['detail'], 'Attendance is recorded at the meeting.');
            }));
    }

    public function test_a_recipient_sees_whether_they_have_read_the_board_pack(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createOrdinaryMember();
        $meeting = $this->createMeeting($admin, ['scheduled_at' => now()->addDays(4)]);
        $pack = $this->createDistributedPack($admin, $meeting, $member->boardMember->id);

        $this->actingAs($member)->get("/governance/meetings/{$meeting->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('meeting.board_pack.id', $pack->id)
                ->where('packReading.sent', true)
                ->where('packReading.is_recipient', true)
                ->where('packReading.read', false)
            );

        $pack->recordRead($member->boardMember->id, $member->id);

        $this->actingAs($member)->get("/governance/meetings/{$meeting->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('packReading.read', true)
                ->where('packReading.version', 1)
                ->where('packReading.read_at', fn ($readAt) => is_string($readAt) && $readAt !== '')
            );
    }

    private function createDistributedPack(User $creator, GovernanceMeeting $meeting, int $recipientId): BoardPack
    {
        $snapshotData = ['widgets' => []];
        $snapshot = DashboardSnapshot::create([
            'snapshot_data' => $snapshotData,
            'period_type' => 'month',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'checksum' => DashboardSnapshot::generateChecksum($snapshotData),
            'captured_at' => now(),
            'captured_by' => $creator->id,
            'data_freshness' => [],
        ]);

        return BoardPack::create([
            'governance_meeting_id' => $meeting->id,
            'dashboard_snapshot_id' => $snapshot->id,
            'document_manifest' => [
                'manifest_sections' => [['id' => 'agenda', 'title' => 'Agenda', 'type' => 'auto', 'included' => true]],
                'content_sections' => ['agenda' => [['title' => 'Agenda item']]],
            ],
            'generated_at' => now(),
            'generated_by' => $creator->id,
            'file_path' => "governance/board-packs/{$meeting->id}/pack.pdf",
            'file_size' => 10,
            'checksum' => hash('sha256', 'board-pack'),
            'watermark_text' => 'CONFIDENTIAL - BOARD ONLY',
            'distributed_at' => now(),
            'distributed_to' => [$recipientId],
            'download_tracking' => [],
            'read_tracking' => [],
            'supplementary_attachments' => [],
        ]);
    }
}
