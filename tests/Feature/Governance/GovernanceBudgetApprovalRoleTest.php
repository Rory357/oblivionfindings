<?php

namespace Tests\Feature\Governance;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * Approving budgets and budget changes belongs to finance approvers (chair,
 * treasurer, admin), not every board member — owner decision 15 September 2026.
 */
class GovernanceBudgetApprovalRoleTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_ordinary_board_members_cannot_approve_budgets_but_finance_approvers_can(): void
    {
        $this->assertFalse($this->createUserWithRole('board_member')->canDo('governance.budgets.approve'));
        $this->assertTrue($this->createUserWithRole('board_chair')->canDo('governance.budgets.approve'));
        $this->assertTrue($this->createAdminUser()->canDo('governance.budgets.approve'));
    }

    public function test_the_migration_removes_the_grant_from_existing_board_member_roles(): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'governance.budgets.approve'],
            ['description' => 'Approve Budgets on behalf of the Board'],
        );
        $member = Role::query()->firstOrCreate(['name' => 'board_member'], ['label' => 'Board member', 'level' => 10, 'type' => 'system']);
        $treasurer = Role::query()->firstOrCreate(['name' => 'board_treasurer'], ['label' => 'Board treasurer', 'level' => 10, 'type' => 'system']);
        $member->permissions()->syncWithoutDetaching([$permission->id]);

        $migration = require base_path('database/migrations/2026_09_15_720000_limit_budget_approval_to_finance_approvers.php');
        $migration->up();

        $this->assertFalse($member->permissions()->where('permissions.id', $permission->id)->exists());
        $this->assertTrue($treasurer->permissions()->where('permissions.id', $permission->id)->exists());
    }
}
