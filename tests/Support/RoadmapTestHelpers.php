<?php

namespace Tests\Support;

use App\Domain\Roadmap\Models\Initiative;
use App\Domain\Roadmap\Models\InitiativeCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\RoadmapPermissionsSeeder;
use Database\Seeders\RoadmapSeeder;

trait RoadmapTestHelpers
{
    protected function seedRoadmapModule(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(GovernancePermissionsSeeder::class);
        $this->seed(RoadmapPermissionsSeeder::class);
        $this->seed(RoadmapSeeder::class);

        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $adminRole->permissions()->sync(Permission::pluck('id')->all());
        }
    }

    protected function createUserWithRole(string $roleName, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => $roleName,
            'approved_at' => now(),
        ], $overrides));

        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    protected function createAdminUser(array $overrides = []): User
    {
        return $this->createUserWithRole('admin', $overrides);
    }

    protected function roadmapCategory(string $key = 'operations'): InitiativeCategory
    {
        return InitiativeCategory::query()
            ->where('key', $key)
            ->firstOrFail();
    }

    protected function createInitiative(User $owner, array $overrides = []): Initiative
    {
        $category = $this->roadmapCategory($overrides['category_key'] ?? 'operations');

        $defaults = [
            'title' => 'Roadmap initiative',
            'category_id' => $category->id,
            'stream' => 'operations',
            'status' => Initiative::STATUS_APPROVED,
            'owner_user_id' => $owner->id,
            'next_decision' => 'Approve next wave',
            'decision_due_at' => now()->addDays(14)->toDateString(),
            'cost_estimate_low' => 1000,
            'cost_estimate_high' => 5000,
            'impact_profile' => [
                'safety' => 3,
                'compliance' => 3,
                'reputation' => 2,
                'financial' => 3,
                'efficiency' => 4,
                'urgency' => 3,
                'complexity' => 2,
                'dependency' => 2,
                'multi_site' => 3,
            ],
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ];

        unset($overrides['category_key']);

        return Initiative::create(array_merge($defaults, $overrides));
    }
}
