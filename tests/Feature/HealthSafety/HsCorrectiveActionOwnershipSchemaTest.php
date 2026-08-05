<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HsCorrectiveActionOwnershipSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_task_and_attachment_relationships_are_reciprocal(): void
    {
        $this->assertTrue(Schema::hasColumn('hs_corrective_actions', 'source_control_room_task_id'));

        $task = $this->alertTask();
        $action = HsCorrectiveAction::factory()->create([
            'source_control_room_task_id' => $task->id,
        ]);
        $attachment = $action->attachments()->create([
            'uploaded_by' => User::factory()->create()->id,
            'original_name' => 'completion-photo.jpg',
            'path' => 'health-safety/corrective-actions/completion-photo.jpg',
            'disk' => 'private',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 4096,
        ]);

        $this->assertTrue($action->sourceControlRoomTask->is($task));
        $this->assertTrue($task->fresh()->transferredCorrectiveAction->is($action));
        $this->assertTrue($attachment->attachable->is($action));
    }

    public function test_reciprocal_source_link_is_preferred_over_the_legacy_pointer(): void
    {
        $task = $this->alertTask();
        $legacyAction = HsCorrectiveAction::factory()->create();
        $reciprocalAction = HsCorrectiveAction::factory()->create([
            'source_control_room_task_id' => $task->id,
        ]);
        $task->forceFill([
            'transferred_to_hs_corrective_action_id' => $legacyAction->id,
        ])->save();

        $this->assertTrue($task->fresh()->transferredCorrectiveAction->is($reciprocalAction));
    }

    public function test_legacy_transfer_pointer_remains_readable(): void
    {
        $task = $this->alertTask();
        $legacyAction = HsCorrectiveAction::factory()->create();
        $task->forceFill([
            'transferred_to_hs_corrective_action_id' => $legacyAction->id,
        ])->save();

        $this->assertTrue($task->fresh()->transferredCorrectiveAction->is($legacyAction));
    }

    public function test_one_control_room_task_cannot_source_two_corrective_actions(): void
    {
        $task = $this->alertTask();
        HsCorrectiveAction::factory()->create([
            'source_control_room_task_id' => $task->id,
        ]);

        $this->expectException(QueryException::class);

        HsCorrectiveAction::factory()->create([
            'source_control_room_task_id' => $task->id,
        ]);
    }

    private function alertTask(): AlertTask
    {
        return AlertTask::query()->create([
            'alert_id' => ControlRoomAlert::factory()->create()->id,
            'title' => 'Replace unsafe bathroom rail',
            'status' => AlertTask::STATUS_IN_PROGRESS,
            'priority' => 'high',
        ]);
    }
}
