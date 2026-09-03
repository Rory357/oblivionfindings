<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Jobs\SendBoardPackNotification;
use App\Domain\Governance\Jobs\SendPreReadReminders;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\DashboardSnapshot;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Notifications\BoardPackPublishedNotification;
use App\Domain\Governance\Notifications\PreReadReminderNotification;
use App\Domain\Governance\Services\BoardPackAccessService;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceBoardPacksTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_admin_can_view_pack_and_download(): void
    {
        Storage::fake('local');

        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $meeting = $this->createMeeting($admin);

        $snapshotData = [
            'period' => [
                'type' => 'month',
                'start' => now()->startOfMonth()->toDateString(),
                'end' => now()->toDateString(),
            ],
            'widgets' => [],
        ];

        $snapshot = DashboardSnapshot::create([
            'snapshot_data' => $snapshotData,
            'period_type' => 'month',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'checksum' => DashboardSnapshot::generateChecksum($snapshotData),
            'captured_at' => now(),
            'captured_by' => $admin->id,
            'data_freshness' => [],
        ]);

        $content = 'test-pack';
        $path = 'board-packs/test-pack.pdf';
        Storage::put($path, $content);

        $pack = BoardPack::create([
            'governance_meeting_id' => $meeting->id,
            'dashboard_snapshot_id' => $snapshot->id,
            'document_manifest' => [],
            'generated_at' => now(),
            'generated_by' => $admin->id,
            'file_path' => $path,
            'file_size' => strlen($content),
            'checksum' => hash('sha256', $content),
            'watermark_text' => 'CONFIDENTIAL - BOARD ONLY',
            'distributed_at' => now(),
            'distributed_to' => [$boardMember->id],
            'download_tracking' => [],
            'read_tracking' => [],
        ]);

        $showResponse = $this->actingAs($admin)->get("/governance/packs/{$pack->id}");
        $showResponse->assertOk();
        $showResponse->assertInertia(fn ($page) => $page
            ->component('Governance/Packs/Show')
            ->has('manifestSections')
            ->has('contentSections')
            ->has('distributionStats')
        );

        $downloadResponse = $this->actingAs($admin)->get("/governance/packs/{$pack->id}/download");
        $downloadResponse->assertOk();

        $pack->refresh();
        $this->assertCount(1, $pack->download_tracking ?? []);
    }

    public function test_admin_can_generate_pack_for_meeting_inline(): void
    {
        Storage::fake('local');

        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin, [
            'status' => 'agenda_final',
        ]);

        $response = $this->actingAs($admin)->post("/governance/meetings/{$meeting->id}/packs", [
            'sync' => true,
        ]);

        $response->assertRedirect();

        $pack = BoardPack::query()->where('governance_meeting_id', $meeting->id)->first();

        $this->assertNotNull($pack);
        $this->assertNotNull($pack->dashboard_snapshot_id);
        $this->assertArrayHasKey('manifest_sections', $pack->document_manifest);
        $this->assertArrayHasKey('content_sections', $pack->document_manifest);
    }

    public function test_admin_can_distribute_pack(): void
    {
        Notification::fake();

        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $meeting = $this->createMeeting($admin);

        $snapshot = DashboardSnapshot::create([
            'snapshot_data' => ['widgets' => []],
            'period_type' => 'month',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'checksum' => DashboardSnapshot::generateChecksum(['widgets' => []]),
            'captured_at' => now(),
            'captured_by' => $admin->id,
            'data_freshness' => [],
        ]);

        $pack = BoardPack::create([
            'governance_meeting_id' => $meeting->id,
            'dashboard_snapshot_id' => $snapshot->id,
            'document_manifest' => [],
            'generated_at' => now(),
            'generated_by' => $admin->id,
            'file_path' => 'board-packs/placeholder.pdf',
            'file_size' => 0,
            'checksum' => hash('sha256', 'placeholder'),
            'watermark_text' => 'CONFIDENTIAL - BOARD ONLY',
        ]);

        $response = $this->actingAs($admin)->post("/governance/packs/{$pack->id}/distribute", [
            'board_member_ids' => [$boardMember->id],
        ]);

        $response->assertRedirect();
        $pack->refresh();

        $this->assertNotNull($pack->distributed_at);
        $this->assertEquals([$boardMember->id], $pack->distributed_to);
    }

    public function test_pack_show_normalizes_legacy_manifest_and_real_distribution_stats(): void
    {
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $meeting = $this->createMeeting($admin);

        $snapshot = DashboardSnapshot::create([
            'snapshot_data' => ['widgets' => []],
            'period_type' => 'month',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'checksum' => DashboardSnapshot::generateChecksum(['widgets' => []]),
            'captured_at' => now(),
            'captured_by' => $admin->id,
            'data_freshness' => [],
        ]);

        $pack = BoardPack::create([
            'governance_meeting_id' => $meeting->id,
            'dashboard_snapshot_id' => $snapshot->id,
            'document_manifest' => [
                ['id' => 'cover', 'title' => 'Cover & Meeting Overview', 'type' => 'auto', 'included' => true],
                ['id' => 'agenda', 'title' => 'Agenda', 'type' => 'auto', 'included' => true],
                'content' => [
                    'cover' => ['type' => 'Full Board Meeting', 'date' => now()->toDateString()],
                    'agenda' => [['title' => 'Opening karakia']],
                ],
            ],
            'generated_at' => now(),
            'generated_by' => $admin->id,
            'file_path' => 'board-packs/legacy-pack.pdf',
            'file_size' => 100,
            'checksum' => hash('sha256', 'legacy-pack'),
            'watermark_text' => 'CONFIDENTIAL - BOARD ONLY',
            'distributed_at' => now(),
            'distributed_to' => [$boardMember->id],
            'download_tracking' => [[
                'board_member_id' => $boardMember->id,
                'downloaded_at' => now()->toIso8601String(),
                'ip_address' => '127.0.0.1',
            ]],
            'read_tracking' => [[
                'board_member_id' => $boardMember->id,
                'read_at' => now()->toIso8601String(),
            ]],
        ]);

        $response = $this->actingAs($admin)->get("/governance/packs/{$pack->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Packs/Show')
            ->has('manifestSections', 2)
            ->has('contentSections', 2)
            ->where('manifestSections.0.id', 'cover')
            ->where('contentSections.0.key', 'cover')
            ->where('distributionStats.intended_recipients', 1)
            ->where('distributionStats.read_count', 1)
            ->where('distributionStats.download_count', 1)
            ->where('distributionStats.outstanding_reads', 0)
        );
    }

    public function test_non_recipient_cannot_discover_read_or_download_another_members_pack(): void
    {
        Storage::fake('local');

        $admin = $this->createAdminUser();
        $recipient = $this->createUserWithRole('board_member');
        $recipientMember = $this->createBoardMember($recipient);
        $viewer = $this->createUserWithRole('board_member');
        $viewerMember = $this->createBoardMember($viewer);

        $visibleMeeting = $this->createMeeting($admin, [
            'title' => 'Viewer pack',
            'scheduled_at' => now()->addDays(5),
        ]);
        $visiblePack = $this->createTestPack(
            $admin,
            $visibleMeeting,
            [$viewerMember->id],
        );
        $hiddenMeeting = $this->createMeeting($admin, [
            'title' => 'Committee recipient-only pack',
            'meeting_type' => 'committee',
            'scheduled_at' => now()->addDay(),
            'pack_distributed_at' => now(),
        ]);
        $hiddenPack = $this->createTestPack(
            $admin,
            $hiddenMeeting,
            [$recipientMember->id],
            [[
                'id' => 'hidden-attachment',
                'path' => 'governance/board-packs/hidden/secret.pdf',
                'original_name' => 'secret.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 6,
                'uploaded_at' => now()->toIso8601String(),
                'uploaded_by_id' => $admin->id,
                'uploaded_by_name' => $admin->name,
            ]],
        );
        $draftPack = $this->createTestPack(
            $admin,
            $this->createMeeting($admin, [
                'title' => 'Draft pack',
                'scheduled_at' => now()->addDays(3),
            ]),
        );
        $this->createMeeting($admin, [
            'title' => 'Meeting without pack',
            'scheduled_at' => now()->addDays(4),
        ]);

        $indexResponse = $this->actingAs($viewer)->get('/governance/packs');

        $indexResponse->assertOk();
        $indexResponse->assertInertia(fn ($page) => $page
            ->component('Governance/Packs/Index')
            ->has('packs.data', 1)
            ->where('packs.data.0.id', $visiblePack->id)
            ->where('summary.total', 1)
            ->where('summary.distributed', 1)
            ->where('summary.draft', 0)
            ->where('packs.total', 1)
            ->has('meetings_without_pack', 0)
            ->missing('packs.data.0.document_manifest')
            ->missing('packs.data.0.file_path')
            ->missing('packs.data.0.checksum')
            ->missing('packs.data.0.distributed_to')
            ->missing('packs.data.0.download_tracking')
            ->missing('packs.data.0.read_tracking')
            ->missing('packs.data.0.supplementary_attachments')
        );

        $this->actingAs($viewer)->get("/governance/packs/{$hiddenPack->id}")->assertNotFound();
        $this->actingAs($viewer)->get("/governance/packs/{$hiddenPack->id}/download")->assertNotFound();
        $this->actingAs($viewer)->get("/governance/packs/{$hiddenPack->id}/attachments/hidden-attachment/download")->assertNotFound();
        $this->actingAs($viewer)->post("/governance/packs/{$hiddenPack->id}/read")->assertNotFound();
        $this->actingAs($viewer)->get("/governance/packs/{$draftPack->id}")->assertNotFound();
        $this->actingAs($viewer)->post("/governance/packs/{$draftPack->id}/read")->assertNotFound();

        $meetingResponse = $this->actingAs($viewer)->get("/governance/meetings/{$hiddenMeeting->id}");
        $meetingResponse->assertOk();
        $meetingResponse->assertInertia(fn ($page) => $page
            ->where('meeting.board_pack', null)
            ->missing('meeting.pack_distributed_at')
            ->where('workflowChecklist.items', fn ($items) => collect($items)
                ->whereIn('key', ['pack_generated', 'pack_distributed'])
                ->isEmpty())
            ->where('meetingCockpit.cards', fn ($cards) => ! collect($cards)->contains('key', 'pack_readiness'))
        );

        $this->actingAs($viewer)
            ->get('/governance/meetings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('meetings.data', fn ($meetings) => collect($meetings)
                    ->every(fn (array $meeting) => ! array_key_exists('pack_distributed_at', $meeting)))
            );

        $dashboardResponse = $this->actingAs($viewer)->getJson('/governance/dashboard/data?period=month');
        $dashboardResponse->assertOk();
        $dashboardResponse->assertJsonPath('cockpit.board_pack', null);
        $this->assertFalse(collect($dashboardResponse->json('workflow.actions'))->contains(
            fn (array $action) => ($action['action_url'] ?? null) === "/governance/packs/{$hiddenPack->id}",
        ));
        $this->assertFalse(collect($dashboardResponse->json('cockpit.cards_by_key.meeting_readiness.metrics'))->contains(
            'label',
            'Pack',
        ));
        $this->assertFalse(collect($dashboardResponse->json('cockpit.next_meeting.checklist'))->contains(
            fn (array $item) => in_array($item['key'] ?? null, ['pack_generated', 'pack_distributed'], true),
        ));

        $hiddenPack->refresh();
        $draftPack->refresh();
        $this->assertSame([], $hiddenPack->read_tracking ?? []);
        $this->assertSame([], $hiddenPack->download_tracking ?? []);
        $this->assertSame([], $draftPack->read_tracking ?? []);
    }

    public function test_pack_manager_without_board_membership_can_review_but_cannot_acknowledge_draft(): void
    {
        Storage::fake('local');

        $manager = $this->createAdminUser();
        $meeting = $this->createMeeting($manager, [
            'title' => 'Manager draft',
            'scheduled_at' => now()->addDay(),
        ]);
        $pack = $this->createTestPack(
            $manager,
            $meeting,
            null,
            [[
                'id' => 'manager-attachment',
                'path' => 'governance/board-packs/manager/report.pdf',
                'original_name' => 'report.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 6,
                'uploaded_at' => now()->toIso8601String(),
                'uploaded_by_id' => $manager->id,
                'uploaded_by_name' => $manager->name,
            ]],
        );
        $this->createMeeting($manager, [
            'title' => 'Available for generation',
            'scheduled_at' => now()->addDays(2),
        ]);

        $indexResponse = $this->actingAs($manager)->get('/governance/packs');
        $indexResponse->assertOk();
        $indexResponse->assertInertia(fn ($page) => $page
            ->has('packs.data', 1)
            ->where('packs.data.0.id', $pack->id)
            ->where('summary.total', 1)
            ->where('summary.distributed', 0)
            ->where('summary.draft', 1)
            ->has('meetings_without_pack', 1)
        );

        $showResponse = $this->actingAs($manager)->get("/governance/packs/{$pack->id}");
        $showResponse->assertOk();
        $showResponse->assertInertia(fn ($page) => $page
            ->where('pack.id', $pack->id)
            ->where('can_mark_read', false)
            ->missing('pack.document_manifest')
            ->missing('pack.file_path')
            ->missing('pack.checksum')
            ->missing('pack.distributed_to')
            ->missing('pack.download_tracking')
            ->missing('pack.read_tracking')
            ->missing('pack.supplementary_attachments')
        );

        $this->actingAs($manager)->get("/governance/packs/{$pack->id}/download")->assertOk();
        $this->actingAs($manager)->get("/governance/packs/{$pack->id}/attachments/manager-attachment/download")->assertOk();
        $this->actingAs($manager)->post("/governance/packs/{$pack->id}/read")->assertNotFound();

        $meetingResponse = $this->actingAs($manager)->get("/governance/meetings/{$meeting->id}");
        $meetingResponse->assertOk();
        $meetingResponse->assertInertia(fn ($page) => $page
            ->where('meeting.board_pack.id', $pack->id)
            ->where('meeting.board_pack.distributed_at', null)
            ->missing('meeting.board_pack.document_manifest')
            ->missing('meeting.board_pack.file_path')
            ->missing('meeting.board_pack.distributed_to')
            ->missing('meeting.board_pack.read_tracking')
            ->where('workflowChecklist.items', fn ($items) => collect($items)->contains('key', 'pack_generated'))
            ->where('meetingCockpit.cards', fn ($cards) => collect($cards)->contains('key', 'pack_readiness'))
        );

        $pack->refresh();
        $this->assertSame([], $pack->read_tracking ?? []);
        $this->assertSame([], $pack->download_tracking ?? []);
    }

    public function test_distributed_recipient_can_view_download_and_acknowledge_once(): void
    {
        Storage::fake('local');

        $admin = $this->createAdminUser();
        $viewer = $this->createUserWithRole('board_member');
        $viewerMember = $this->createBoardMember($viewer);
        $pack = $this->createTestPack(
            $admin,
            $this->createMeeting($admin, ['title' => 'Recipient pack']),
            [$viewerMember->id],
            [[
                'id' => 'recipient-attachment',
                'path' => 'governance/board-packs/recipient/report.pdf',
                'original_name' => 'report.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 6,
                'uploaded_at' => now()->toIso8601String(),
                'uploaded_by_id' => $admin->id,
                'uploaded_by_name' => $admin->name,
            ]],
        );

        $showResponse = $this->actingAs($viewer)->get("/governance/packs/{$pack->id}");
        $showResponse->assertOk();
        $showResponse->assertInertia(fn ($page) => $page
            ->where('pack.id', $pack->id)
            ->where('can_mark_read', true)
            ->has('manifestSections', 1)
            ->has('supplementaryAttachments', 1)
            ->where('supplementaryAttachments.0.id', 'recipient-attachment')
            ->where('supplementaryAttachments.0.original_name', 'report.pdf')
            ->where('supplementaryAttachments.0.download_url', "/governance/packs/{$pack->id}/attachments/recipient-attachment/download")
            ->missing('supplementaryAttachments.0.path')
            ->missing('supplementaryAttachments.0.uploaded_by_id')
            ->missing('pack.file_path')
            ->missing('pack.distributed_to')
            ->missing('pack.download_tracking')
            ->missing('pack.read_tracking')
        );

        $this->actingAs($viewer)->post("/governance/packs/{$pack->id}/read")->assertOk();
        $this->actingAs($viewer)->post("/governance/packs/{$pack->id}/read")->assertOk();
        $this->actingAs($viewer)->get("/governance/packs/{$pack->id}/download")->assertOk();
        $this->actingAs($viewer)->get("/governance/packs/{$pack->id}/attachments/recipient-attachment/download")->assertOk();

        $pack->refresh();
        $this->assertCount(1, $pack->read_tracking ?? []);
        $this->assertCount(1, $pack->download_tracking ?? []);
        $this->assertSame($viewerMember->id, $pack->read_tracking[0]['board_member_id']);
        $this->assertSame($viewerMember->id, $pack->download_tracking[0]['board_member_id']);
    }

    public function test_pack_manager_who_is_not_a_recipient_cannot_acknowledge_distributed_pack(): void
    {
        Storage::fake('local');

        $manager = $this->createAdminUser();
        $this->createBoardMember($manager);
        $recipient = $this->createUserWithRole('board_member');
        $recipientMember = $this->createBoardMember($recipient);
        $pack = $this->createTestPack(
            $manager,
            $this->createMeeting($manager, ['title' => 'Recipient acknowledgement boundary']),
            [$recipientMember->id],
        );

        $showResponse = $this->actingAs($manager)->get("/governance/packs/{$pack->id}");
        $showResponse->assertOk();
        $showResponse->assertInertia(fn ($page) => $page
            ->where('pack.id', $pack->id)
            ->where('can_mark_read', false)
        );

        $this->actingAs($manager)->get("/governance/packs/{$pack->id}/download")->assertOk();
        $this->actingAs($manager)->post("/governance/packs/{$pack->id}/read")->assertNotFound();

        $pack->refresh();
        $this->assertSame([], $pack->read_tracking ?? []);
        $this->assertSame([], $pack->download_tracking ?? []);
    }

    public function test_view_permission_without_canonical_board_member_has_no_pack_visibility(): void
    {
        Storage::fake('local');

        $admin = $this->createAdminUser();
        $recipient = $this->createUserWithRole('board_member');
        $recipientMember = $this->createBoardMember($recipient);
        $viewer = $this->createUserWithRole('board_member');
        $pack = $this->createTestPack(
            $admin,
            $this->createMeeting($admin),
            [$recipientMember->id],
        );

        $indexResponse = $this->actingAs($viewer)->get('/governance/packs');
        $indexResponse->assertOk();
        $indexResponse->assertInertia(fn ($page) => $page
            ->has('packs.data', 0)
            ->where('summary.total', 0)
            ->where('summary.distributed', 0)
            ->where('summary.draft', 0)
        );

        $this->actingAs($viewer)->get("/governance/packs/{$pack->id}")->assertNotFound();
        $this->actingAs($viewer)->get("/governance/packs/{$pack->id}/download")->assertNotFound();
        $this->actingAs($viewer)->post("/governance/packs/{$pack->id}/read")->assertNotFound();
    }

    public function test_distribution_and_visibility_require_current_active_board_terms(): void
    {
        Notification::fake();
        Storage::fake('local');

        $admin = $this->createAdminUser();
        $activeUser = $this->createUserWithRole('board_member');
        $activeMember = $this->createBoardMember($activeUser);
        $futureUser = $this->createUserWithRole('board_member');
        $futureMember = $this->createBoardMember($futureUser, [
            'term_start' => now()->addMonth()->toDateString(),
            'term_end' => now()->addYear()->toDateString(),
        ]);
        $inactiveUser = $this->createUserWithRole('board_member');
        $inactiveMember = $this->createBoardMember($inactiveUser, ['is_active' => false]);
        $deletedUser = $this->createUserWithRole('board_member');
        $deletedMember = $this->createBoardMember($deletedUser);
        $deletedMember->delete();

        $draftPack = $this->createTestPack(
            $admin,
            $this->createMeeting($admin, ['title' => 'Canonical recipient selection']),
        );

        $invalidDistribution = $this->actingAs($admin)->post("/governance/packs/{$draftPack->id}/distribute", [
            'board_member_ids' => [$futureMember->id, $inactiveMember->id, $deletedMember->id],
        ]);
        $invalidDistribution->assertSessionHasErrors([
            'board_member_ids.0',
            'board_member_ids.1',
            'board_member_ids.2',
        ]);
        $this->assertNull($draftPack->fresh()->distributed_at);

        $this->actingAs($admin)
            ->post("/governance/packs/{$draftPack->id}/distribute")
            ->assertRedirect();
        $draftPack->refresh();
        $this->assertSame([$activeMember->id], $draftPack->distributed_to);

        $legacyFuturePack = $this->createTestPack(
            $admin,
            $this->createMeeting($admin, ['title' => 'Future-term legacy snapshot']),
            [$futureMember->id],
        );

        $this->actingAs($futureUser)->get("/governance/packs/{$legacyFuturePack->id}")->assertNotFound();
        $this->actingAs($futureUser)->get("/governance/packs/{$legacyFuturePack->id}/download")->assertNotFound();
        $this->actingAs($futureUser)->post("/governance/packs/{$legacyFuturePack->id}/read")->assertNotFound();
    }

    public function test_meeting_payload_conceals_pack_when_pack_view_permission_is_denied(): void
    {
        Storage::fake('local');

        $admin = $this->createAdminUser();
        $viewer = $this->createUserWithRole('board_member');
        $viewerMember = $this->createBoardMember($viewer);
        $meeting = $this->createMeeting($admin, ['title' => 'Permission-denied pack']);
        $pack = $this->createTestPack($admin, $meeting, [$viewerMember->id]);
        $packsView = Permission::query()->where('key', 'governance.packs.view')->firstOrFail();
        $viewer->permissionOverrides()->syncWithoutDetaching([
            $packsView->id => ['allowed' => false],
        ]);

        $this->actingAs($viewer)->get("/governance/packs/{$pack->id}")->assertForbidden();

        $meetingResponse = $this->actingAs($viewer)->get("/governance/meetings/{$meeting->id}");
        $meetingResponse->assertOk();
        $meetingResponse->assertInertia(fn ($page) => $page
            ->where('meeting.board_pack', null)
            ->where('workflowChecklist.items', fn ($items) => collect($items)
                ->whereIn('key', ['pack_generated', 'pack_distributed'])
                ->isEmpty())
            ->where('meetingCockpit.cards', fn ($cards) => ! collect($cards)->contains('key', 'pack_readiness'))
        );
    }

    public function test_pack_notifications_are_revalidated_against_current_recipients(): void
    {
        Notification::fake();
        Storage::fake('local');

        $admin = $this->createAdminUser();
        $recipient = $this->createUserWithRole('board_member');
        $recipientMember = $this->createBoardMember($recipient);
        $nonRecipient = $this->createUserWithRole('board_member');
        $nonRecipientMember = $this->createBoardMember($nonRecipient);
        $meeting = $this->createMeeting($admin, [
            'title' => 'Reminder recipient boundary',
            'scheduled_at' => now()->addDays(4),
        ]);
        $pack = $this->createTestPack($admin, $meeting, [$recipientMember->id]);

        app()->call([new SendBoardPackNotification($pack, $recipientMember), 'handle']);
        app()->call([new SendBoardPackNotification($pack, $nonRecipientMember), 'handle']);
        SendPreReadReminders::dispatchSync();

        Notification::assertSentTo($recipient, BoardPackPublishedNotification::class);
        Notification::assertSentTo($recipient, PreReadReminderNotification::class);
        Notification::assertNotSentTo($nonRecipient, BoardPackPublishedNotification::class);
        Notification::assertNotSentTo($nonRecipient, PreReadReminderNotification::class);
        $this->assertFalse(is_subclass_of(PreReadReminderNotification::class, ShouldQueue::class));

        Notification::fake();
        $recipientMember->update(['is_active' => false]);
        app()->call([new SendBoardPackNotification($pack, $recipientMember), 'handle']);
        SendPreReadReminders::dispatchSync();
        Notification::assertNothingSent();
    }

    public function test_revoked_pack_notifications_are_concealed_from_every_shared_inbox_surface(): void
    {
        Storage::fake('local');

        $manager = $this->createAdminUser();
        $recipient = $this->createUserWithRole('board_member');
        $recipientMember = $this->createBoardMember($recipient);
        $meeting = $this->createMeeting($manager, [
            'title' => 'Revoked notification boundary',
            'scheduled_at' => now()->addDays(4),
        ]);
        $pack = $this->createTestPack($manager, $meeting, [$recipientMember->id]);
        $packNotificationId = (string) Str::uuid();
        $reminderNotificationId = (string) Str::uuid();
        $unrelatedNotificationId = (string) Str::uuid();
        $managerNotificationId = (string) Str::uuid();

        DB::table('notifications')->insert([
            $this->databaseNotificationRow(
                $recipient,
                $packNotificationId,
                BoardPackPublishedNotification::class,
                [
                    'type' => 'board_pack_published',
                    'pack_id' => $pack->id,
                    'meeting_id' => $meeting->id,
                ],
            ),
            $this->databaseNotificationRow(
                $recipient,
                $reminderNotificationId,
                PreReadReminderNotification::class,
                [
                    'type' => 'pre_read_reminder',
                    'meeting_id' => $meeting->id,
                    'meeting_title' => $meeting->title,
                    'scheduled_at' => $meeting->scheduled_at->toIso8601String(),
                ],
            ),
            $this->databaseNotificationRow(
                $recipient,
                $unrelatedNotificationId,
                'App\\Notifications\\ShiftTaskDueNotification',
                ['title' => 'Visible control notification'],
            ),
            $this->databaseNotificationRow(
                $manager,
                $managerNotificationId,
                BoardPackPublishedNotification::class,
                [
                    'type' => 'board_pack_published',
                    'pack_id' => $pack->id,
                    'meeting_id' => $meeting->id,
                ],
            ),
        ]);

        $this->assertCount(
            3,
            app(BoardPackAccessService::class)
                ->visibleNotificationQuery($recipient)
                ->get(),
        );

        $pack->update(['distributed_to' => []]);

        $visibleNotifications = app(BoardPackAccessService::class)
            ->visibleNotificationQuery($recipient)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
        $this->assertSame([$unrelatedNotificationId], $visibleNotifications);
        $this->assertSame(
            [$managerNotificationId],
            app(BoardPackAccessService::class)
                ->visibleNotificationQuery($manager)
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all(),
        );

        $notificationCentre = $this->actingAs($recipient)->get('/notifications');
        $notificationCentre->assertOk();
        $notificationCentre->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.id', $unrelatedNotificationId)
            ->where('unread_count', 1)
        );

        $portalNotifications = $this->actingAs($recipient)->get('/portal/notifications');
        $portalNotifications->assertOk();
        $portalNotifications->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.id', $unrelatedNotificationId)
            ->where('unreadCount', 1)
        );

        $myDay = $this->actingAs($recipient)->get('/my-day');
        $myDay->assertOk();
        $myDay->assertInertia(fn ($page) => $page
            ->where('stats.notifications_unread', 1)
            ->has('notifications', 1)
            ->where('notifications.0.id', $unrelatedNotificationId)
        );

        $headerInbox = $this->actingAs($recipient)->get(
            '/governance/packs',
            $this->inertiaPartialHeaders('Governance/Packs/Index', 'inbox'),
        );
        $headerInbox
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('props.inbox.notifications.unread_count', 1)
            ->assertJsonCount(1, 'props.inbox.notifications.items')
            ->assertJsonPath('props.inbox.notifications.items.0.id', $unrelatedNotificationId);

        $this->actingAs($recipient)
            ->post("/portal/notifications/{$packNotificationId}/read")
            ->assertNotFound();
        $this->actingAs($recipient)
            ->post("/inbox/notifications/{$reminderNotificationId}/read")
            ->assertNotFound();
        $this->actingAs($recipient)
            ->post("/inbox/notifications/{$packNotificationId}/acknowledge")
            ->assertNotFound();

        $this->actingAs($recipient)
            ->post('/inbox/notifications/read-all')
            ->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->where('id', $unrelatedNotificationId)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $packNotificationId)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $reminderNotificationId)->value('read_at'));

        DB::table('notifications')->where('id', $unrelatedNotificationId)->update(['read_at' => null]);
        $this->actingAs($recipient)
            ->post('/portal/notifications/read-all')
            ->assertRedirect();
        $this->assertNotNull(DB::table('notifications')->where('id', $unrelatedNotificationId)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $packNotificationId)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $reminderNotificationId)->value('read_at'));
    }

    public function test_board_pack_audit_activity_is_manager_only(): void
    {
        Storage::fake('local');

        $manager = $this->createAdminUser();
        $recipient = $this->createUserWithRole('board_member');
        $recipientMember = $this->createBoardMember($recipient);
        $viewer = $this->createUserWithRole('board_member');
        $this->createBoardMember($viewer);
        $pack = $this->createTestPack(
            $manager,
            $this->createMeeting($manager, ['title' => 'Audit event boundary']),
            [$recipientMember->id],
        );

        DB::table('governance_audit_log')->insert([
            [
                'user_id' => $recipient->id,
                'action' => 'board_pack.downloaded',
                'resource_type' => 'BoardPack',
                'resource_id' => $pack->id,
                'metadata' => json_encode(['board_member_id' => $recipientMember->id, 'private' => 'concealed']),
                'ip_address' => '203.0.113.77',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $manager->id,
                'action' => 'resolution.finalized',
                'resource_type' => 'Resolution',
                'resource_id' => 991001,
                'metadata' => null,
                'ip_address' => '203.0.113.10',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $viewerResponse = $this->actingAs($viewer)->get('/governance/audit-log');
        $viewerResponse->assertOk();
        $viewerResponse->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.entity_type', 'Resolution')
            ->where('entityTypes', fn ($types) => ! collect($types)->contains('BoardPack'))
            ->where('actionTypes', fn ($types) => ! collect($types)->contains('board_pack.downloaded'))
        );

        $viewerExport = $this->actingAs($viewer)->get('/governance/audit-log/export');
        $viewerExport->assertOk();
        $this->assertStringNotContainsString('BoardPack', $viewerExport->streamedContent());
        $this->assertStringContainsString('Resolution', $viewerExport->streamedContent());

        $managerResponse = $this->actingAs($manager)->get('/governance/audit-log');
        $managerResponse->assertOk();
        $managerResponse->assertInertia(fn ($page) => $page
            ->has('entries.data', 2)
            ->where('entries.data', fn ($entries) => collect($entries)->contains(
                fn (array $entry) => ($entry['entity_type'] ?? null) === 'BoardPack'
                    && ($entry['entity_id'] ?? null) === $pack->id
                    && ($entry['metadata']['private'] ?? null) === 'concealed',
            ))
        );
    }

    public function test_global_audit_and_report_readers_conceal_board_pack_records_from_non_managers(): void
    {
        Storage::fake('local');

        $manager = $this->createAdminUser();
        $viewer = $this->createUserWithRole('board_member');
        $this->createBoardMember($viewer);
        $packOnlyActor = $this->createUserWithRole('board_member');
        $permissionIds = Permission::query()
            ->whereIn('key', ['audit.viewAny', 'reports.viewAny', 'compliance.view'])
            ->pluck('id');
        $viewer->permissionOverrides()->syncWithoutDetaching(
            $permissionIds->mapWithKeys(fn ($id) => [(int) $id => ['allowed' => true]])->all(),
        );

        $this->actingAs($manager);
        $pack = $this->createTestPack(
            $manager,
            $this->createMeeting($manager, ['title' => 'Global audit boundary']),
        );
        $packAudit = AuditLog::query()
            ->where('auditable_type', BoardPack::class)
            ->where('auditable_id', $pack->id)
            ->latest('id')
            ->firstOrFail();

        AuditLog::query()->where('id', '!=', $packAudit->id)->delete();
        $packAudit->forceFill(['user_id' => $packOnlyActor->id])->saveQuietly();

        $this->assertNull($packAudit->ip_address);
        $this->assertNotContains('document_manifest', $packAudit->meta['fields'] ?? []);
        $this->assertArrayNotHasKey('document_manifest', $packAudit->meta['after'] ?? []);
        $this->assertArrayNotHasKey('file_path', $packAudit->meta['after'] ?? []);

        $controlAudit = AuditLog::create([
            'user_id' => $viewer->id,
            'action' => 'resolution.update',
            'auditable_type' => 'Resolution',
            'auditable_id' => 991002,
            'meta' => ['fields' => ['status']],
            'ip_address' => '203.0.113.12',
        ]);

        $globalAuditResponse = $this->actingAs($viewer)->get('/audit-logs');
        $globalAuditResponse->assertOk();
        $globalAuditResponse->assertInertia(fn ($page) => $page
            ->where('logs.data', fn ($entries) => ! collect($entries)->contains(
                fn (array $entry) => ($entry['subject_type'] ?? null) === 'Board Pack'
                    || (($entry['subject_id'] ?? null) === $pack->id
                        && str_contains(strtolower((string) ($entry['action'] ?? '')), 'boardpack')),
            ))
            ->where('filter_options.users', fn ($users) => collect($users)
                ->contains('id', $viewer->id)
                && ! collect($users)->contains('id', $packOnlyActor->id))
        );

        $reportsResponse = $this->actingAs($viewer)->get('/reports');
        $reportsResponse->assertOk();
        $this->assertSame(1, $reportsResponse->inertiaProps('kpis.auditEvents7d'));
        $auditModule = collect($reportsResponse->inertiaProps('modules'))->firstWhere('key', 'audit_logs');
        $this->assertSame(1, data_get($auditModule, 'summary.total_records'));
        $this->assertSame(
            $controlAudit->created_at->toDateTimeString(),
            data_get($auditModule, 'summary.last_activity'),
        );

        $complianceResponse = $this->actingAs($viewer)->get('/compliance');
        $complianceResponse->assertOk();
        $auditKpi = collect($complianceResponse->inertiaProps('kpis'))->firstWhere('key', 'audit');
        $this->assertSame(1, data_get($auditKpi, 'value'));
        $this->assertSame(1, array_sum(data_get($auditKpi, 'spark', [])));

        $moduleReportResponse = $this->actingAs($viewer)->get('/reports/modules/audit_logs');
        $moduleReportResponse->assertOk();
        $moduleReportResponse->assertInertia(fn ($page) => $page
            ->where('rows.data', fn ($rows) => ! collect($rows)->contains(
                fn (array $row) => ($row['auditable_type'] ?? null) === BoardPack::class
                    || ($row['auditable_type'] ?? null) === 'BoardPack',
            ))
        );

        $combinedReportResponse = $this->actingAs($viewer)->get('/reports/combined/compliance-risk');
        $combinedReportResponse->assertOk();
        $combinedReportResponse->assertInertia(fn ($page) => $page
            ->where('sections', fn ($sections) => ! str_contains(
                strtolower(json_encode($sections)),
                'boardpack',
            ))
        );

        $managerAuditResponse = $this->actingAs($manager)->get('/audit-logs');
        $managerAuditResponse->assertOk();
        $managerAuditResponse->assertInertia(fn ($page) => $page
            ->where('logs.data', fn ($entries) => collect($entries)->contains(
                fn (array $entry) => ($entry['subject_type'] ?? null) === 'Board Pack'
                    && ($entry['subject_id'] ?? null) === $pack->id,
            ))
            ->where('filter_options.users', fn ($users) => collect($users)->contains('id', $packOnlyActor->id))
        );
    }

    public function test_missing_pack_file_does_not_create_download_tracking(): void
    {
        Storage::fake('local');

        $admin = $this->createAdminUser();
        $viewer = $this->createUserWithRole('board_member');
        $viewerMember = $this->createBoardMember($viewer);
        $pack = $this->createTestPack(
            $admin,
            $this->createMeeting($admin),
            [$viewerMember->id],
        );
        Storage::disk('local')->delete($pack->file_path);

        $this->actingAs($viewer)->get("/governance/packs/{$pack->id}/download")->assertNotFound();

        $pack->refresh();
        $this->assertSame([], $pack->download_tracking ?? []);
    }

    public function test_pack_with_soft_deleted_meeting_is_concealed_instead_of_crashing_show(): void
    {
        Storage::fake('local');

        $manager = $this->createAdminUser();
        $recipient = $this->createUserWithRole('board_member');
        $recipientMember = $this->createBoardMember($recipient);
        $meeting = $this->createMeeting($manager, ['title' => 'Deleted meeting']);
        $pack = $this->createTestPack($manager, $meeting, [$recipientMember->id]);
        $meeting->delete();

        $indexResponse = $this->actingAs($manager)->get('/governance/packs');
        $indexResponse->assertOk();
        $indexResponse->assertInertia(fn ($page) => $page
            ->has('packs.data', 0)
            ->where('summary.total', 0)
            ->where('summary.distributed', 0)
            ->where('summary.draft', 0)
        );

        $this->actingAs($manager)->get("/governance/packs/{$pack->id}")->assertNotFound();
        $this->actingAs($manager)->get("/governance/packs/{$pack->id}/download")->assertNotFound();
        $this->actingAs($recipient)->post("/governance/packs/{$pack->id}/read")->assertNotFound();
    }

    /**
     * @param  array<int, int>|null  $recipientIds
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private function createTestPack(
        User $creator,
        GovernanceMeeting $meeting,
        ?array $recipientIds = null,
        array $attachments = [],
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

        $filePath = "governance/board-packs/{$meeting->id}/pack.pdf";
        Storage::disk('local')->put($filePath, 'board-pack');
        foreach ($attachments as $attachment) {
            Storage::disk('local')->put($attachment['path'], 'secret');
        }

        return BoardPack::create([
            'governance_meeting_id' => $meeting->id,
            'dashboard_snapshot_id' => $snapshot->id,
            'document_manifest' => [
                'manifest_sections' => [['id' => 'agenda', 'title' => 'Agenda', 'type' => 'auto', 'included' => true]],
                'content_sections' => ['agenda' => [['title' => 'Private agenda item']]],
            ],
            'generated_at' => now(),
            'generated_by' => $creator->id,
            'file_path' => $filePath,
            'file_size' => 10,
            'checksum' => hash('sha256', 'board-pack'),
            'watermark_text' => 'CONFIDENTIAL - BOARD ONLY',
            'distributed_at' => $recipientIds === null ? null : now(),
            'distributed_to' => $recipientIds,
            'download_tracking' => [],
            'read_tracking' => [],
            'supplementary_attachments' => $attachments,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function databaseNotificationRow(User $user, string $id, string $type, array $data): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'read_at' => null,
            'acknowledged_at' => null,
            'escalation_count' => 0,
            'last_escalated_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
