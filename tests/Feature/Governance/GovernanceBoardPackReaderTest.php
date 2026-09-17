<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\CeoBoardReport;
use App\Domain\Governance\Models\DashboardSnapshot;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * The board pack page is a place to read the pack: members get links to the
 * papers they can open, never management statistics, and are only told about
 * a newer version once it has actually been sent to them.
 */
class GovernanceBoardPackReaderTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
        Storage::fake('local');
        Notification::fake();
    }

    public function test_members_are_only_told_about_a_newer_version_once_it_is_sent_to_them(): void
    {
        $admin = $this->createAdminUser();
        $memberUser = $this->createUserWithRole('board_member');
        $member = $this->createBoardMember($memberUser);
        $meeting = $this->createMeeting($admin, ['title' => 'September board meeting']);

        $v1 = $this->pack($admin, $meeting, 1, [$member->id]);
        $v2 = $this->pack($admin, $meeting, 2, null, supersedes: $v1);
        $v1->update(['is_current' => false]);

        // Version 2 is a draft: the member still reads version 1, with no
        // notice pointing at a page they can't open.
        $this->actingAs($memberUser)
            ->get("/governance/packs/{$v1->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Packs/Show')
                ->where('newer_version', null)
                ->where('all_revisions', fn ($versions) => collect($versions)->pluck('id')->all() === [$v1->id])
                ->where('distributionStats', null)
                ->where('read_count', null)
                ->where('download_count', null)
                ->where('can_manage', false));

        $this->actingAs($memberUser)->get("/governance/packs/{$v2->id}")->assertNotFound();

        $this->actingAs($memberUser)
            ->get('/governance/packs')
            ->assertInertia(fn ($page) => $page
                ->where('packs.data.0.id', $v1->id)
                ->where('packs.data.0.is_current', true)
                ->where('packs.data.0.read_count', null));

        // The manager sees the draft and the reading statistics.
        $this->actingAs($admin)
            ->get("/governance/packs/{$v1->id}")
            ->assertInertia(fn ($page) => $page
                ->where('newer_version.id', $v2->id)
                ->where('newer_version.is_distributed', false)
                ->has('distributionStats'));

        // Once version 2 is sent to the member, their old copy points to it.
        $v2->update(['distributed_at' => now(), 'distributed_to' => [$member->id]]);

        $this->actingAs($memberUser)
            ->get("/governance/packs/{$v1->id}")
            ->assertInertia(fn ($page) => $page
                ->where('newer_version.id', $v2->id)
                ->where('newer_version.revision_number', 2));
    }

    public function test_the_reading_list_links_only_papers_the_viewer_can_open(): void
    {
        $admin = $this->createAdminUser();
        $memberUser = $this->createUserWithRole('board_member');
        $member = $this->createBoardMember($memberUser);
        $meeting = $this->createMeeting($admin, ['title' => 'October board meeting']);
        $paper = $this->createResolution($admin, [
            'title' => 'Approve the 2026/27 budget',
            'governance_meeting_id' => $meeting->id,
        ]);
        $report = CeoBoardReport::create([
            'governance_meeting_id' => $meeting->id,
            'submitted_by' => $admin->id,
            'status' => CeoBoardReport::STATUS_SUBMITTED,
            'executive_summary' => 'A steady month.',
        ]);

        $pack = $this->pack($admin, $meeting, 1, [$member->id], content: [
            'agenda' => [['title' => 'Welcome']],
            'ceo_report' => ['status' => 'submitted'],
            'resolutions' => ['items' => [$paper->fresh()->toArray()]],
        ]);

        $response = $this->actingAs($memberUser)->get("/governance/packs/{$pack->id}");

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('meeting_url', "/governance/meetings/{$meeting->id}")
            ->where('readingSections', function ($sections) use ($paper, $report) {
                $sections = collect($sections)->keyBy('key');

                return $sections['agenda']['summary'] === '1 agenda item'
                    && $sections['ceo_report']['href'] === "/governance/ceo-reports/{$report->id}"
                    && $sections['resolutions']['title'] === 'Resolutions'
                    && $sections['resolutions']['items'][0]['title'] === 'Approve the 2026/27 budget'
                    && $sections['resolutions']['items'][0]['href'] === "/governance/resolutions/{$paper->id}";
            })
            ->where('manifestSections', fn ($sections) => collect($sections)->every(
                fn ($section) => ! str_contains((string) $section['title'], 'Paper:'),
            )));
    }

    public function test_the_register_shows_what_the_member_has_still_to_read(): void
    {
        $admin = $this->createAdminUser();
        $memberUser = $this->createUserWithRole('board_member');
        $member = $this->createBoardMember($memberUser);

        $next = $this->pack($admin, $this->createMeeting($admin, [
            'title' => 'Next meeting',
            'scheduled_at' => now()->addDays(5),
        ]), 1, [$member->id]);
        $older = $this->pack($admin, $this->createMeeting($admin, [
            'title' => 'Last month',
            'scheduled_at' => now()->subMonth(),
        ]), 1, [$member->id]);
        $older->recordRead($member->id, $memberUser->id);

        $this->actingAs($memberUser)
            ->get('/governance/packs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('is_recipient', true)
                ->where('summary.unread', 1)
                ->where('next_meeting_pack.id', $next->id)
                ->where('next_meeting_pack.read', false)
                ->where('packs.data', fn ($rows) => collect($rows)->firstWhere('id', $older->id)['my_read_at'] !== null
                    && collect($rows)->firstWhere('id', $next->id)['my_read_at'] === null));

        $this->actingAs($memberUser)
            ->get('/governance/packs?status=unread')
            ->assertInertia(fn ($page) => $page
                ->has('packs.data', 1)
                ->where('packs.data.0.id', $next->id));
    }

    public function test_sending_a_pack_reports_how_many_members_it_went_to(): void
    {
        $admin = $this->createAdminUser();
        $this->createBoardMember($this->createUserWithRole('board_member'));
        $this->createBoardMember($this->createUserWithRole('board_member'));
        $meeting = $this->createMeeting($admin);
        $pack = $this->pack($admin, $meeting, 1, null);

        $this->actingAs($admin)
            ->get("/governance/packs/{$pack->id}")
            ->assertInertia(fn ($page) => $page->where('distribution_recipient_count', 2));

        $this->actingAs($admin)
            ->from("/governance/packs/{$pack->id}")
            ->post("/governance/packs/{$pack->id}/distribute")
            ->assertRedirect("/governance/packs/{$pack->id}")
            ->assertSessionHas('success', 'Board pack sent to 2 board members.');
    }

    /**
     * @param  array<int, int>|null  $recipients
     * @param  array<string, mixed>|null  $content
     */
    private function pack(
        User $creator,
        GovernanceMeeting $meeting,
        int $revision,
        ?array $recipients,
        ?BoardPack $supersedes = null,
        ?array $content = null,
    ): BoardPack {
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

        $content ??= ['agenda' => [['title' => 'Welcome']]];
        $path = "governance/board-packs/{$meeting->id}/pack-r{$revision}.pdf";
        Storage::disk('local')->put($path, 'pack');

        return BoardPack::create([
            'governance_meeting_id' => $meeting->id,
            'dashboard_snapshot_id' => $snapshot->id,
            'revision_number' => $revision,
            'supersedes_id' => $supersedes?->id,
            'build_status' => 'published',
            'is_current' => true,
            'document_manifest' => [
                'manifest_sections' => collect($content['resolutions']['items'] ?? [])
                    ->map(fn (array $paper) => ['id' => "res_{$paper['id']}", 'title' => "Paper: {$paper['title']}", 'type' => 'paper', 'included' => true])
                    ->values()
                    ->all(),
                'content_sections' => $content,
            ],
            'generated_at' => now(),
            'generated_by' => $creator->id,
            'file_path' => $path,
            'file_size' => 4,
            'checksum' => hash('sha256', 'pack'),
            'watermark_text' => 'CONFIDENTIAL - BOARD ONLY',
            'distributed_at' => $recipients === null ? null : now(),
            'distributed_to' => $recipients,
            'download_tracking' => [],
            'read_tracking' => [],
        ]);
    }
}
