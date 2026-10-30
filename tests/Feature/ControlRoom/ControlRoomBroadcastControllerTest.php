<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\Communication;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ControlRoom\BroadcastRecipientNotice;
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

    public function test_broadcast_pages_need_the_control_room_permission(): void
    {
        $this->actingAs($this->visibleWorker)->get('/control-room/broadcast')->assertForbidden();
        $this->actingAs($this->visibleWorker)->get('/control-room/broadcast/group-any')->assertForbidden();
    }

    public function test_broadcast_list_and_record_show_in_app_reads_and_history_for_visible_staff(): void
    {
        config(['services.sms.provider' => null]);
        $secondWorker = $this->makeRoleUser('support_worker');
        $this->scopeUserToSite($secondWorker, $this->visibleSite);
        foreach ([$this->visibleWorker, $secondWorker] as $worker) {
            $worker->forceFill(['cellphone' => '+64211234567'])->save();
        }

        $this->actingAs($this->coordinator)
            ->post('/control-room/broadcast', [
                'content' => "Severe weather this afternoon.\nSecure outdoor furniture.",
                'channels' => ['in_app', 'sms'],
                'target_roles' => ['support_worker'],
                'send_to_all' => false,
            ])
            ->assertRedirect(route('control-room.broadcast.index'))
            ->assertSessionHas('success');

        $groupId = (string) Communication::query()->where('purpose', 'broadcast')->value('broadcast_group_id');
        // A send to someone at another Site, in the same broadcast, must never show or count.
        Communication::query()->create([
            'broadcast_group_id' => $groupId,
            'channel' => 'sms',
            'direction' => 'outbound',
            'purpose' => 'broadcast',
            'target_user_id' => $this->hiddenWorker->id,
            'content' => "Severe weather this afternoon.\nSecure outdoor furniture.",
            'status' => 'failed',
            'status_detail' => 'No phone number on record for the recipient.',
            'sent_at' => now(),
            'initiated_by_user_id' => $this->coordinator->id,
        ]);

        // The first worker opens it in the app; the second hasn't yet.
        $readAt = now()->subMinute()->startOfSecond();
        $notice = $this->visibleWorker->notifications()->where('type', BroadcastRecipientNotice::class)->sole();
        $notice->forceFill(['read_at' => $readAt])->save();

        $this->actingAs($this->coordinator)
            ->get('/control-room/broadcast')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/broadcast')
                ->where('broadcasts.data.0.broadcast_group_id', $groupId)
                ->where('broadcasts.data.0.read_count', 1)
                ->where('broadcasts.data.0.read_of', 2)
            );

        $response = $this->actingAs($this->coordinator)
            ->get("/control-room/broadcast/{$groupId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/broadcast-show')
                ->where('summary.total', 4)
                ->where('summary.read', 1)
                ->where('summary.read_of', 2)
                ->has('recipients', 4)
            );

        $props = $response->viewData('page')['props'];
        $recipients = collect($props['recipients']);
        $this->assertFalse($recipients->contains(fn ($r) => ($r['target_user']['id'] ?? null) === $this->hiddenWorker->id));
        $firstInApp = $recipients->first(fn ($r) => $r['channel'] === 'in_app' && $r['target_user']['id'] === $this->visibleWorker->id);
        $secondInApp = $recipients->first(fn ($r) => $r['channel'] === 'in_app' && $r['target_user']['id'] === $secondWorker->id);
        $this->assertSame($readAt->toISOString(), $firstInApp['read_at']);
        $this->assertTrue($firstInApp['read_tracked']);
        $this->assertNull($secondInApp['read_at']);
        $this->assertTrue($secondInApp['read_tracked']);
        $this->assertNull($recipients->first(fn ($r) => $r['channel'] === 'sms')['read_at']);

        $history = collect($props['history']);
        $sent = $history->firstWhere('kind', 'sent');
        $this->assertSame('sent', $history->first()['kind']);
        $this->assertSame($this->coordinator->name, $sent['by']);
        $this->assertSame(2, $sent['people']);
        $this->assertSame(['in_app', 'sms'], $sent['channels']);

        $delivered = $history->first(fn ($e) => $e['kind'] === 'delivered' && $e['channel'] === 'in_app');
        $this->assertSame(2, $delivered['count']);

        $failed = $history->first(fn ($e) => $e['kind'] === 'failed' && $e['channel'] === 'sms');
        $this->assertSame(2, $failed['count']);
        $this->assertSame([['reason' => 'sms_not_set_up', 'count' => 2, 'detail' => null]], $failed['reasons']);

        $read = $history->firstWhere('kind', 'read');
        $this->assertSame(1, $read['count']);
        $this->assertSame(2, $read['of']);
        $this->assertSame($this->visibleWorker->name, $read['first_reader']);
        $this->assertSame($readAt->toISOString(), $read['at']);

        // Nothing is waiting, and nothing that isn't recorded (retries, acknowledgements) appears.
        $this->assertSame([], $history->pluck('kind')->diff(['sent', 'delivered', 'failed', 'read'])->values()->all());
    }

    public function test_broadcast_record_lists_sends_still_waiting_last_in_its_history(): void
    {
        $groupId = 'group-waiting';
        Communication::query()->create([
            'broadcast_group_id' => $groupId,
            'channel' => 'email',
            'direction' => 'outbound',
            'purpose' => 'broadcast',
            'target_user_id' => $this->visibleWorker->id,
            'content' => 'Fire drill at 2 pm',
            'status' => 'pending',
            'sent_at' => now()->subMinutes(20),
            'initiated_by_user_id' => $this->coordinator->id,
        ]);

        $response = $this->actingAs($this->coordinator)
            ->get("/control-room/broadcast/{$groupId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.read', 0)
                ->where('summary.read_of', 0)
                ->where('recipients.0.read_tracked', false)
            );

        $history = collect($response->viewData('page')['props']['history']);
        $this->assertSame(['sent', 'waiting'], $history->pluck('kind')->all());
        $this->assertSame('email', $history->last()['channel']);
        $this->assertSame(1, $history->last()['count']);
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
