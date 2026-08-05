<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HsWorksafeDecisionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_decision_requires_hazards_manage(): void
    {
        $site = Site::factory()->create();
        $user = $this->siteBoundUser($site, ['hazards.view']);
        $event = HsEvent::factory()->worksafeUndecided()->create([
            'site_id' => $site->id,
        ]);

        $this->actingAs($user)
            ->post("/health-safety/events/{$event->id}/worksafe/decision", $this->decisionPayload())
            ->assertForbidden();

        $this->assertNull($event->fresh()->worksafe_notifiable);
    }

    public function test_site_bound_manager_receives_direct_object_denial_for_a_different_site(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $user = $this->siteBoundUser($siteA, ['hazards.manage']);
        $event = HsEvent::factory()->worksafeUndecided()->create([
            'site_id' => $siteB->id,
        ]);

        $this->actingAs($user)
            ->post("/health-safety/events/{$event->id}/worksafe/decision", $this->decisionPayload())
            ->assertNotFound();

        $this->assertNull($event->fresh()->worksafe_notifiable);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
        ]);
        $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync(
            $permissions->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]),
        );

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    /**
     * @return array{notifiable: bool, reason: string, source: string}
     */
    private function decisionPayload(): array
    {
        return [
            'notifiable' => false,
            'reason' => 'The documented review found that the WorkSafe notification threshold is not met.',
            'source' => 'manual',
        ];
    }
}
