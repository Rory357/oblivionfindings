<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\Communication;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomBroadcastControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $coordinator;

    protected User $visibleWorker;

    protected User $hiddenWorker;

    protected Site $visibleSite;

    protected Site $hiddenSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->coordinator = $this->makeRoleUser('coordinator');
        $this->visibleWorker = $this->makeRoleUser('support_worker');
        $this->hiddenWorker = $this->makeRoleUser('support_worker');
        $this->visibleSite = Site::factory()->create(['type' => 'house']);
        $this->hiddenSite = Site::factory()->create(['type' => 'house']);

        $this->scopeUserToSite($this->coordinator, $this->visibleSite);
        $this->scopeUserToSite($this->visibleWorker, $this->visibleSite);
        $this->scopeUserToSite($this->hiddenWorker, $this->hiddenSite);
    }

    public function test_broadcast_index_scopes_staff_counts_to_visible_sites(): void
    {
        $this->actingAs($this->coordinator)
            ->get('/control-room/broadcast')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/broadcast')
                ->where('roleCounts.support_worker', 1)
                ->where('roleCounts.coordinator', 1)
                ->where('totalStaff', 2)
            );
    }

    public function test_broadcast_store_only_targets_staff_within_visible_sites(): void
    {
        $this->actingAs($this->coordinator)
            ->post('/control-room/broadcast', [
                'content' => 'Site-specific update',
                'channels' => ['email'],
                'target_roles' => ['support_worker'],
                'send_to_all' => false,
            ])
            ->assertRedirect(route('control-room.broadcast.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('control_room_communications', 1);
        $this->assertDatabaseHas('control_room_communications', [
            'target_user_id' => $this->visibleWorker->id,
            'channel' => 'email',
            'purpose' => 'broadcast',
        ]);
        $this->assertDatabaseMissing('control_room_communications', [
            'target_user_id' => $this->hiddenWorker->id,
            'purpose' => 'broadcast',
        ]);
    }

    public function test_broadcast_show_hides_out_of_scope_recipients(): void
    {
        $groupId = 'group-visible-scope';

        Communication::query()->create([
            'broadcast_group_id' => $groupId,
            'channel' => 'email',
            'direction' => 'outbound',
            'purpose' => 'broadcast',
            'target_user_id' => $this->visibleWorker->id,
            'target_email' => $this->visibleWorker->email,
            'content' => 'Visible recipient',
            'status' => 'sent',
            'sent_at' => now(),
            'initiated_by_user_id' => $this->coordinator->id,
        ]);

        Communication::query()->create([
            'broadcast_group_id' => $groupId,
            'channel' => 'email',
            'direction' => 'outbound',
            'purpose' => 'broadcast',
            'target_user_id' => $this->hiddenWorker->id,
            'target_email' => $this->hiddenWorker->email,
            'content' => 'Hidden recipient',
            'status' => 'sent',
            'sent_at' => now(),
            'initiated_by_user_id' => $this->coordinator->id,
        ]);

        $this->actingAs($this->coordinator)
            ->get("/control-room/broadcast/{$groupId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/broadcast-show')
                ->where('summary.total', 1)
                ->has('recipients', 1)
                ->where('recipients.0.target_user.id', $this->visibleWorker->id)
            );
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    protected function scopeUserToSite(User $user, Site $site): void
    {
        HrEmployeeProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => 1,
                'employee_number' => 'EMP-BROADCAST-'.$user->id,
                'work_email' => $user->email,
                'position_title' => 'Broadcast Staff',
                'position_role' => $user->role,
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
            ],
        );
    }
}
