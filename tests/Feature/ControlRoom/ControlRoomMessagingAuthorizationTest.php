<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\Communication;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ControlRoomMessagingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Site $visibleSite;

    private Site $hiddenSite;

    private User $operator;

    private User $visibleStaff;

    private User $hiddenStaff;

    private ControlRoomAlert $visibleAlert;

    private ControlRoomAlert $hiddenAlert;

    private Communication $visibleAlertMessage;

    private Communication $hiddenAlertMessage;

    private Communication $visibleDirectMessage;

    private Communication $hiddenDirectMessage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->visibleSite = Site::factory()->create([
            'name' => 'Visible Operations Site',
            'type' => 'house',
        ]);
        $this->hiddenSite = Site::factory()->create([
            'name' => 'Hidden Operations Site',
            'type' => 'house',
        ]);

        $this->operator = $this->siteBoundUser(
            $this->visibleSite,
            ['controlRoom.viewAny', 'controlRoom.alerts.manage'],
            'coordinator',
            'Visible Operator',
        );
        $this->visibleStaff = $this->siteBoundUser(
            $this->visibleSite,
            [],
            'support_worker',
            'Visible Support Worker',
        );
        $this->hiddenStaff = $this->siteBoundUser(
            $this->hiddenSite,
            [],
            'support_worker',
            'Hidden Support Worker',
        );

        $this->visibleAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->visibleSite->id,
            'alert_type' => 'Visible Operations Alert',
        ]);
        $this->hiddenAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->hiddenSite->id,
            'alert_type' => 'Hidden Confidential Alert',
        ]);

        $this->visibleAlertMessage = $this->communication([
            'alert_id' => $this->visibleAlert->id,
            'target_user_id' => $this->visibleStaff->id,
            'content' => 'Visible alert message',
            'sent_at' => now()->subMinutes(4),
        ]);
        $this->hiddenAlertMessage = $this->communication([
            'alert_id' => $this->hiddenAlert->id,
            'target_user_id' => $this->hiddenStaff->id,
            'content' => 'Hidden alert message',
            'sent_at' => now()->subMinutes(3),
        ]);
        $this->visibleDirectMessage = $this->communication([
            'alert_id' => null,
            'target_user_id' => $this->visibleStaff->id,
            'content' => 'Visible direct message',
            'sent_at' => now()->subMinutes(2),
        ]);
        $this->hiddenDirectMessage = $this->communication([
            'alert_id' => null,
            'target_user_id' => $this->hiddenStaff->id,
            'content' => 'Hidden direct message',
            'sent_at' => now()->subMinute(),
        ]);
    }

    public function test_site_bound_operator_index_only_contains_accessible_threads_and_staff(): void
    {
        $response = $this->actingAs($this->operator)
            ->get('/control-room/messaging')
            ->assertOk();

        $props = $response->viewData('page')['props'];
        $threads = collect($props['threads']);
        $staff = collect($props['staff']);

        $this->assertEqualsCanonicalizing(
            ["alert-{$this->visibleAlert->id}", "user-{$this->visibleStaff->id}"],
            $threads->pluck('id')->all(),
        );
        $this->assertContains($this->operator->id, $staff->pluck('id')->all());
        $this->assertContains($this->visibleStaff->id, $staff->pluck('id')->all());
        $this->assertNotContains($this->hiddenStaff->id, $staff->pluck('id')->all());

        $encodedProps = json_encode($props, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Hidden Confidential Alert', $encodedProps);
        $this->assertStringNotContainsString('Hidden alert message', $encodedProps);
        $this->assertStringNotContainsString('Hidden direct message', $encodedProps);
        $this->assertStringNotContainsString('Hidden Support Worker', $encodedProps);
    }

    public function test_alert_thread_hidden_and_missing_ids_are_both_not_found(): void
    {
        $missingAlertId = (int) ControlRoomAlert::query()->max('id') + 1000;

        $hiddenResponse = $this->actingAs($this->operator)
            ->getJson("/control-room/messaging/thread?alert_id={$this->hiddenAlert->id}");
        $missingResponse = $this->actingAs($this->operator)
            ->getJson("/control-room/messaging/thread?alert_id={$missingAlertId}");

        $hiddenResponse
            ->assertNotFound()
            ->assertJsonMissing(['content' => 'Hidden alert message']);
        $missingResponse->assertNotFound();
        $this->assertSame($hiddenResponse->status(), $missingResponse->status());
    }

    public function test_direct_thread_hidden_and_missing_user_ids_are_both_not_found(): void
    {
        $missingUserId = (int) User::query()->max('id') + 1000;

        $hiddenResponse = $this->actingAs($this->operator)
            ->getJson("/control-room/messaging/thread?user_id={$this->hiddenStaff->id}");
        $missingResponse = $this->actingAs($this->operator)
            ->getJson("/control-room/messaging/thread?user_id={$missingUserId}");

        $hiddenResponse
            ->assertNotFound()
            ->assertJsonMissing(['content' => 'Hidden direct message']);
        $missingResponse->assertNotFound();
        $this->assertSame($hiddenResponse->status(), $missingResponse->status());
    }

    public function test_send_rejects_other_site_alerts_and_inaccessible_target_users_without_writes(): void
    {
        $communicationCount = Communication::query()->count();
        $auditCount = DB::table('audit_logs')->count();

        $hiddenAlertResponse = $this->actingAs($this->operator)
            ->postJson('/control-room/messaging/send', [
                'content' => 'Must not reach a hidden alert',
                'alert_id' => $this->hiddenAlert->id,
                'target_user_id' => $this->visibleStaff->id,
            ]);

        $hiddenTargetResponse = $this->actingAs($this->operator)
            ->postJson('/control-room/messaging/send', [
                'content' => 'Must not reach hidden staff',
                'alert_id' => $this->visibleAlert->id,
                'target_user_id' => $this->hiddenStaff->id,
            ]);

        $this->assertSame($communicationCount, Communication::query()->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $hiddenAlertResponse->assertNotFound();
        $hiddenTargetResponse->assertNotFound();
    }

    public function test_hidden_and_missing_alerts_resolve_before_invalid_send_content_is_validated(): void
    {
        $missingAlertId = (int) ControlRoomAlert::query()->max('id') + 1000;
        $communicationCount = Communication::query()->count();
        $auditCount = DB::table('audit_logs')->count();

        $hiddenResponse = $this->actingAs($this->operator)
            ->postJson('/control-room/messaging/send', [
                'content' => '',
                'alert_id' => $this->hiddenAlert->id,
                'target_user_id' => $this->visibleStaff->id,
            ]);
        $missingResponse = $this->actingAs($this->operator)
            ->postJson('/control-room/messaging/send', [
                'content' => '',
                'alert_id' => $missingAlertId,
                'target_user_id' => $this->visibleStaff->id,
            ]);

        $this->assertSame($communicationCount, Communication::query()->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $hiddenResponse->assertNotFound()->assertJsonMissingValidationErrors('content');
        $missingResponse->assertNotFound()->assertJsonMissingValidationErrors('content');
        $this->assertSame($hiddenResponse->status(), $missingResponse->status());
    }

    public function test_hidden_and_missing_targets_resolve_before_invalid_send_content_is_validated(): void
    {
        $missingUserId = (int) User::query()->max('id') + 1000;
        $communicationCount = Communication::query()->count();
        $auditCount = DB::table('audit_logs')->count();

        $hiddenResponse = $this->actingAs($this->operator)
            ->postJson('/control-room/messaging/send', [
                'content' => '',
                'alert_id' => $this->visibleAlert->id,
                'target_user_id' => $this->hiddenStaff->id,
            ]);
        $missingResponse = $this->actingAs($this->operator)
            ->postJson('/control-room/messaging/send', [
                'content' => '',
                'alert_id' => $this->visibleAlert->id,
                'target_user_id' => $missingUserId,
            ]);

        $this->assertSame($communicationCount, Communication::query()->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $hiddenResponse->assertNotFound()->assertJsonMissingValidationErrors('content');
        $missingResponse->assertNotFound()->assertJsonMissingValidationErrors('content');
        $this->assertSame($hiddenResponse->status(), $missingResponse->status());
    }

    public function test_hidden_alert_resolves_before_missing_target_and_invalid_content_are_validated(): void
    {
        $communicationCount = Communication::query()->count();
        $auditCount = DB::table('audit_logs')->count();

        $response = $this->actingAs($this->operator)
            ->postJson('/control-room/messaging/send', [
                'content' => '',
                'alert_id' => $this->hiddenAlert->id,
            ]);

        $this->assertSame($communicationCount, Communication::query()->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $response
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['target_user_id', 'content']);
    }

    public function test_accessible_send_still_validates_content_without_writes(): void
    {
        $communicationCount = Communication::query()->count();
        $auditCount = DB::table('audit_logs')->count();

        $this->actingAs($this->operator)
            ->postJson('/control-room/messaging/send', [
                'content' => '',
                'alert_id' => $this->visibleAlert->id,
                'target_user_id' => $this->visibleStaff->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');

        $this->assertSame($communicationCount, Communication::query()->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_mark_read_hidden_or_orphaned_records_match_missing_communication_without_mutation(): void
    {
        $orphanedAlertMessage = $this->communication([
            'alert_id' => $this->visibleAlert->id,
            'target_user_id' => $this->visibleStaff->id,
            'content' => 'Orphaned alert message',
        ]);
        $orphanedTargetMessage = $this->communication([
            'alert_id' => null,
            'target_user_id' => $this->visibleStaff->id,
            'content' => 'Orphaned target message',
        ]);
        $missingAlertId = (int) ControlRoomAlert::query()->max('id') + 1000;
        $missingUserId = (int) User::query()->max('id') + 1000;
        Schema::withoutForeignKeyConstraints(function () use ($orphanedAlertMessage, $orphanedTargetMessage, $missingAlertId, $missingUserId): void {
            DB::table('control_room_communications')
                ->where('id', $orphanedAlertMessage->id)
                ->update(['alert_id' => $missingAlertId]);
            DB::table('control_room_communications')
                ->where('id', $orphanedTargetMessage->id)
                ->update(['target_user_id' => $missingUserId]);
        });
        $orphanedAlertMessage->refresh();
        $orphanedTargetMessage->refresh();
        $missingCommunicationId = (int) Communication::query()->max('id') + 1000;

        $responses = [
            $this->actingAs($this->operator)->postJson("/control-room/messaging/{$this->hiddenAlertMessage->id}/read"),
            $this->actingAs($this->operator)->postJson("/control-room/messaging/{$this->hiddenDirectMessage->id}/read"),
            $this->actingAs($this->operator)->postJson("/control-room/messaging/{$orphanedAlertMessage->id}/read"),
            $this->actingAs($this->operator)->postJson("/control-room/messaging/{$orphanedTargetMessage->id}/read"),
            $this->actingAs($this->operator)->postJson("/control-room/messaging/{$missingCommunicationId}/read"),
        ];

        foreach ($responses as $response) {
            $response->assertNotFound();
        }

        $this->assertNull($this->hiddenAlertMessage->fresh()->delivered_at);
        $this->assertNull($this->hiddenDirectMessage->fresh()->delivered_at);
        $this->assertNull($orphanedAlertMessage->fresh()->delivered_at);
        $this->assertNull($orphanedTargetMessage->fresh()->delivered_at);
    }

    public function test_global_reports_actor_retains_all_messaging_access(): void
    {
        $globalOperator = $this->roleUser(
            'coordinator',
            ['controlRoom.viewAny', 'controlRoom.alerts.manage', 'reports.viewAny'],
            'Global Control Room Operator',
        );

        $indexResponse = $this->actingAs($globalOperator)
            ->get('/control-room/messaging')
            ->assertOk();
        $props = $indexResponse->viewData('page')['props'];
        $threadIds = collect($props['threads'])->pluck('id')->all();
        $staffIds = collect($props['staff'])->pluck('id')->all();

        $this->assertContains("alert-{$this->visibleAlert->id}", $threadIds);
        $this->assertContains("alert-{$this->hiddenAlert->id}", $threadIds);
        $this->assertContains("user-{$this->visibleStaff->id}", $threadIds);
        $this->assertContains("user-{$this->hiddenStaff->id}", $threadIds);
        $this->assertContains($this->visibleStaff->id, $staffIds);
        $this->assertContains($this->hiddenStaff->id, $staffIds);

        $this->actingAs($globalOperator)
            ->getJson("/control-room/messaging/thread?alert_id={$this->hiddenAlert->id}")
            ->assertOk()
            ->assertJsonPath('messages.0.content', 'Hidden alert message');

        $this->actingAs($globalOperator)
            ->getJson("/control-room/messaging/thread?user_id={$this->hiddenStaff->id}")
            ->assertOk()
            ->assertJsonPath('messages.0.content', 'Hidden direct message');

        $this->actingAs($globalOperator)
            ->postJson('/control-room/messaging/send', [
                'content' => 'Global message to hidden operations',
                'alert_id' => $this->hiddenAlert->id,
                'target_user_id' => $this->hiddenStaff->id,
            ])
            ->assertOk()
            ->assertJsonPath('message.content', 'Global message to hidden operations');

        $this->actingAs($globalOperator)
            ->postJson("/control-room/messaging/{$this->hiddenDirectMessage->id}/read")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($this->hiddenDirectMessage->fresh()->delivered_at);
        $this->assertDatabaseHas('control_room_communications', [
            'alert_id' => $this->hiddenAlert->id,
            'target_user_id' => $this->hiddenStaff->id,
            'content' => 'Global message to hidden operations',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'controlRoom.messaging.sent',
        ]);
    }

    public function test_thread_requires_exactly_one_thread_identifier(): void
    {
        $this->actingAs($this->operator)
            ->getJson('/control-room/messaging/thread')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['alert_id', 'user_id'])
            ->assertJsonMissing(['messages' => []]);

        $this->actingAs($this->operator)
            ->getJson("/control-room/messaging/thread?alert_id={$this->visibleAlert->id}&user_id={$this->visibleStaff->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['alert_id', 'user_id'])
            ->assertJsonMissing(['content' => 'Visible alert message'])
            ->assertJsonMissing(['content' => 'Visible direct message']);
    }

    private function communication(array $attributes): Communication
    {
        return Communication::query()->create(array_merge([
            'channel' => 'in_app',
            'direction' => 'inbound',
            'purpose' => 'update',
            'status' => 'sent',
            'content' => 'Control Room message',
            'initiated_by_user_id' => $this->operator->id,
            'sent_at' => now(),
        ], $attributes));
    }

    private function siteBoundUser(
        Site $site,
        array $permissionKeys,
        string $roleName,
        string $name,
    ): User {
        $user = $this->roleUser($roleName, $permissionKeys, $name);

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    private function roleUser(string $roleName, array $permissionKeys, string $name): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $roleName],
            ['label' => str($roleName)->replace('_', ' ')->title()->toString()],
        );
        $user = User::factory()->create([
            'name' => $name,
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $user->roles()->attach($role);

        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key]);
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }

        return $user;
    }
}
