<?php

namespace Tests\Feature\Roadmap;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Roadmap\Jobs\SendRoadmapDigestJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AppEventNotification;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\RoadmapPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RoadmapDigestRecipientAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-31 10:00:00');
        $this->seed(RbacSeeder::class);
        $this->seed(GovernancePermissionsSeeder::class);
        $this->seed(RoadmapPermissionsSeeder::class);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_digest_uses_current_permissions_and_staff_lifecycle_instead_of_hard_coded_roles(): void
    {
        $roadmapManager = $this->roleUser('roadmap_manager');
        $this->attachProfile($roadmapManager);

        $centralGovernance = $this->roleUser('board_chair');
        $this->deny($centralGovernance, 'roadmap.view');

        $revoked = $this->roleUser('admin');
        $this->deny($revoked, 'roadmap.view');
        $this->deny($revoked, 'governance.view');

        $unapproved = $this->roleUser('admin', ['approved_at' => null]);
        $ended = $this->roleUser('admin');
        $this->attachProfile($ended, ['end_date' => today()->subDay()]);
        $inactive = $this->roleUser('admin');
        $this->attachProfile($inactive, ['is_active' => false]);
        $future = $this->roleUser('admin');
        $this->attachProfile($future, ['start_date' => today()->addDay()]);
        $archived = $this->roleUser('admin');
        $this->attachProfile($archived)->delete();
        $ordinary = $this->roleUser('support_worker');

        (new SendRoadmapDigestJob)->handle();

        Notification::assertSentToTimes($roadmapManager, AppEventNotification::class, 1);
        Notification::assertSentToTimes($centralGovernance, AppEventNotification::class, 1);
        foreach ([$revoked, $unapproved, $ended, $inactive, $future, $archived, $ordinary] as $excluded) {
            Notification::assertNotSentTo($excluded, AppEventNotification::class);
        }
        Notification::assertCount(2);

        Notification::assertSentTo($roadmapManager, AppEventNotification::class, function (
            AppEventNotification $notification,
            array $channels,
        ): bool {
            return $channels === ['database']
                && $notification->payload === [
                    'kind' => 'roadmap.digest',
                    'title' => 'Roadmap weekly digest',
                    'body' => 'Roadmap digest generated with pending triage, overdue tasks, and decision queue.',
                    'context' => [
                        'pending_suggestions' => 0,
                        'overdue_tasks' => 0,
                        'pending_decisions' => 0,
                    ],
                    'url' => url('/roadmap/dashboard'),
                ];
        });
    }

    public function test_hard_coded_roles_do_not_bypass_current_permission_or_staff_state(): void
    {
        $valid = $this->roleUser('board_chair');
        $revoked = $this->roleUser('admin');
        $this->deny($revoked, 'roadmap.view');
        $this->deny($revoked, 'governance.view');
        $ended = $this->roleUser('provider_manager');
        $this->attachProfile($ended, ['end_date' => today()->subDay()]);

        (new SendRoadmapDigestJob)->handle();

        Notification::assertSentToTimes($valid, AppEventNotification::class, 1);
        Notification::assertNotSentTo($revoked, AppEventNotification::class);
        Notification::assertNotSentTo($ended, AppEventNotification::class);
    }

    /** @param array<string, mixed> $overrides */
    private function roleUser(string $roleName, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => $roleName,
            'approved_at' => now(),
        ], $overrides));
        $role = Role::query()->where('name', $roleName)->sole();
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function attachProfile(User $user, array $overrides = []): HrEmployeeProfile
    {
        return HrEmployeeProfile::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_active' => true,
            'start_date' => today()->subYear(),
            'end_date' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ], $overrides));
    }

    private function deny(User $user, string $permissionKey): void
    {
        $permission = Permission::query()->where('key', $permissionKey)->sole();
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => false],
        ]);
    }
}
