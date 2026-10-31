<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\DispatchProcedureReviewDelivery;
use App\Models\AuditLog;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\ProcedureReviewDelivery;
use App\Models\ProcedureReviewRequest;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\HealthSafety\ProcedureReviewNotifications;
use App\Services\HealthSafety\SafeWorkProcedureService;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProcedureReviewDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $executor;

    private Site $site;

    private SafeWorkProcedure $procedure;

    private HsEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->executor = $this->manager();
        $this->actingAs($this->executor);
        $service = app(SafeWorkProcedureService::class);
        $this->procedure = $service->create($this->executor, [
            'title' => 'Sensitive handling instructions', 'reference_number' => 'SWP-DELIVERY', 'category' => 'manual_handling',
            'steps' => [['description' => 'Private procedure steps.']], 'applicable_roles' => ['support_worker'],
            'applicable_sites' => [$this->site->id], 'related_training' => [], 'owner_id' => $this->executor->id,
        ]);
        $service->lifecycle($this->procedure, $this->executor, 'submit-for-review', $this->guards());
        $service->lifecycle($this->procedure, $this->executor, 'approve', [...$this->guards(), 'approval_note' => 'Approved current instructions.']);
        $this->procedure->refresh();
        $this->event = HsEvent::factory()->create(['site_id' => $this->site->id]);
    }

    private function manager(): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null]);
        foreach (['procedures.view', 'procedures.create', 'procedures.manage', 'procedures.approve', 'hazards.view', 'hazards.manage'] as $key) {
            $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => true]]);
        }

        return $user;
    }

    private function guards(): array
    {
        $record = $this->procedure->fresh();

        return ['expected_version' => $record->current_version, 'expected_lock_version' => $record->lock_version];
    }

    private function requestWork(?string $key = null): ProcedureReviewRequest
    {
        return app(SafeWorkProcedureService::class)->requestReview($this->procedure, $this->executor, [
            'hs_event_id' => $this->event->id, 'requested_version_id' => $this->procedure->published_version_id,
            'assigned_to_user_id' => $this->executor->id, 'note' => 'Sensitive safety finding and requested private changes.',
            'idempotency_key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function intent(ProcedureReviewRequest $request): ProcedureReviewDelivery
    {
        return ProcedureReviewDelivery::query()->where('procedure_review_request_id', $request->id)->orderByDesc('assignment_revision')->firstOrFail();
    }

    private function command(ProcedureReviewRequest $request, string $command, array $data = []): ProcedureReviewRequest
    {
        return app(SafeWorkProcedureService::class)->reviewRequestCommand($this->procedure, $request, $this->executor, $command, [
            'expected_version' => $request->fresh()->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$data,
        ]);
    }

    public function test_source_command_stages_once_and_delivery_is_neutral_repeat_safe_and_not_actioned(): void
    {
        $key = (string) Str::uuid();
        $request = $this->requestWork($key);
        $this->requestWork($key);
        $this->assertSame(1, ProcedureReviewDelivery::count());
        $intent = $this->intent($request);
        $this->assertSame('pending', $intent->status);
        $this->assertSame(0, DB::table('notifications')->count());
        app(ProcedureReviewNotifications::class)->deliver($intent->id);
        app(ProcedureReviewNotifications::class)->deliver($intent->id);
        $this->assertSame(1, DB::table('notifications')->count());
        $notification = DB::table('notifications')->where('id', $intent->id)->first();
        $payload = json_decode($notification->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('/health-safety/procedures/'.$this->procedure->id.'?section=connections&request='.$request->id, $payload['url']);
        $this->assertSame('Procedure review assigned', $payload['title']);
        $this->assertStringNotContainsString('Sensitive', $notification->data);
        $this->assertStringNotContainsString('Private procedure', $notification->data);
        $this->assertArrayNotHasKey('hs_event_id', $payload);
        $this->assertNull($notification->read_at);
        $this->assertSame('requested', $request->fresh()->status);
        $this->assertSame('delivered', $intent->fresh()->status);
        $this->assertSame(1, $intent->fresh()->attempts);
    }

    public function test_start_does_not_invalidate_an_actual_assignment_intent(): void
    {
        $request = $this->requestWork();
        $intent = $this->intent($request);
        $this->command($request, 'start');
        app(ProcedureReviewNotifications::class)->deliver($intent->id);
        $this->assertSame('delivered', $intent->fresh()->status);
        $this->assertSame('in_review', $request->fresh()->status);
    }

    public function test_reassignment_replay_and_unchanged_owner_do_not_create_extra_deliveries(): void
    {
        $request = $this->requestWork();
        $this->command($request, 'reassign', ['assigned_to_user_id' => $this->executor->id]);
        $this->assertSame(1, ProcedureReviewDelivery::count());
        $incoming = $this->manager();
        $data = ['expected_version' => $request->fresh()->lock_version, 'idempotency_key' => (string) Str::uuid(), 'assigned_to_user_id' => $incoming->id];
        app(SafeWorkProcedureService::class)->reviewRequestCommand($this->procedure, $request, $this->executor, 'reassign', $data);
        app(SafeWorkProcedureService::class)->reviewRequestCommand($this->procedure, $request, $this->executor, 'reassign', $data);
        $this->assertSame(2, ProcedureReviewDelivery::count());
        foreach (ProcedureReviewDelivery::all() as $intent) {
            app(ProcedureReviewNotifications::class)->deliver($intent->id);
        }
        $this->assertSame(1, DB::table('notifications')->count());
        $this->assertSame($incoming->id, (int) DB::table('notifications')->first()->notifiable_id);
    }

    public function test_returning_to_the_original_owner_does_not_deliver_the_old_intent(): void
    {
        $request = $this->requestWork();
        $original = $this->intent($request);
        $incoming = $this->manager();
        $this->command($request, 'reassign', ['assigned_to_user_id' => $incoming->id]);
        $this->command($request, 'reassign', ['assigned_to_user_id' => $this->executor->id]);
        app(ProcedureReviewNotifications::class)->deliver($original->id);
        $this->assertSame('skipped', $original->fresh()->status);
        app(ProcedureReviewNotifications::class)->recoverPending();
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public static function terminalStatuses(): array
    {
        return [['declined'], ['cancelled'], ['resolved']];
    }

    #[DataProvider('terminalStatuses')]
    public function test_finished_requests_never_send_delayed_assignment(string $status): void
    {
        $request = $this->requestWork();
        $intent = $this->intent($request);
        $request->update(['status' => $status]);
        app(ProcedureReviewNotifications::class)->deliver($intent->id);
        $this->assertSame('skipped', $intent->fresh()->status);
        $this->assertSame('assignment_inactive', $intent->fresh()->failure_code);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public static function revocations(): array
    {
        return [['inactive_staff'], ['ended_staff'], ['unapproved'], ['external_clinician'], ['procedure_permission'], ['procedure_view'], ['source_permission'], ['source_site'], ['version']];
    }

    #[DataProvider('revocations')]
    public function test_delivery_rechecks_current_staff_source_and_requested_version(string $revocation): void
    {
        $request = $this->requestWork();
        $intent = $this->intent($request);
        if ($revocation === 'inactive_staff') {
            $this->executor->hrEmployeeProfile()->update(['is_active' => false]);
        } elseif ($revocation === 'ended_staff') {
            $this->executor->hrEmployeeProfile()->update(['end_date' => now()->subDay()]);
        } elseif ($revocation === 'unapproved') {
            $this->executor->update(['approved_at' => null]);
        } elseif ($revocation === 'external_clinician') {
            $this->executor->update(['role' => 'external_clinician']);
        } elseif (in_array($revocation, ['procedure_permission', 'procedure_view', 'source_permission'], true)) {
            $permission = match ($revocation) {
                'procedure_permission' => 'procedures.manage',
                'procedure_view' => 'procedures.view',
                default => 'hazards.view',
            };
            $this->executor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $permission)->firstOrFail()->id => ['allowed' => false]]);
        } elseif ($revocation === 'source_site') {
            $this->event->update(['site_id' => Site::factory()->create()->id]);
        } else {
            $other = SafeWorkProcedure::factory()->create();
            $version = SafeWorkProcedureVersion::create(['safe_work_procedure_id' => $other->id, 'version_number' => 2, 'content_snapshot' => ['applicable_roles' => [], 'applicable_sites' => [$this->site->id]], 'provenance' => 'legacy_current_capture']);
            $request->update(['requested_version_id' => $version->id]);
        }
        app(ProcedureReviewNotifications::class)->deliver($intent->id);
        $this->assertSame('skipped', $intent->fresh()->status);
        $this->assertSame('authority_changed', $intent->fresh()->failure_code);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public static function suppressions(): array
    {
        return [['user_disabled'], ['inapp_disabled'], ['role_disabled']];
    }

    #[DataProvider('suppressions')]
    public function test_existing_assignment_preferences_suppress_without_claiming_delivery(string $suppression): void
    {
        $request = $this->requestWork();
        $intent = $this->intent($request);
        if ($suppression === 'role_disabled') {
            $role = Role::where('name', 'support_worker')->firstOrFail();
            $this->executor->roles()->sync([$role->id]);
            RoleNotificationPreference::create(['role_id' => $role->id, 'key' => 'tasks.assigned', 'enabled' => false, 'channel_inapp' => false]);
        } else {
            UserNotificationPreference::create(['user_id' => $this->executor->id, 'key' => 'tasks.assigned', 'enabled' => $suppression !== 'user_disabled', 'channel_inapp' => $suppression !== 'inapp_disabled']);
        }
        app(ProcedureReviewNotifications::class)->deliver($intent->id);
        $this->assertSame('skipped', $intent->fresh()->status);
        $this->assertSame('preference_suppressed', $intent->fresh()->failure_code);
        $this->assertNull($intent->fresh()->delivered_at);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_failed_inbox_delivery_retains_intent_and_recovers_once_after_backoff(): void
    {
        $request = $this->requestWork();
        $intent = $this->intent($request);
        $real = app(Dispatcher::class);
        $broken = Mockery::mock(Dispatcher::class);
        $broken->shouldReceive('send')->once()->andThrow(new RuntimeException('Inbox write failed'));
        $this->app->instance(Dispatcher::class, $broken);
        try {
            app(ProcedureReviewNotifications::class)->deliver($intent->id);
            $this->fail('Expected inbox failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Inbox write failed', $exception->getMessage());
        } finally {
            $this->app->instance(Dispatcher::class, $real);
        }
        $this->assertSame('failed', $intent->fresh()->status);
        $this->assertSame('delivery_failed', $intent->fresh()->failure_code);
        $this->assertSame('requested', $request->fresh()->status);
        $this->assertSame(0, app(ProcedureReviewNotifications::class)->recoverPending());
        $this->travel(31)->seconds();
        $this->assertSame(1, app(ProcedureReviewNotifications::class)->recoverPending());
        $this->assertSame('delivered', $intent->fresh()->status);
        $this->assertSame(2, $intent->fresh()->attempts);
        $this->assertSame(1, DB::table('notifications')->count());
        $this->assertSame(0, app(ProcedureReviewNotifications::class)->recoverPending());
    }

    public function test_staging_rolls_back_with_source_assignment(): void
    {
        try {
            DB::transaction(function (): void {
                $this->requestWork();
                throw new RuntimeException('Strict source audit failed');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Strict source audit failed', $exception->getMessage());
        }
        $this->assertSame(0, ProcedureReviewRequest::count());
        $this->assertSame(0, ProcedureReviewDelivery::count());
        Queue::assertNotPushed(DispatchProcedureReviewDelivery::class);
    }

    public function test_archiving_a_procedure_skips_pending_delivery(): void
    {
        $request = $this->requestWork();
        $intent = $this->intent($request);
        $this->procedure->forceFill(['status' => 'archived'])->saveQuietly();
        app(ProcedureReviewNotifications::class)->deliver($intent->id);
        $this->assertSame('skipped', $intent->fresh()->status);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_disabled_role_cannot_supply_inapp_channel_to_a_different_enabled_role(): void
    {
        $request = $this->requestWork();
        $intent = $this->intent($request);
        $first = Role::where('name', 'support_worker')->firstOrFail();
        $second = Role::where('name', 'coordinator')->firstOrFail();
        $this->executor->roles()->sync([$first->id, $second->id]);
        RoleNotificationPreference::create(['role_id' => $first->id, 'key' => 'tasks.assigned', 'enabled' => true, 'channel_inapp' => false]);
        RoleNotificationPreference::create(['role_id' => $second->id, 'key' => 'tasks.assigned', 'enabled' => false, 'channel_inapp' => true]);
        app(ProcedureReviewNotifications::class)->deliver($intent->id);
        $this->assertSame('preference_suppressed', $intent->fresh()->failure_code);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_user_override_wins_over_disabled_role_default(): void
    {
        $request = $this->requestWork();
        $intent = $this->intent($request);
        $role = Role::where('name', 'support_worker')->firstOrFail();
        $this->executor->roles()->sync([$role->id]);
        RoleNotificationPreference::create(['role_id' => $role->id, 'key' => 'tasks.assigned', 'enabled' => false, 'channel_inapp' => false]);
        UserNotificationPreference::create(['user_id' => $this->executor->id, 'key' => 'tasks.assigned', 'enabled' => true, 'channel_inapp' => true]);
        app(ProcedureReviewNotifications::class)->deliver($intent->id);
        $this->assertSame('delivered', $intent->fresh()->status);
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_notice_opens_exact_request_in_working_lens_when_current_publication_is_unreadable(): void
    {
        $request = $this->requestWork();
        $intent = $this->intent($request);
        $restricted = SafeWorkProcedureVersion::create([
            'safe_work_procedure_id' => $this->procedure->id, 'version_number' => 99,
            'content_snapshot' => ['applicable_roles' => ['support_worker'], 'applicable_sites' => [Site::factory()->create()->id]],
            'provenance' => 'controlled', 'approved_by' => $this->executor->id, 'approved_at' => now(),
        ]);
        $this->procedure->forceFill(['published_version_id' => $restricted->id])->saveQuietly();
        app(ProcedureReviewNotifications::class)->deliver($intent->id);
        $notice = DB::table('notifications')->where('id', $intent->id)->first();
        $payload = json_decode($notice->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('/health-safety/procedures/'.$this->procedure->id.'?section=connections&request='.$request->id.'&revision=working', $payload['url']);
        $this->assertSame('delivered', $intent->fresh()->status);
    }

    public function test_strict_assignment_audit_failure_rolls_back_request_and_delivery(): void
    {
        $originalDispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $originalDispatcher);
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->action === 'procedure.review_requested') {
                throw new RuntimeException('Strict assignment audit failed');
            }
        });
        try {
            $this->requestWork();
            $this->fail('Expected strict assignment audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Strict assignment audit failed', $exception->getMessage());
        } finally {
            AuditLog::setEventDispatcher($originalDispatcher);
        }
        $this->assertSame(0, ProcedureReviewRequest::count());
        $this->assertSame(0, ProcedureReviewDelivery::count());
        Queue::assertNotPushed(DispatchProcedureReviewDelivery::class);
    }

    public function test_outbox_failure_rolls_back_confirmed_request_and_audit(): void
    {
        $originalDispatcher = ProcedureReviewDelivery::getEventDispatcher();
        ProcedureReviewDelivery::setEventDispatcher(clone $originalDispatcher);
        ProcedureReviewDelivery::creating(fn () => throw new RuntimeException('Outbox unavailable'));
        try {
            $this->requestWork();
            $this->fail('Expected atomic outbox write failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Outbox unavailable', $exception->getMessage());
        } finally {
            ProcedureReviewDelivery::setEventDispatcher($originalDispatcher);
        }
        $this->assertSame(0, ProcedureReviewRequest::count());
        $this->assertSame(0, ProcedureReviewDelivery::count());
        $this->assertSame(0, AuditLog::where('action', 'procedure.review_requested')->count());
    }
}
