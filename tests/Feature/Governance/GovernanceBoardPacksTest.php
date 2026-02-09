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
        );

        $downloadResponse = $this->actingAs($admin)->get("/governance/packs/{$pack->id}/download");
        $downloadResponse->assertOk();

        $pack->refresh();
        $this->assertCount(1, $pack->download_tracking ?? []);
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
}
