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

    public function test_any_approved_person_can_be_appointed_not_only_staff(): void
    {
        $admin = $this->createAdminUser();
        // A family representative with a portal login — not a staff account.
        $family = $this->createUserWithRole('next_of_kin', ['name' => 'Whānau Representative']);
        $pending = User::factory()->create(['name' => 'Not Yet Approved', 'approved_at' => null]);
        $alreadyOn = $this->createUserWithRole('board_member', ['name' => 'Sitting Member']);
        $this->createBoardMember($alreadyOn);

        $this->actingAs($admin)
            ->get('/governance/admin/board-members')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('availableUsers', fn ($people) => collect($people)->pluck('name')->contains('Whānau Representative')
                    && ! collect($people)->pluck('name')->contains('Not Yet Approved')
                    && ! collect($people)->pluck('name')->contains('Sitting Member'))
                ->where('boardMembers.0.standing', 'active')
                ->where('boardMembers.0.can_vote', true));

        $this->actingAs($admin)->post('/governance/admin/board-members', [
            'user_id' => $family->id,
            'board_role' => 'member',
            'term_start' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHas('success', 'Whānau Representative was appointed to the board.');

        $this->assertTrue(BoardMember::query()->where('user_id', $family->id)->exists());

        // Someone without an approved login still can't be appointed.
        $this->actingAs($admin)
            ->from('/governance/admin/board-members')
            ->post('/governance/admin/board-members', [
                'user_id' => $pending->id,
                'board_role' => 'member',
                'term_start' => now()->toDateString(),
            ])
            ->assertSessionHasErrors(['user_id' => "That person can't be appointed. They need an approved login first."]);
    }

    public function test_clearing_the_term_end_makes_the_term_ongoing(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createBoardMember($this->createUserWithRole('board_member'), [
            'term_start' => '2025-01-01',
            'term_end' => '2027-01-01',
        ]);

        $this->actingAs($admin)
            ->from('/governance/admin/board-members')
            ->put("/governance/admin/board-members/{$member->id}", [
                'board_role' => 'member',
                'is_active' => true,
                'term_end' => null,
            ])
            ->assertRedirect('/governance/admin/board-members')
            ->assertSessionHas('success', 'Appointment saved.');

        $this->assertNull($member->fresh()->term_end);

        $this->actingAs($admin)
            ->from('/governance/admin/board-members')
            ->put("/governance/admin/board-members/{$member->id}", ['term_end' => '2024-06-30'])
            ->assertSessionHasErrors(['term_end' => 'The term must end after it starts (1 January 2025).']);

        $this->assertNull($member->fresh()->term_end);
    }
}
