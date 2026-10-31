<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ControlRoom\BroadcastIntent;
use App\Models\ControlRoom\Communication;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ControlRoom\BroadcastRecipientNotice;
use App\Services\ControlRoom\BroadcastRecipientAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Staff broadcast messages in the inbox: a real Control Room broadcast creates one
 * reference-only notice per in-app recipient, only that recipient can read it (while
 * current staff in the original audience), and every default path leaves it alone.
 */
class BroadcastRecipientReaderTest extends TestCase
{
    use RefreshDatabase;

    private User $sender;

    private User $worker;

    private User $colleague;

    private Site $siteA;

    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->siteA = Site::factory()->create(['type' => 'house']);
        $this->siteB = Site::factory()->create(['type' => 'house']);
        $this->sender = $this->staff('coordinator', $this->siteA);
        $this->worker = $this->staff('support_worker', $this->siteA);
        $this->colleague = $this->staff('support_worker', $this->siteA);
    }

    private function staff(string $role, Site $site): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->firstOrFail()->id]);
        HrEmployeeProfile::query()->updateOrCreate(['user_id' => $user->id], [
            'tenant_id' => 1, 'employee_number' => 'BRR-'.$user->id, 'work_email' => $user->email,
            'position_title' => 'Staff', 'position_role' => $role, 'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(), 'is_active' => true,
            'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        ]);

        return $user;
    }

    private function send(string $content, array $channels = ['in_app'], array $roles = ['support_worker']): BroadcastIntent
    {
        $this->actingAs($this->sender)->post('/control-room/broadcast', [
            'content' => $content, 'channels' => $channels, 'target_roles' => $roles, 'send_to_all' => false,
        ])->assertRedirect(route('control-room.broadcast.index'))->assertSessionHas('success');

        return BroadcastIntent::query()->latest('created_at')->latest('id')->firstOrFail();
    }

    private function noticeFor(User $user): DatabaseNotification
    {
        return $user->notifications()->where('type', BroadcastRecipientNotice::class)->sole();
    }

    private function ordinary(User $user, string $title = 'Shift reminder'): DatabaseNotification
    {
        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\ShiftReminder',
            'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'data' => ['title' => $title, 'module' => 'operations'], 'read_at' => null,
        ]);
    }

    public function test_an_in_app_broadcast_creates_one_reference_only_notice_per_recipient(): void
    {
        $intent = $this->send("Fire drill at 2:00 pm — meet at the letterbox.\nKia ora koutou.", ['in_app', 'email']);

        foreach ([$this->worker, $this->colleague] as $recipient) {
            $notice = $this->noticeFor($recipient);
            $data = $notice->data;
            ksort($data);
            $this->assertSame(['communication_id', 'intent_id', 'kind', 'schema_version'], array_keys($data));
            $this->assertSame('broadcast_recipient', $data['kind']);
            $this->assertSame($intent->id, $data['intent_id']);

            $row = Communication::query()->whereKey($data['communication_id'])->sole();
            $this->assertSame('in_app', $row->channel);
            $this->assertSame((int) $recipient->id, (int) $row->target_user_id);
            $this->assertSame('in_app_available', $row->broadcast_delivery_state);
            $this->assertSame('available', $row->broadcast_outcome['availability']);
            $this->assertSame($notice->id, $row->broadcast_outcome['recipient_notification_id']);
            $this->assertSame('delivered', $row->status);
            $this->assertSame(1, AuditLog::query()->where('action', BroadcastRecipientAvailabilityService::AUDIT_ACTION)
                ->where('auditable_id', $row->id)->whereNull('user_id')->count());
            $this->assertArrayNotHasKey('content', AuditLog::query()->where('auditable_id', $row->id)
                ->where('action', BroadcastRecipientAvailabilityService::AUDIT_ACTION)->sole()->meta);
        }
        // The sender isn't a support worker, so got nothing; email rows keep status-only delivery.
        $this->assertSame(0, $this->sender->notifications()->count());
        $this->assertSame(0, Communication::query()->where('channel', 'email')->whereNotNull('broadcast_delivery_state')->count());
        $this->assertSame(2, $intent->selected_people_count);
    }

    public function test_the_recipient_reads_the_full_message_in_the_notification_centre(): void
    {
        $this->send('Staff meeting moved to Thursday at 9:30 am.');
        $notice = $this->noticeFor($this->worker);
        $this->ordinary($this->worker);

        $response = $this->actingAs($this->worker)->get('/notifications')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('notifications/index')
                ->where('broadcast_reader.schema_version', 1)
                ->where('broadcast_reader.viewer_id', $this->worker->id)
                ->where('broadcast_reader.capabilities', ['mark_read' => true, 'acknowledge' => false, 'bulk_mark_read' => true])
                ->where('unread_count', 2)
                ->where('selected_broadcast_notification', null)
                ->has('notifications.data', 2)
            );
        $props = $response->viewData('page')['props'];
        $typed = collect($props['notifications']['data'])->firstWhere('id', $notice->id);
        $this->assertNotNull($typed);
        $this->assertSame('BroadcastRecipientNotice', $typed['type']);
        $this->assertNull($typed['acknowledged_at']);
        $this->assertSame([
            'schema_version' => 1, 'type' => 'broadcast_recipient', 'module' => 'control_room',
            'intent_id' => $notice->data['intent_id'], 'communication_id' => $notice->data['communication_id'],
            'recipient_viewer_id' => $this->worker->id, 'reader_read_id' => $props['broadcast_reader']['read_id'],
            'title' => 'Staff message', 'body' => 'Staff meeting moved to Thursday at 9:30 am.',
            'url' => '/notifications?notification='.$notice->id, 'ack_required' => false,
        ], $typed['data']);

        // The Control room module filter includes staff messages.
        $this->actingAs($this->worker)->get('/notifications?type=control_room')->assertOk()
            ->assertInertia(fn ($page) => $page->has('notifications.data', 1)->where('notifications.data.0.id', $notice->id));
    }

    public function test_a_selected_message_resolves_only_for_its_own_recipient(): void
    {
        $this->send('Selected message test');
        $mine = $this->noticeFor($this->worker);
        $theirs = $this->noticeFor($this->colleague);

        $this->actingAs($this->worker)->get('/notifications?notification='.$mine->id.'&filter=read')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('selected_broadcast_notification.id', $mine->id)
                ->where('selected_broadcast_notification.data.body', 'Selected message test')
                ->has('notifications.data', 0));

        foreach ([
            '/notifications?notification='.$theirs->id,
            '/notifications?notification='.(string) Str::uuid(),
            '/notifications?notification='.strtoupper($mine->id),
            '/notifications?notification='.$mine->id.'&notification='.$mine->id,
            '/notifications?notification[]='.$mine->id,
            '/notifications?notification=',
        ] as $url) {
            $this->actingAs($this->worker)->get($url)->assertNotFound();
        }
    }

    public function test_default_paths_never_show_or_write_broadcast_notices(): void
    {
        $this->send('Not for generic handlers');
        $notice = $this->noticeFor($this->worker);
        $ordinary = $this->ordinary($this->worker);

        $this->actingAs($this->worker);
        $visible = app(BoardPackAccessService::class)->visibleNotificationQuery($this->worker)->pluck('id')->all();
        $this->assertSame([$ordinary->id], $visible);

        // The current Notification Centre's generic click: no write, no error page.
        $this->post('/inbox/notifications/'.$notice->id.'/read')->assertRedirect()->assertSessionHas('info');
        $this->post('/inbox/notifications/'.$this->noticeFor($this->colleague)->id.'/read')->assertNotFound();
        $this->post('/inbox/notifications/'.$notice->id.'/acknowledge')->assertNotFound();
        $this->post('/inbox/notifications/read-all')->assertRedirect();
        $this->assertNull($notice->fresh()->read_at);
        $this->assertNull($notice->fresh()->acknowledged_at);
        $this->assertNotNull($ordinary->fresh()->read_at);

        // A notice retyped to look ordinary is still recognised and kept out.
        DatabaseNotification::query()->whereKey($notice->id)->update(['type' => 'App\\Notifications\\ShiftReminder']);
        $this->assertNotContains($notice->id, app(BoardPackAccessService::class)->visibleNotificationQuery($this->worker)->pluck('id')->all());
        $this->get('/notifications?notification='.$notice->id)->assertNotFound();
    }

    public function test_a_message_follows_the_recipient_not_their_site(): void
    {
        $this->send('Personal message survives a transfer');
        $notice = $this->noticeFor($this->worker);

        HrEmployeeProfile::query()->where('user_id', $this->worker->id)->update(['primary_site_id' => $this->siteB->id]);
        HrEmployeeProfile::query()->where('user_id', $this->sender->id)->update(['is_active' => false]);

        $this->actingAs($this->worker)->get('/notifications?notification='.$notice->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('selected_broadcast_notification.id', $notice->id));
    }

    public function test_leaving_staff_or_the_audience_role_withdraws_the_message(): void
    {
        $this->send('Withdrawn when no longer eligible');
        $notice = $this->noticeFor($this->worker);
        $colleagueNotice = $this->noticeFor($this->colleague);

        HrEmployeeProfile::query()->where('user_id', $this->worker->id)->update(['is_active' => false]);
        $this->actingAs($this->worker)->get('/notifications')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('notifications.data', 0)
                ->where('unread_count', 0)
                ->where('broadcast_reader.capabilities.mark_read', false));
        $this->actingAs($this->worker)->get('/notifications?notification='.$notice->id)->assertNotFound();

        $this->colleague->roles()->detach(Role::query()->where('name', 'support_worker')->value('id'));
        $this->actingAs($this->colleague)->get('/notifications?notification='.$colleagueNotice->id)->assertNotFound();
    }

    public function test_a_broken_ledger_link_is_never_shown(): void
    {
        $this->send('Original text');
        $notice = $this->noticeFor($this->worker);
        Communication::query()->whereKey($notice->data['communication_id'])->update(['content' => 'Edited text']);

        $this->actingAs($this->worker)->get('/notifications')->assertOk()
            ->assertInertia(fn ($page) => $page->has('notifications.data', 0)->where('unread_count', 0));
        $this->actingAs($this->worker)->get('/notifications?notification='.$notice->id)->assertNotFound();
    }

    public function test_an_ineligible_recipient_gets_no_notice(): void
    {
        HrEmployeeProfile::query()->where('user_id', $this->colleague->id)->update(['end_date' => now()->subDay()->toDateString()]);
        $this->send('Only current staff');

        $this->assertSame(1, DatabaseNotification::query()->where('type', BroadcastRecipientNotice::class)->count());
        $this->assertSame(0, $this->colleague->notifications()->count());
    }
}
