<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceBoardMemberAdminTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_admin_can_view_board_member_admin_page(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/governance/admin/board-members');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Admin/BoardMembers')
        );
    }

    public function test_admin_can_appoint_remove_and_reappoint_board_member(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create(['approved_at' => now()]);

        $appointResponse = $this->actingAs($admin)->post('/governance/admin/board-members', [
            'user_id' => $user->id,
            'board_role' => 'member',
            'term_start' => now()->toDateString(),
            'term_end' => now()->addYears(2)->toDateString(),
        ]);
        $appointResponse->assertRedirect();

        $boardMember = BoardMember::where('user_id', $user->id)->first();
        $this->assertNotNull($boardMember);
        $this->assertTrue($boardMember->is_active);

        $removeResponse = $this->actingAs($admin)->delete("/governance/admin/board-members/{$boardMember->id}");
        $removeResponse->assertRedirect();

        $boardMember->refresh();
        $this->assertFalse($boardMember->is_active);
        $this->assertNotNull($boardMember->deleted_at);

        $reappointResponse = $this->actingAs($admin)->post('/governance/admin/board-members', [
            'user_id' => $user->id,
            'board_role' => 'chair',
            'term_start' => now()->toDateString(),
            'term_end' => now()->addYears(3)->toDateString(),
        ]);
        $reappointResponse->assertRedirect();

        $restored = BoardMember::withTrashed()->where('user_id', $user->id)->first();
        $this->assertNotNull($restored);
        $this->assertTrue($restored->is_active);
        $this->assertNull($restored->deleted_at);
        $this->assertEquals('chair', $restored->board_role);
    }
}
