<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\Communication;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Communications › Delivery problems: broadcast sends that failed, or still have no
 * result 15 minutes after sending, for staff the viewer can see — newest first.
 */
class ControlRoomDeliveryProblemsTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private User $visibleWorker;

    private User $hiddenWorker;

    private Site $visibleSite;

    private Site $hiddenSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->visibleSite = Site::factory()->create(['type' => 'house', 'name' => 'Aurora House']);
        $this->hiddenSite = Site::factory()->create(['type' => 'house', 'name' => 'Kōwhai Lodge']);
        $this->coordinator = $this->staff('coordinator', $this->visibleSite);
        $this->visibleWorker = $this->staff('support_worker', $this->visibleSite);
        $this->hiddenWorker = $this->staff('support_worker', $this->hiddenSite);
    }

    public function test_it_lists_failed_and_unconfirmed_sends_for_visible_staff_newest_first(): void
    {
        $this->send('g-weather', 'sms', 'failed', now()->subHour(), $this->visibleWorker, 'No phone number on record for the recipient.');
        $this->send('g-weather', 'in_app', 'delivered', now()->subHour(), $this->visibleWorker);
        $this->send('g-weather', 'sms', 'failed', now()->subHour(), $this->hiddenWorker, 'No phone number on record for the recipient.');
        $this->send('g-drill', 'email', 'pending', now()->subMinutes(30), $this->visibleWorker);
        // Not problems: still inside the 15 minutes, delivered, or outside the last 7 days.
        $this->send('g-fresh', 'email', 'pending', now()->subMinutes(5), $this->visibleWorker);
        $this->send('g-ok', 'email', 'delivered', now()->subMinutes(40), $this->visibleWorker);
        $this->send('g-old', 'sms', 'failed', now()->subDays(10), $this->visibleWorker, 'SMS provider is not configured.');

        $this->actingAs($this->coordinator)
            ->get('/control-room/delivery-problems')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/delivery-problems')
                ->where('thresholdMinutes', 15)
                ->where('filters.period', '7d')
                ->where('filters.channel', null)
                ->where('filters.outcome', null)
                ->where('stats.failed', 1)
                ->where('stats.unconfirmed', 1)
                ->where('stats.people', 1)
                ->where('stats.oldest.broadcast_group_id', 'g-weather')
                ->where('problems.total', 2)
                ->where('problems.data.0.broadcast_group_id', 'g-drill')
                ->where('problems.data.0.channel', 'email')
                ->where('problems.data.0.outcome', 'unconfirmed')
                ->where('problems.data.0.reason', null)
                ->where('problems.data.1.broadcast_group_id', 'g-weather')
                ->where('problems.data.1.outcome', 'failed')
                ->where('problems.data.1.reason', 'no_phone')
                ->where('problems.data.1.person.id', $this->visibleWorker->id)
                ->where('problems.data.1.person.site', 'Aurora House')
                ->where('problems.data.1.reached_by', ['in_app'])
                ->where('can.viewStaff', true)
            );
    }

    public function test_the_numbers_and_filters_narrow_the_list(): void
    {
        $this->send('g-weather', 'sms', 'failed', now()->subHour(), $this->visibleWorker, 'SMS provider is not configured.');
        $this->send('g-drill', 'email', 'sent', now()->subMinutes(30), $this->visibleWorker);
        $this->send('g-old', 'sms', 'failed', now()->subDays(10), $this->visibleWorker, 'SMS provider is not configured.');

        $this->actingAs($this->coordinator)
            ->get('/control-room/delivery-problems?outcome=failed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.outcome', 'failed')
                ->where('problems.total', 1)
                ->where('problems.data.0.reason', 'sms_not_set_up')
                // Header numbers still count both kinds of problem.
                ->where('stats.failed', 1)
                ->where('stats.unconfirmed', 1)
            );

        $this->actingAs($this->coordinator)
            ->get('/control-room/delivery-problems?channel=email')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('problems.total', 1)
                ->where('problems.data.0.broadcast_group_id', 'g-drill')
                ->where('stats.failed', 0)
                ->where('stats.unconfirmed', 1)
            );

        $this->actingAs($this->coordinator)
            ->get('/control-room/delivery-problems?period=30d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.period', '30d')
                ->where('problems.total', 3)
                ->where('stats.failed', 2)
                ->where('stats.oldest.broadcast_group_id', 'g-old')
            );
    }

    public function test_unknown_filter_values_are_ignored(): void
    {
        $this->actingAs($this->coordinator)
            ->get('/control-room/delivery-problems?period=forever&outcome=lost&channel[]=sms')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.period', '7d')
                ->where('filters.outcome', null)
                ->where('filters.channel', null)
                ->where('problems.total', 0)
                ->where('stats.oldest', null)
            );
    }

    public function test_another_sites_staff_never_show_or_count(): void
    {
        $this->send('g-weather', 'sms', 'failed', now()->subHour(), $this->hiddenWorker, 'No phone number on record for the recipient.');
        $this->send('g-drill', 'email', 'pending', now()->subMinutes(30), $this->hiddenWorker);

        $this->actingAs($this->coordinator)
            ->get('/control-room/delivery-problems')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('problems.total', 0)
                ->where('stats.failed', 0)
                ->where('stats.unconfirmed', 0)
                ->where('stats.people', 0)
                ->where('stats.oldest', null)
            );
    }

    public function test_it_needs_the_control_room_permission(): void
    {
        // Signed out first: actingAs() stays signed in for the rest of the test.
        $this->get('/control-room/delivery-problems')->assertRedirect();

        $this->actingAs($this->visibleWorker)
            ->get('/control-room/delivery-problems')
            ->assertForbidden();
    }

    private function send(string $group, string $channel, string $status, $sentAt, User $to, ?string $detail = null): Communication
    {
        $row = Communication::query()->create([
            'broadcast_group_id' => $group,
            'channel' => $channel,
            'direction' => 'outbound',
            'purpose' => 'broadcast',
            'target_user_id' => $to->id,
            'content' => "Broadcast {$group}\nMore detail.",
            'status' => $status,
            'status_detail' => $detail,
            'sent_at' => $sentAt,
            'delivered_at' => $status === 'delivered' ? $sentAt : null,
            'initiated_by_user_id' => $this->coordinator->id,
        ]);

        return $row;
    }

    private function staff(string $role, Site $site): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->firstOrFail()->id]);
        HrEmployeeProfile::query()->updateOrCreate(['user_id' => $user->id], [
            'tenant_id' => 1,
            'employee_number' => 'EMP-DELIVERY-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Delivery Staff',
            'position_role' => $role,
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }
}
