<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\AlertDiscussion;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\AlertWatcher;
use App\Models\ControlRoom\EvidenceItem;
use App\Models\ControlRoom\EvidencePack;
use App\Models\ControlRoom\TimeEntry;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ControlRoomNestedRecordAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Site $visibleSite;

    private Site $hiddenSite;

    private User $operator;

    private ControlRoomAlert $visibleAlert;

    private ControlRoomAlert $hiddenAlert;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->visibleSite = Site::factory()->create(['type' => 'house']);
        $this->hiddenSite = Site::factory()->create(['type' => 'house']);
        $this->operator = $this->siteBoundUser($this->visibleSite, [
            'controlRoom.viewAny',
            'controlRoom.alerts.manage',
        ]);
        $this->visibleAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->visibleSite->id,
        ]);
        $this->hiddenAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->hiddenSite->id,
        ]);
    }

    #[DataProvider('deniedNestedOperations')]
    public function test_site_bound_operator_cannot_access_another_sites_nested_alert_route(string $operation): void
    {
        [$method, $uri, $payload, $expectsNoFileResponse] = $this->prepareDeniedOperation($operation);
        $before = $this->snapshotAlertData($this->hiddenAlert);

        $response = $this->dispatch($method, $uri, $payload);

        $this->assertSame($before, $this->snapshotAlertData($this->hiddenAlert));
        if ($expectsNoFileResponse) {
            $this->assertFalse($response->headers->has('content-disposition'));
        }
        $response->assertForbidden();
    }

    public static function deniedNestedOperations(): array
    {
        return [
            'task index' => ['task_index'],
            'task store' => ['task_store'],
            'task update' => ['task_update'],
            'task status' => ['task_status'],
            'task destroy' => ['task_destroy'],
            'task reorder' => ['task_reorder'],
            'evidence index' => ['evidence_index'],
            'evidence pack store' => ['evidence_pack_store'],
            'evidence item store' => ['evidence_item_store'],
            'evidence item destroy' => ['evidence_item_destroy'],
            'evidence item download' => ['evidence_item_download'],
            'evidence pack complete' => ['evidence_pack_complete'],
            'evidence pack export' => ['evidence_pack_export'],
            'discussion index' => ['discussion_index'],
            'discussion store' => ['discussion_store'],
            'discussion update' => ['discussion_update'],
            'discussion destroy' => ['discussion_destroy'],
            'watcher index' => ['watcher_index'],
            'watcher store' => ['watcher_store'],
            'watcher toggle' => ['watcher_toggle'],
            'watcher destroy' => ['watcher_destroy'],
            'time entry index' => ['time_entry_index'],
            'time entry start' => ['time_entry_start'],
            'time entry store' => ['time_entry_store'],
            'time entry stop' => ['time_entry_stop'],
            'time entry destroy' => ['time_entry_destroy'],
        ];
    }

    #[DataProvider('crossAlertForeignKeys')]
    public function test_cross_alert_foreign_keys_are_rejected(string $operation, string $field): void
    {
        $otherAlert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->visibleSite->id,
        ]);
        [$method, $uri, $payload] = $this->prepareCrossAlertInjection($operation, $otherAlert);
        $before = $this->snapshotAlertData($this->visibleAlert);

        $response = $this->dispatchJson($method, $uri, $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->snapshotAlertData($this->visibleAlert));
    }

    public static function crossAlertForeignKeys(): array
    {
        return [
            'task parent on store' => ['task_parent_store', 'parent_task_id'],
            'task parent on update' => ['task_parent_update', 'parent_task_id'],
            'discussion parent' => ['discussion_parent', 'parent_id'],
            'manual time entry task' => ['time_entry_task', 'task_id'],
        ];
    }

    #[DataProvider('outOfScopeRecipients')]
    public function test_out_of_scope_nested_record_recipients_are_rejected(string $operation): void
    {
        $recipient = $this->siteBoundUser(
            $this->hiddenSite,
            [],
            str_starts_with($operation, 'task_assignee') ? 'coordinator' : 'support_worker',
        );
        [$method, $uri, $payload] = $this->prepareOutOfScopeRecipient($operation, $recipient);
        $before = $this->snapshotAlertData($this->visibleAlert);

        $response = $this->dispatchJson($method, $uri, $payload);

        $response->assertForbidden();
        $this->assertSame($before, $this->snapshotAlertData($this->visibleAlert));
    }

    public static function outOfScopeRecipients(): array
    {
        return [
            'task assignee on store' => ['task_assignee_store'],
            'task assignee on update' => ['task_assignee_update'],
            'watcher' => ['watcher_store'],
            'discussion mention' => ['discussion_mention'],
        ];
    }

    public function test_multi_site_operator_can_use_visible_recipients_and_mixed_hidden_recipients_are_rejected_atomically(): void
    {
        $thirdSite = Site::factory()->create(['type' => 'house']);
        $operatorProfile = HrEmployeeProfile::query()
            ->where('user_id', $this->operator->id)
            ->firstOrFail();
        $operatorProfile->update(['secondary_site_ids' => [$this->hiddenSite->id]]);
        $this->operator->unsetRelation('hrEmployeeProfile');

        $visibleStaff = $this->siteBoundUser($this->visibleSite, [], 'support_worker');
        $secondarySiteStaff = $this->siteBoundUser($this->hiddenSite, [], 'coordinator');
        $inaccessibleStaff = $this->siteBoundUser($thirdSite, [], 'coordinator');

        $this->actingAs($this->operator)
            ->post("/control-room/alerts/{$this->visibleAlert->id}/tasks", [
                'title' => 'Secondary-site assignee',
                'priority' => 'medium',
                'assigned_to_user_id' => $secondarySiteStaff->id,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('control_room_alert_tasks', [
            'alert_id' => $this->visibleAlert->id,
            'assigned_to_user_id' => $secondarySiteStaff->id,
        ]);

        $this->actingAs($this->operator)
            ->postJson("/control-room/alerts/{$this->visibleAlert->id}/watchers", [
                'user_id' => $secondarySiteStaff->id,
            ])
            ->assertCreated();

        $this->actingAs($this->operator)
            ->postJson("/control-room/alerts/{$this->visibleAlert->id}/discussions", [
                'content' => 'Both accessible sites can be mentioned.',
                'mentions' => [$visibleStaff->id, $secondarySiteStaff->id],
            ])
            ->assertCreated();

        $taskCount = AlertTask::query()->where('alert_id', $this->visibleAlert->id)->count();
        $watcherCount = AlertWatcher::query()->where('alert_id', $this->visibleAlert->id)->count();
        $discussionCount = AlertDiscussion::query()->where('alert_id', $this->visibleAlert->id)->count();
        $watchersAggregate = $this->visibleAlert->fresh()->watchers_count;
        $auditCount = DB::table('audit_logs')->count();

        $this->actingAs($this->operator)
            ->postJson("/control-room/alerts/{$this->visibleAlert->id}/tasks", [
                'title' => 'Inaccessible assignee',
                'priority' => 'medium',
                'assigned_to_user_id' => $inaccessibleStaff->id,
            ])
            ->assertForbidden();
        $this->actingAs($this->operator)
            ->postJson("/control-room/alerts/{$this->visibleAlert->id}/watchers", [
                'user_id' => $inaccessibleStaff->id,
            ])
            ->assertForbidden();
        $this->actingAs($this->operator)
            ->postJson("/control-room/alerts/{$this->visibleAlert->id}/discussions", [
                'content' => 'Mixed recipient list must be atomic.',
                'mentions' => [$secondarySiteStaff->id, $inaccessibleStaff->id],
            ])
            ->assertForbidden();

        $this->assertSame($taskCount, AlertTask::query()->where('alert_id', $this->visibleAlert->id)->count());
        $this->assertSame($watcherCount, AlertWatcher::query()->where('alert_id', $this->visibleAlert->id)->count());
        $this->assertSame($discussionCount, AlertDiscussion::query()->where('alert_id', $this->visibleAlert->id)->count());
        $this->assertSame($watchersAggregate, $this->visibleAlert->fresh()->watchers_count);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_global_reports_actor_retains_parent_and_child_record_access(): void
    {
        $globalOperator = $this->roleUser('coordinator', [
            'controlRoom.viewAny',
            'controlRoom.alerts.manage',
            'reports.viewAny',
        ]);
        $task = $this->taskFor($this->hiddenAlert, $globalOperator);
        $pack = $this->packFor($this->hiddenAlert, $globalOperator);
        Storage::disk('local')->put('evidence/global-proof.txt', 'global proof');
        $item = EvidenceItem::query()->create([
            'evidence_pack_id' => $pack->id,
            'type' => 'document',
            'title' => 'global-proof.txt',
            'storage_path' => 'evidence/global-proof.txt',
            'mime_type' => 'text/plain',
            'file_size' => 12,
            'captured_at' => now(),
            'captured_by_user_id' => $globalOperator->id,
        ]);

        $this->actingAs($globalOperator)
            ->getJson("/control-room/alerts/{$this->hiddenAlert->id}/tasks")
            ->assertOk()
            ->assertJsonCount(1, 'tasks');

        $this->actingAs($globalOperator)
            ->post("/control-room/tasks/{$task->id}/status", ['status' => 'completed'])
            ->assertRedirect();

        $this->actingAs($globalOperator)
            ->get("/control-room/evidence/items/{$item->id}/download")
            ->assertOk()
            ->assertDownload('global-proof.txt');

        $this->assertSame('completed', $task->fresh()->status);
    }

    private function prepareDeniedOperation(string $operation): array
    {
        $alertId = $this->hiddenAlert->id;

        return match ($operation) {
            'task_index' => ['GET', "/control-room/alerts/{$alertId}/tasks", [], false],
            'task_store' => ['POST', "/control-room/alerts/{$alertId}/tasks", [
                'title' => 'Forbidden task',
                'priority' => 'high',
            ], false],
            'task_update' => $this->taskOperation('PUT', 'tasks', ['title' => 'Forbidden edit']),
            'task_status' => $this->taskOperation('POST', 'tasks', ['status' => 'completed'], '/status'),
            'task_destroy' => $this->taskOperation('DELETE', 'tasks'),
            'task_reorder' => $this->prepareDeniedTaskReorder(),
            'evidence_index' => ['GET', "/control-room/alerts/{$alertId}/evidence", [], false],
            'evidence_pack_store' => ['POST', "/control-room/alerts/{$alertId}/evidence", [
                'title' => 'Forbidden pack',
            ], false],
            'evidence_item_store' => $this->evidencePackOperation('POST', 'items', [
                'item_type' => 'note',
                'content' => 'Forbidden evidence',
            ]),
            'evidence_item_destroy' => $this->evidenceItemOperation('DELETE'),
            'evidence_item_download' => $this->evidenceItemOperation('GET', true),
            'evidence_pack_complete' => $this->evidencePackOperation('POST', 'complete'),
            'evidence_pack_export' => $this->evidencePackOperation('GET', 'export', [], true),
            'discussion_index' => ['GET', "/control-room/alerts/{$alertId}/discussions", [], false],
            'discussion_store' => ['POST', "/control-room/alerts/{$alertId}/discussions", [
                'content' => 'Forbidden discussion',
            ], false],
            'discussion_update' => $this->discussionOperation('PUT', ['content' => 'Forbidden edit']),
            'discussion_destroy' => $this->discussionOperation('DELETE'),
            'watcher_index' => ['GET', "/control-room/alerts/{$alertId}/watchers", [], false],
            'watcher_store' => ['POST', "/control-room/alerts/{$alertId}/watchers", [
                'user_id' => User::factory()->create()->id,
            ], false],
            'watcher_toggle' => ['POST', "/control-room/alerts/{$alertId}/watchers/toggle", [], false],
            'watcher_destroy' => $this->prepareDeniedWatcherDestroy(),
            'time_entry_index' => ['GET', "/control-room/alerts/{$alertId}/time-entries", [], false],
            'time_entry_start' => ['POST', "/control-room/alerts/{$alertId}/time-entries/start", [], false],
            'time_entry_store' => ['POST', "/control-room/alerts/{$alertId}/time-entries", [
                'duration_minutes' => 15,
            ], false],
            'time_entry_stop' => $this->timeEntryOperation('POST', true),
            'time_entry_destroy' => $this->timeEntryOperation('DELETE', false),
            default => throw new \InvalidArgumentException("Unknown denied operation [{$operation}]."),
        };
    }

    private function taskOperation(string $method, string $segment, array $payload = [], string $suffix = ''): array
    {
        $task = $this->taskFor($this->hiddenAlert, $this->operator);

        return [$method, "/control-room/{$segment}/{$task->id}{$suffix}", $payload, false];
    }

    private function prepareDeniedTaskReorder(): array
    {
        $first = $this->taskFor($this->hiddenAlert, $this->operator, ['sort_order' => 1]);
        $second = $this->taskFor($this->hiddenAlert, $this->operator, ['sort_order' => 2]);

        return ['POST', "/control-room/alerts/{$this->hiddenAlert->id}/tasks/reorder", [
            'task_ids' => [$second->id, $first->id],
        ], false];
    }

    private function evidencePackOperation(
        string $method,
        string $suffix,
        array $payload = [],
        bool $expectsNoFileResponse = false,
    ): array {
        $pack = $this->packFor($this->hiddenAlert, $this->operator);

        return [$method, "/control-room/evidence/{$pack->id}/{$suffix}", $payload, $expectsNoFileResponse];
    }

    private function evidenceItemOperation(string $method, bool $download = false): array
    {
        $pack = $this->packFor($this->hiddenAlert, $this->operator);
        $path = 'evidence/hidden-proof.txt';
        Storage::disk('local')->put($path, 'hidden proof');
        $item = EvidenceItem::query()->create([
            'evidence_pack_id' => $pack->id,
            'type' => 'document',
            'title' => 'hidden-proof.txt',
            'storage_path' => $path,
            'mime_type' => 'text/plain',
            'file_size' => 12,
            'captured_at' => now(),
            'captured_by_user_id' => $this->operator->id,
        ]);
        $suffix = $download ? '/download' : '';

        return [$method, "/control-room/evidence/items/{$item->id}{$suffix}", [], $download];
    }

    private function discussionOperation(string $method, array $payload = []): array
    {
        $discussion = AlertDiscussion::query()->create([
            'alert_id' => $this->hiddenAlert->id,
            'user_id' => $this->operator->id,
            'content' => 'Hidden discussion',
            'type' => 'comment',
            'is_internal' => true,
        ]);

        return [$method, "/control-room/discussions/{$discussion->id}", $payload, false];
    }

    private function prepareDeniedWatcherDestroy(): array
    {
        $watcher = User::factory()->create();
        AlertWatcher::query()->create([
            'alert_id' => $this->hiddenAlert->id,
            'user_id' => $watcher->id,
            'added_by_user_id' => $this->operator->id,
        ]);
        $this->hiddenAlert->update(['watchers_count' => 1]);

        return ['DELETE', "/control-room/alerts/{$this->hiddenAlert->id}/watchers/{$watcher->id}", [], false];
    }

    private function timeEntryOperation(string $method, bool $running): array
    {
        $entry = TimeEntry::query()->create([
            'alert_id' => $this->hiddenAlert->id,
            'user_id' => $this->operator->id,
            'started_at' => now()->subMinutes(10),
            'ended_at' => $running ? null : now(),
            'duration_minutes' => $running ? 0 : 10,
        ]);

        return [$method, "/control-room/time-entries/{$entry->id}".($running ? '/stop' : ''), [], false];
    }

    private function prepareCrossAlertInjection(string $operation, ControlRoomAlert $otherAlert): array
    {
        return match ($operation) {
            'task_parent_store' => $this->crossAlertTaskParentStore($otherAlert),
            'task_parent_update' => $this->crossAlertTaskParentUpdate($otherAlert),
            'discussion_parent' => $this->crossAlertDiscussionParent($otherAlert),
            'time_entry_task' => $this->crossAlertTimeEntryTask($otherAlert),
            default => throw new \InvalidArgumentException("Unknown injection operation [{$operation}]."),
        };
    }

    private function crossAlertTaskParentStore(ControlRoomAlert $otherAlert): array
    {
        $parent = $this->taskFor($otherAlert, $this->operator);

        return ['POST', "/control-room/alerts/{$this->visibleAlert->id}/tasks", [
            'title' => 'Injected child',
            'priority' => 'medium',
            'parent_task_id' => $parent->id,
        ]];
    }

    private function crossAlertTaskParentUpdate(ControlRoomAlert $otherAlert): array
    {
        $task = $this->taskFor($this->visibleAlert, $this->operator);
        $parent = $this->taskFor($otherAlert, $this->operator);

        return ['PUT', "/control-room/tasks/{$task->id}", ['parent_task_id' => $parent->id]];
    }

    private function crossAlertDiscussionParent(ControlRoomAlert $otherAlert): array
    {
        $parent = AlertDiscussion::query()->create([
            'alert_id' => $otherAlert->id,
            'user_id' => $this->operator->id,
            'content' => 'Other alert parent',
            'type' => 'comment',
            'is_internal' => true,
        ]);

        return ['POST', "/control-room/alerts/{$this->visibleAlert->id}/discussions", [
            'content' => 'Injected reply',
            'parent_id' => $parent->id,
        ]];
    }

    private function crossAlertTimeEntryTask(ControlRoomAlert $otherAlert): array
    {
        $task = $this->taskFor($otherAlert, $this->operator);

        return ['POST', "/control-room/alerts/{$this->visibleAlert->id}/time-entries", [
            'duration_minutes' => 15,
            'task_id' => $task->id,
        ]];
    }

    private function prepareOutOfScopeRecipient(string $operation, User $recipient): array
    {
        return match ($operation) {
            'task_assignee_store' => ['POST', "/control-room/alerts/{$this->visibleAlert->id}/tasks", [
                'title' => 'Scoped assignment',
                'priority' => 'medium',
                'assigned_to_user_id' => $recipient->id,
            ]],
            'task_assignee_update' => $this->outOfScopeTaskUpdate($recipient),
            'watcher_store' => ['POST', "/control-room/alerts/{$this->visibleAlert->id}/watchers", [
                'user_id' => $recipient->id,
            ]],
            'discussion_mention' => ['POST', "/control-room/alerts/{$this->visibleAlert->id}/discussions", [
                'content' => 'Scoped mention',
                'mentions' => [$recipient->id],
            ]],
            default => throw new \InvalidArgumentException("Unknown recipient operation [{$operation}]."),
        };
    }

    private function outOfScopeTaskUpdate(User $recipient): array
    {
        $task = $this->taskFor($this->visibleAlert, $this->operator);

        return ['PUT', "/control-room/tasks/{$task->id}", [
            'assigned_to_user_id' => $recipient->id,
        ]];
    }

    private function taskFor(ControlRoomAlert $alert, User $creator, array $attributes = []): AlertTask
    {
        return AlertTask::query()->create(array_merge([
            'alert_id' => $alert->id,
            'title' => 'Alert task',
            'priority' => 'medium',
            'status' => 'open',
            'created_by_user_id' => $creator->id,
            'sort_order' => 1,
        ], $attributes));
    }

    private function packFor(ControlRoomAlert $alert, User $creator): EvidencePack
    {
        return EvidencePack::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Evidence pack',
            'status' => 'collecting',
            'item_count' => 0,
            'created_by_user_id' => $creator->id,
        ]);
    }

    private function snapshotAlertData(ControlRoomAlert $alert): array
    {
        $packIds = EvidencePack::query()->where('alert_id', $alert->id)->pluck('id');

        return [
            'alert' => $alert->fresh()->only(['watchers_count', 'time_spent_minutes']),
            'tasks' => AlertTask::query()->where('alert_id', $alert->id)->orderBy('id')->get()->toArray(),
            'packs' => EvidencePack::query()->where('alert_id', $alert->id)->orderBy('id')->get()->toArray(),
            'items' => EvidenceItem::query()->whereIn('evidence_pack_id', $packIds)->orderBy('id')->get()->toArray(),
            'discussions' => AlertDiscussion::query()->where('alert_id', $alert->id)->orderBy('id')->get()->toArray(),
            'watchers' => AlertWatcher::query()->where('alert_id', $alert->id)->orderBy('id')->get()->toArray(),
            'time_entries' => TimeEntry::query()->where('alert_id', $alert->id)->orderBy('id')->get()->toArray(),
            'audit_count' => DB::table('audit_logs')->count(),
            'files' => collect(Storage::disk('local')->allFiles())->sort()->values()->all(),
        ];
    }

    private function dispatch(string $method, string $uri, array $payload)
    {
        $this->actingAs($this->operator);

        return match ($method) {
            'GET' => $this->get($uri),
            'POST' => $this->post($uri, $payload),
            'PUT' => $this->put($uri, $payload),
            'DELETE' => $this->delete($uri, $payload),
            default => throw new \InvalidArgumentException("Unknown method [{$method}]."),
        };
    }

    private function dispatchJson(string $method, string $uri, array $payload)
    {
        $this->actingAs($this->operator);

        return match ($method) {
            'POST' => $this->postJson($uri, $payload),
            'PUT' => $this->putJson($uri, $payload),
            default => throw new \InvalidArgumentException("Unknown JSON method [{$method}]."),
        };
    }

    private function siteBoundUser(
        Site $site,
        array $permissionKeys,
        string $roleName = 'coordinator',
    ): User {
        $user = $this->roleUser($roleName, $permissionKeys);

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    private function roleUser(string $roleName, array $permissionKeys): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $roleName],
            ['label' => str($roleName)->replace('_', ' ')->title()->toString()],
        );
        $user = User::factory()->create([
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
