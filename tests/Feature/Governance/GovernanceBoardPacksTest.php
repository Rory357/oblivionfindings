<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\DashboardSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceBoardPacksTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

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
}
