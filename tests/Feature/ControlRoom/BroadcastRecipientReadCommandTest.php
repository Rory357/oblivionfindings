<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\BroadcastRecipientReadCommand;
use App\Models\ControlRoom\Communication;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ControlRoom\BroadcastRecipientNotice;
use App\Services\ControlRoom\BroadcastRecipientAvailabilityService;
use App\Services\ControlRoom\BroadcastRecipientLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Typed "mark as read" for staff broadcast messages, over the real inbox routes (v3 HTTP/DTO contract). */
class BroadcastRecipientReadCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $sender;

    private User $worker;

    private User $colleague;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->sender = $this->staff('coordinator');
        $this->worker = $this->staff('support_worker');
        $this->colleague = $this->staff('support_worker');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->firstOrFail()->id]);
        HrEmployeeProfile::query()->updateOrCreate(['user_id' => $user->id], [
            'tenant_id' => 1, 'employee_number' => 'BRC-'.$user->id, 'work_email' => $user->email,
            'position_title' => 'Staff', 'position_role' => $role, 'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(), 'is_active' => true,
            'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
        ]);

        return $user;
    }

    /** A real broadcast through the ledger and availability writer, to one recipient. */
    private function message(User $to, string $content = 'Staff update'): DatabaseNotification
    {
        $group = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($to, $content, $group, $now): void {
            Communication::query()->insert([
                'broadcast_group_id' => $group, 'alert_id' => null, 'channel' => 'in_app', 'direction' => 'outbound',
                'purpose' => 'broadcast', 'target_user_id' => $to->id, 'content' => $content, 'template_used' => null,
                'status' => 'pending', 'force_delivery' => false, 'sent_at' => $now,
                'initiated_by_user_id' => $this->sender->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            app(BroadcastRecipientLedger::class)->record($this->sender, $group, [
                'content' => $content, 'channels' => ['in_app'], 'target_roles' => ['support_worker'],
                'send_to_all' => false, 'template' => null, 'force_delivery' => false,
            ], collect([$to]), $now);
        });
        $id = (int) Communication::query()->where('broadcast_group_id', $group)->value('id');
        app(BroadcastRecipientAvailabilityService::class)->publish($id, app(BroadcastRecipientLedger::class)->claimInApp($id));

        return $to->notifications()->where('type', BroadcastRecipientNotice::class)->where('data->communication_id', $id)->sole();
    }

    private function body(User $actor, ?string $key = null, array $override = []): array
    {
        return array_replace(['kind' => 'broadcast_recipient', 'schema_version' => 1,
            'expected_actor_id' => $actor->id, 'command_id' => $key ?? (string) Str::uuid()], $override);
    }

    private function single(User $actor, string $notice, array $body): TestResponse
    {
        return $this->actingAs($actor)->postJson('/inbox/notifications/'.$notice.'/read', $body);
    }

    private function bulk(User $actor, array $body): TestResponse
    {
        return $this->actingAs($actor)->postJson('/inbox/notifications/read-all', $body);
    }

    private function envelope(TestResponse $response, int $status, string $outcome, string $code): array
    {
        $response->assertStatus($status)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $json = $response->json();
        $this->assertSame(['schema_version', 'kind', 'actor_user_id', 'expected_actor_id', 'command_id', 'operation', 'outcome', 'code', 'checked_at', 'receipt', 'current_more_remain', 'current_more_remain_checked_at'], array_keys($json));
        $this->assertSame([$outcome, $code], [$json['outcome'], $json['code']]);

        return $json;
    }

    public function test_single_read_confirms_and_an_exact_replay_keeps_the_first_read_time(): void
    {
        $notice = $this->message($this->worker);
        $key = (string) Str::uuid();

        $first = $this->envelope($this->single($this->worker, $notice->id, $this->body($this->worker, $key)), 200, 'confirmed', 'command_confirmed');
        $this->assertSame('single', $first['operation']);
        $this->assertSame([$notice->id], $first['receipt']['notification_ids']);
        $this->assertSame([1, 1], [$first['receipt']['selected_count'], $first['receipt']['changed_count']]);
        $this->assertFalse($first['receipt']['more_remain']);
        $readAt = $notice->fresh()->getRawOriginal('read_at');
        $this->assertNotNull($readAt);

        $this->travel(15)->seconds();
        $second = $this->envelope($this->single($this->worker, $notice->id, $this->body($this->worker, $key)), 200, 'confirmed', 'command_confirmed');
        $this->assertSame($first['receipt'], $second['receipt']);
        $this->assertSame($readAt, $notice->fresh()->getRawOriginal('read_at'));
        $this->assertNull($notice->fresh()->acknowledged_at);
        $this->assertSame(1, BroadcastRecipientReadCommand::query()->count());

        // Already read: a new command confirms without moving the first read.
        $again = $this->envelope($this->single($this->worker, $notice->id, $this->body($this->worker)), 200, 'confirmed', 'command_confirmed');
        $this->assertSame(0, $again['receipt']['changed_count']);
        $this->assertSame($readAt, $notice->fresh()->getRawOriginal('read_at'));
    }

    public function test_a_stale_account_or_malformed_request_has_no_effect(): void
    {
        $notice = $this->message($this->worker);

        $stale = $this->envelope($this->single($this->worker, $notice->id, $this->body($this->colleague)), 403, 'denied', 'actor_changed');
        $this->assertNull($stale['receipt']);
        $this->assertSame($this->worker->id, $stale['actor_user_id']);

        foreach ([
            $this->body($this->worker, null, ['extra' => true]),
            $this->body($this->worker, null, ['expected_actor_id' => (string) $this->worker->id]),
            $this->body($this->worker, null, ['command_id' => 'not-a-uuid']),
            ['kind' => 'broadcast_recipient'],
        ] as $bad) {
            $json = $this->envelope($this->single($this->worker, $notice->id, $bad), 400, 'pre_effect', 'invalid_request');
            $this->assertSame([null, null, 'single'], [$json['expected_actor_id'], $json['command_id'], $json['operation']]);
        }
        $this->assertNull($notice->fresh()->read_at);
        $this->assertSame(0, BroadcastRecipientReadCommand::query()->count());
    }

    public function test_someone_elses_message_is_unavailable(): void
    {
        $theirs = $this->message($this->colleague);

        $json = $this->envelope($this->single($this->worker, $theirs->id, $this->body($this->worker)), 404, 'denied', 'current_record_unavailable');
        $this->assertNull($json['receipt']);
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_bulk_reads_only_the_actors_own_unread_messages(): void
    {
        $a = $this->message($this->worker, 'One');
        $b = $this->message($this->worker, 'Two');
        $theirs = $this->message($this->colleague, 'Three');
        $ordinary = DatabaseNotification::query()->create(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\ShiftReminder',
            'notifiable_type' => $this->worker->getMorphClass(), 'notifiable_id' => $this->worker->id, 'data' => ['title' => 'Ordinary']]);

        $json = $this->envelope($this->bulk($this->worker, $this->body($this->worker)), 200, 'confirmed', 'command_confirmed');
        $this->assertSame('bulk', $json['operation']);
        $this->assertSame([2, 2], [$json['receipt']['selected_count'], $json['receipt']['changed_count']]);
        $this->assertNotNull($a->fresh()->read_at);
        $this->assertNotNull($b->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
        $this->assertNull($ordinary->fresh()->read_at);
    }

    public function test_bulk_is_capped_at_200_and_reports_more_remaining(): void
    {
        for ($i = 0; $i < 201; $i++) {
            $this->message($this->worker, 'Bulk '.$i);
        }

        $json = $this->envelope($this->bulk($this->worker, $this->body($this->worker)), 200, 'confirmed', 'command_confirmed');
        $this->assertSame([200, 200], [$json['receipt']['selected_count'], $json['receipt']['changed_count']]);
        $this->assertTrue($json['receipt']['more_remain']);
        $this->assertSame(1, $this->worker->unreadNotifications()->where('type', BroadcastRecipientNotice::class)->count());
    }

    public function test_an_empty_bulk_capture_never_absorbs_later_messages(): void
    {
        $key = (string) Str::uuid();
        $json = $this->envelope($this->bulk($this->worker, $this->body($this->worker, $key)), 200, 'confirmed', 'command_confirmed');
        $this->assertSame([0, 0], [$json['receipt']['selected_count'], $json['receipt']['changed_count']]);

        $later = $this->message($this->worker, 'Arrived later');
        $replay = $this->envelope($this->bulk($this->worker, $this->body($this->worker, $key)), 200, 'confirmed', 'command_confirmed');
        $this->assertSame($json['receipt'], $replay['receipt']);
        $this->assertNull($later->fresh()->read_at);
    }

    public function test_reconcile_returns_the_receipt_and_unknown_keys_are_not_found(): void
    {
        $notice = $this->message($this->worker);
        $key = (string) Str::uuid();
        $first = $this->single($this->worker, $notice->id, $this->body($this->worker, $key))->json();

        $json = $this->envelope($this->reconcileGet($key, (string) $this->worker->id), 200, 'confirmed', 'command_confirmed');
        $this->assertSame($first['receipt'], $json['receipt']);
        $this->assertSame('single', $json['operation']);
        $this->assertFalse($json['current_more_remain']);
        $this->assertNotNull($json['current_more_remain_checked_at']);

        $missing = $this->envelope($this->reconcileGet((string) Str::uuid(), (string) $this->worker->id), 404, 'pre_effect', 'command_not_found');
        $this->assertNull($missing['operation']);

        $this->envelope($this->reconcileGet($key, (string) $this->colleague->id), 403, 'denied', 'actor_changed');
        $this->envelope($this->reconcileGet($key, '0'.$this->worker->id), 400, 'pre_effect', 'invalid_request');

        // A GET that carries a body is refused (getJson() sends "[]").
        $this->envelope($this->actingAs($this->worker)->getJson('/inbox/notifications/broadcast-read-commands/'.$key
            .'?expected_actor_id='.$this->worker->id), 400, 'pre_effect', 'invalid_request');
    }

    /** A bodiless JSON GET, as the Notification Centre sends it. */
    private function reconcileGet(string $key, string $expected): TestResponse
    {
        return $this->actingAs($this->worker)->get('/inbox/notifications/broadcast-read-commands/'.$key
            .'?expected_actor_id='.$expected, ['Accept' => 'application/json']);
    }

    public function test_reusing_a_key_for_another_operation_conflicts(): void
    {
        $notice = $this->message($this->worker);
        $key = (string) Str::uuid();
        $this->single($this->worker, $notice->id, $this->body($this->worker, $key))->assertOk();

        $this->envelope($this->bulk($this->worker, $this->body($this->worker, $key)), 409, 'conflict', 'command_identity_conflict');
    }

    public function test_staff_who_have_left_cannot_mark_messages_read(): void
    {
        $notice = $this->message($this->worker);
        HrEmployeeProfile::query()->where('user_id', $this->worker->id)->update(['is_active' => false]);

        $this->envelope($this->single($this->worker, $notice->id, $this->body($this->worker)), 403, 'denied', 'current_actor_unavailable');
        $this->assertNull($notice->fresh()->read_at);
    }
}
