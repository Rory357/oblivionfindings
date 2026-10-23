<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ControlRoom\Communication;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ControlRoom\BroadcastRecipientNotice;
use App\Services\ControlRoom\BroadcastRecipientAvailabilityService;
use App\Services\ControlRoom\BroadcastRecipientLedger;
use App\Services\ControlRoom\BroadcastRecipientReadContext;
use App\Services\ControlRoom\BroadcastRecipientReadService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real slim ledger and availability records; disposable isolated DB only. Never run on shared defaults. */
final class MyDayBroadcastRecipientTest extends TestCase
{
    use DatabaseTruncation;

    private User $sender;
    private User $worker;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-23 10:00:00', 'Pacific/Auckland')->utc());
        $this->seed(\Database\Seeders\RbacSeeder::class);
        self::assertSame(0, DB::transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'archived' => false]);
        $this->sender = $this->staff('coordinator', $this->site);
        $this->worker = $this->staff('support_worker', $this->site);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $role, ?Site $site): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'work_email' => $user->email,
            'position_role' => $role,
            'primary_site_id' => $site?->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }

    /** Same canonical fixture as the immutable slim reader tests: no hand-written notice or proof. */
    private function publish(string $body, array $targetRoles = ['support_worker']): DatabaseNotification
    {
        $group = (string) Str::uuid();
        $at = now();
        DB::transaction(function () use ($body, $targetRoles, $group, $at): void {
            Communication::query()->insert([
                'broadcast_group_id' => $group,
                'alert_id' => null,
                'channel' => 'in_app',
                'direction' => 'outbound',
                'purpose' => 'broadcast',
                'target_user_id' => $this->worker->id,
                'content' => $body,
                'template_used' => null,
                'status' => 'pending',
                'force_delivery' => false,
                'sent_at' => $at,
                'initiated_by_user_id' => $this->sender->id,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            app(BroadcastRecipientLedger::class)->record($this->sender, $group, [
                'content' => $body,
                'channels' => ['in_app'],
                'target_roles' => $targetRoles,
                'send_to_all' => false,
                'template' => null,
                'force_delivery' => false,
            ], collect([$this->worker]), $at);
        });
        $communication = Communication::query()
            ->where('broadcast_group_id', $group)
            ->where('target_user_id', $this->worker->id)
            ->where('channel', 'in_app')
            ->sole();
        $token = app(BroadcastRecipientLedger::class)->claimInApp($communication->id);
        self::assertIsString($token);
        app(BroadcastRecipientAvailabilityService::class)->publish($communication->id, $token);
        self::assertSame('in_app_available', $communication->fresh()->broadcast_delivery_state);

        return DatabaseNotification::query()
            ->where('notifiable_type', $this->worker->getMorphClass())
            ->where('notifiable_id', $this->worker->id)
            ->where('type', BroadcastRecipientNotice::class)
            ->where('data->communication_id', $communication->id)
            ->sole();
    }

    private function ordinary(User $viewer, string $title = 'Ordinary update'): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\ShiftTaskDueNotification',
            'notifiable_type' => $viewer->getMorphClass(),
            'notifiable_id' => $viewer->id,
            'data' => json_encode(['title' => $title, 'priority' => 'high'], JSON_THROW_ON_ERROR),
            'read_at' => null,
            'acknowledged_at' => null,
            'escalation_count' => 0,
            'last_escalated_at' => null,
            'created_at' => now()->subMinutes(12),
            'updated_at' => now()->subMinutes(12),
        ]);

        return $id;
    }

    /** A deterministic oldest typed notice outside the five actual previews. */
    private function countOnlyFixture(array $roles = ['support_worker']): array
    {
        $counted = $this->publish('Original count-only sixth notice', $roles);
        $displayed = [];
        for ($i = 0; $i < 5; $i++) {
            Carbon::setTestNow(now()->addSecond());
            $displayed[] = $this->publish('Later displayed notice '.$i)->id;
        }
        $latest = $this->worker->notifications()->whereIn('id', [$counted->id, ...$displayed])
            ->latest()->limit(5)->pluck('id')->all();
        self::assertEqualsCanonicalizing($displayed, $latest);
        self::assertNotContains($counted->id, $latest);

        return [$counted, $displayed];
    }

    private function draft(): ShiftHandover
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $client->supportWorkers()->attach($this->worker->id);
        $shift = Shift::factory()->create([
            'user_id' => $this->worker->id,
            'client_id' => $client->id,
            'site_id' => $this->site->id,
            'status' => 'in_progress',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(7),
        ]);

        return ShiftHandover::factory()->draft()->create([
            'client_id' => $client->id,
            'outgoing_staff_id' => $this->worker->id,
            'incoming_staff_id' => null,
            'outgoing_shift_id' => $shift->id,
            'handover_notes' => 'Keep this existing handover draft unchanged.',
        ]);
    }

    public function test_canonical_preview_and_exact_link_share_the_actual_reader_without_marking_read(): void
    {
        $body = str_repeat('Kia ora whānau — 今日の連絡 🌿 ', 12);
        $notification = $this->publish($body);
        $original = $notification->getRawOriginal();
        $response = $this->actingAs($this->worker)->get('/my-day')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('broadcast_updates_reader.schema_version', 1)
            ->where('broadcast_updates_reader.viewer_id', $this->worker->id)
            ->where('broadcast_updates_reader.capabilities', ['mark_read' => true, 'acknowledge' => false, 'bulk_mark_read' => true])
            ->has('notifications', 1)
            ->where('notifications.0.id', $notification->id)
            ->where('notifications.0.title', Str::limit(trim($body), 90))
            ->where('notifications.0.broadcast.schema_version', 1)
            ->where('notifications.0.broadcast.notification_id', $notification->id)
            ->where('notifications.0.broadcast.recipient_viewer_id', $this->worker->id)
            ->where('notifications.0.broadcast.href', '/notifications?notification='.$notification->id)
            ->where('stats.notifications_unread', 1)
        );
        $props = $response->inertiaProps();
        self::assertSame($props['broadcast_updates_reader']['read_id'], $props['notifications'][0]['broadcast']['reader_read_id']);
        self::assertTrue(Str::isUuid($props['broadcast_updates_reader']['read_id']));
        self::assertSame($original, $notification->fresh()->getRawOriginal());
        self::assertNull($notification->fresh()->read_at);
        self::assertNull($notification->fresh()->acknowledged_at);
        self::assertEqualsCanonicalizing(['schema_version', 'kind', 'intent_id', 'communication_id'], array_keys($notification->fresh()->data));
    }

    public function test_personal_historical_message_survives_no_site_and_sender_departure(): void
    {
        $notification = $this->publish('Original personally addressed update');
        DB::table('hr_employee_profiles')->where('user_id', $this->worker->id)->update([
            'primary_site_id' => null,
            'secondary_site_ids' => json_encode([], JSON_THROW_ON_ERROR),
        ]);
        User::query()->whereKey($this->sender->id)->update(['approved_at' => null]);

        $this->actingAs($this->worker)->get('/my-day')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('notifications', 1)
                ->where('notifications.0.broadcast.notification_id', $notification->id)
                ->where('stats.notifications_unread', 1)
            );
    }

    public function test_a_foreign_nonmember_keeps_only_ordinary_updates(): void
    {
        $this->publish('Private original recipient message');
        $outsider = $this->staff('support_worker', null);
        $ordinary = $this->ordinary($outsider);

        $response = $this->actingAs($outsider)->get('/my-day')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('broadcast_updates_reader.viewer_id', $outsider->id)
            ->has('notifications', 1)
            ->where('notifications.0.id', $ordinary)
            ->where('notifications.0.title', 'Ordinary update')
            ->where('notifications.0.at', '12 minutes ago')
            ->where('notifications.0.tone', 'primary')
            ->missing('notifications.0.broadcast')
            ->where('stats.notifications_unread', 1)
        );
        self::assertStringNotContainsString('Private original recipient message', $response->getContent());
    }

    public function test_loss_of_broadcast_current_staff_eligibility_retains_lawful_ordinary_updates(): void
    {
        $this->publish('Private message after staff eligibility loss');
        $ordinary = $this->ordinary($this->worker);
        HrEmployeeProfile::query()->where('user_id', $this->worker->id)->update(['is_active' => false]);

        $response = $this->actingAs($this->worker)->get('/my-day')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('broadcast_updates_reader.viewer_id', $this->worker->id)
            ->where('broadcast_updates_reader.capabilities', ['mark_read' => false, 'acknowledge' => false, 'bulk_mark_read' => false])
            ->has('notifications', 1)
            ->where('notifications.0.id', $ordinary)
            ->where('notifications.0.title', 'Ordinary update')
            ->missing('notifications.0.broadcast')
            ->where('stats.notifications_unread', 1)
        );
        self::assertStringNotContainsString('Private message after staff eligibility loss', $response->getContent());
    }

    public function test_discovery_keeps_five_rows_and_the_full_current_unread_count(): void
    {
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = $this->publish('Current personal update '.$i)->id;
        }

        $response = $this->actingAs($this->worker)->get('/my-day')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->has('notifications', 5)->where('stats.notifications_unread', 6));
        foreach ($response->inertiaProps('notifications') as $item) {
            self::assertContains($item['id'], $ids);
            self::assertSame('/notifications?notification='.$item['id'], $item['broadcast']['href']);
        }
        self::assertSame(6, DatabaseNotification::query()->whereIn('id', $ids)->whereNull('read_at')->count());
    }

    public function test_unread_count_crosses_a_query_batch_without_counting_read_or_foreign_rows(): void
    {
        $typed = $this->publish('Current typed message with ordinary updates');
        $rows = [];
        for ($i = 0; $i < 201; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\ShiftTaskDueNotification',
                'notifiable_type' => $this->worker->getMorphClass(),
                'notifiable_id' => $this->worker->id,
                'data' => json_encode(['title' => 'Own ordinary update '.$i], JSON_THROW_ON_ERROR),
                'read_at' => null,
                'acknowledged_at' => null,
                'escalation_count' => 0,
                'last_escalated_at' => null,
                'created_at' => now()->subMinutes(12),
                'updated_at' => now()->subMinutes(12),
            ];
        }
        DB::table('notifications')->insert($rows);
        $read = $this->ordinary($this->worker, 'Already read ordinary update');
        DB::table('notifications')->where('id', $read)->update(['read_at' => now()]);
        $this->ordinary($this->sender, 'Another recipient ordinary update');

        $this->actingAs($this->worker)->get('/my-day')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('notifications', 5)
                ->where('notifications.0.id', $typed->id)
                ->where('stats.notifications_unread', 202)
            );
    }

    public function test_digest_query_failure_is_not_replaced_with_empty_success_and_does_not_change_a_draft(): void
    {
        $this->publish('Private scope failure update');
        $draft = $this->draft();
        $before = $draft->getRawOriginal();
        $reached = false;
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $dispatcher);
        $connection->listen(function (QueryExecuted $query) use (&$reached): void {
            $sql = strtolower($query->sql);
            if (! $reached && str_contains($sql, 'from `notifications`') && str_contains($sql, 'limit 5')) {
                self::assertInstanceOf(BroadcastRecipientReadContext::class, BroadcastRecipientReadService::current($this->worker));
                $reached = true;
                throw new HttpException(404, 'Current notification scope unavailable.');
            }
        });

        try {
            $response = $this->actingAs($this->worker)->get('/my-day')->assertNotFound();
            self::assertStringNotContainsString('Private scope failure update', $response->getContent());
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
        self::assertTrue($reached);
        self::assertSame($before, $draft->fresh()->getRawOriginal());
    }

    public function test_actual_digest_hydration_source_corruption_is_rejected_by_final_proof(): void
    {
        $notification = $this->publish('Private projection failure update');
        $communicationId = $notification->data['communication_id'];
        $connection = DB::connection();
        $queryDispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $queryDispatcher);
        $digestHydrating = false;
        $connection->listen(function (QueryExecuted $query) use (&$digestHydrating): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'from `notifications`') && str_contains($sql, 'limit 5')) {
                $digestHydrating = true;
            }
        });
        $dispatcher = DatabaseNotification::getEventDispatcher();
        DatabaseNotification::setEventDispatcher(clone $dispatcher);
        $reached = false;
        DatabaseNotification::retrieved(function (DatabaseNotification $row) use ($notification, $communicationId, &$reached, &$digestHydrating): void {
            if ($digestHydrating && ! $reached && $row->id === $notification->id) {
                $reached = true;
                DB::table('control_room_communications')->where('id', $communicationId)->update(['content' => 'Corrupted after original admission']);
            }
        });
        try {
            $response = $this->actingAs($this->worker)->get('/my-day')->assertNotFound();
            self::assertStringNotContainsString('Private projection failure update', $response->getContent());
        } finally {
            DatabaseNotification::setEventDispatcher($dispatcher);
            $connection->setEventDispatcher($queryDispatcher);
        }
        self::assertTrue($reached);
    }

    public function test_partial_reload_returns_current_digest_reader_and_count_together(): void
    {
        $notification = $this->publish('Current partial update');
        $response = $this->actingAs($this->worker)
            ->withHeaders($this->inertiaPartialHeaders('my-day/index', 'today'))
            ->get('/my-day')->assertOk();
        $response->assertJsonPath('props.notifications.0.broadcast.notification_id', $notification->id)
            ->assertJsonPath('props.broadcast_updates_reader.viewer_id', $this->worker->id)
            ->assertJsonPath('props.stats.notifications_unread', 1);
        self::assertSame($response->json('props.broadcast_updates_reader.read_id'), $response->json('props.notifications.0.broadcast.reader_read_id'));
    }

    public function test_full_response_rechecks_the_source_of_a_sixth_count_only_notice(): void
    {
        [$counted, $displayed] = $this->countOnlyFixture();
        $communicationId = $counted->data['communication_id'];
        $siblings = Communication::query()->whereIn('id', DatabaseNotification::query()->whereIn('id', $displayed)
            ->get()->map(fn ($row) => $row->data['communication_id']))->orderBy('id')->get()->map->getRawOriginal()->all();
        $reached = false;
        Inertia::share('my_day_count_only_tail', function () use ($communicationId, &$reached) {
            self::assertInstanceOf(BroadcastRecipientReadContext::class, BroadcastRecipientReadService::current($this->worker));
            $reached = true;
            DB::table('control_room_communications')->where('id', $communicationId)->update(['content' => 'Only the unseen counted source changed']);

            return 'materialized';
        });
        try {
            $response = $this->actingAs($this->worker)->get('/my-day')->assertNotFound();
            self::assertStringNotContainsString('Original count-only sixth notice', $response->getContent());
        } finally {
            Inertia::share('my_day_count_only_tail', null);
        }
        self::assertTrue($reached);
        $after = Communication::query()->whereIn('id', array_column($siblings, 'id'))->orderBy('id')->get()->map->getRawOriginal()->all();
        self::assertSame($siblings, $after);
        self::assertSame(0, DatabaseNotification::query()->whereIn('id', [$counted->id, ...$displayed])->whereNotNull('read_at')->count());
    }

    public function test_partial_response_rechecks_a_sixth_count_only_notice_original_role(): void
    {
        $coordinatorRole = Role::query()->where('name', 'coordinator')->firstOrFail();
        $this->worker->roles()->syncWithoutDetaching([$coordinatorRole->id]);
        [$counted, $displayed] = $this->countOnlyFixture(['coordinator']);
        $reached = false;
        Inertia::share('my_day_count_only_tail', function () use ($coordinatorRole, &$reached) {
            self::assertInstanceOf(BroadcastRecipientReadContext::class, BroadcastRecipientReadService::current($this->worker));
            $reached = true;
            $this->worker->roles()->detach($coordinatorRole->id);

            return 'materialized';
        });
        try {
            $response = $this->actingAs($this->worker)
                ->withHeaders($this->inertiaPartialHeaders('my-day/index', 'my_day_count_only_tail'))
                ->get('/my-day')->assertNotFound();
            self::assertStringNotContainsString('Original count-only sixth notice', $response->getContent());
        } finally {
            Inertia::share('my_day_count_only_tail', null);
        }
        self::assertTrue($reached);
        self::assertFalse($this->worker->roles()->whereKey($coordinatorRole->id)->exists());
        self::assertTrue($this->worker->roles()->where('name', 'support_worker')->exists());
        self::assertTrue((bool) HrEmployeeProfile::query()->where('user_id', $this->worker->id)->value('is_active'));
        self::assertSame(0, DatabaseNotification::query()->whereIn('id', [$counted->id, ...$displayed])->whereNotNull('read_at')->count());
    }

    /** Both requests must pass actual inner prop materialization before final original proof refuses. */
    public function test_late_materialized_authority_withdrawal_refuses_full_and_partial_responses(): void
    {
        $notification = $this->publish('Private final-proof update');
        $draft = $this->draft();
        $before = $draft->getRawOriginal();

        foreach ([false, true] as $partial) {
            HrEmployeeProfile::query()->where('user_id', $this->worker->id)->update(['is_active' => true]);
            $reached = false;
            Inertia::share('my_day_broadcast_tail_probe', function () use (&$reached) {
                self::assertInstanceOf(BroadcastRecipientReadContext::class, BroadcastRecipientReadService::current($this->worker));
                $reached = true;
                DB::table('hr_employee_profiles')->where('user_id', $this->worker->id)->update(['is_active' => false]);

                return 'materialized';
            });
            $headers = $partial ? $this->inertiaPartialHeaders('my-day/index', 'my_day_broadcast_tail_probe') : [];
            try {
                $response = $this->actingAs($this->worker)->withHeaders($headers)->get('/my-day')->assertNotFound();
                self::assertStringNotContainsString('Private final-proof update', $response->getContent());
            } finally {
                Inertia::share('my_day_broadcast_tail_probe', null);
            }
            self::assertTrue($reached);
            self::assertSame($before, $draft->fresh()->getRawOriginal());
            self::assertNull($notification->fresh()->read_at);
            self::assertNull($notification->fresh()->acknowledged_at);
        }
    }
}
