<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\DashboardSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * Generating a board pack moves a scheduled meeting to `pack_draft`, and the
 * meeting form no longer sets agenda stages by hand. Neither may lock the
 * meeting or block a new pack version before the meeting is held.
 */
class GovernanceMeetingStageGatesTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_a_meeting_with_a_draft_pack_stays_editable(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin, ['title' => 'Board meeting', 'status' => 'pack_draft']);

        $this->assertTrue($meeting->isEditable());

        $this->actingAs($admin)
            ->put("/governance/meetings/{$meeting->id}", ['title' => 'Board meeting (updated)', 'status' => 'pack_draft'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Board meeting (updated)', $meeting->fresh()->title);
    }

    public function test_a_new_pack_version_can_be_made_for_a_scheduled_or_draft_pack_meeting(): void
    {
        Storage::fake('local');
        $admin = $this->createAdminUser();

        foreach (['scheduled', 'pack_draft'] as $status) {
            $meeting = $this->createMeeting($admin, ['title' => "Meeting {$status}", 'status' => $status]);
            $this->assertTrue($meeting->canDistributePack(), "{$status} meetings allow a new pack version");

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

            $this->actingAs($admin)
                ->post("/governance/packs/{$pack->id}/regenerate")
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $this->assertSame(2, BoardPack::query()->where('governance_meeting_id', $meeting->id)->count());
        }
    }

    public function test_cancelled_and_minuted_meetings_stay_closed(): void
    {
        $admin = $this->createAdminUser();

        foreach (['cancelled', 'minutes_draft', 'minutes_signed'] as $status) {
            $meeting = $this->createMeeting($admin, ['status' => $status]);
            $this->assertFalse($meeting->isEditable(), "{$status} meetings are not editable");
            $this->assertFalse($meeting->canDistributePack(), "{$status} meetings take no new pack version");
        }
    }
}
