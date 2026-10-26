<?php

namespace Tests\Feature\HealthSafety;

use App\Models\TaskWatcher;
use App\Services\Tasks\TaskAggregator;
use Illuminate\Support\Facades\DB;

/** Real command/native fixture regression; exact new-source guard proposal UNAPPLIED, all executions UNRUN. */
final class ChemicalExposureTaskEscalationIsolationTest extends ChemicalExposureTaskReadTestCase
{
    public function test_real_private_overdue_row_is_not_copied_to_generic_claim_or_notifications_while_ordinary_work_is_nudged(): void
    {
        [$root, $receiver, $child] = $this->assignedWork(null, null, null, ['due_at' => '2026-10-08T01:00:00Z']);
        $this->allow($receiver, 'incidents.viewAny');
        $incident = $this->linkedIncident();
        $ordinary = $incident->followups()->create(['assigned_to_user_id' => $receiver->id, 'due_at' => now()->subDay(), 'notes' => 'Actual ordinary work']);
        TaskWatcher::create(['source' => 'chemical_exposure_followup', 'item_id' => $child->id, 'user_id' => $this->manager->id]);
        $this->assertCount(1, $this->ownTasks()->itemsFor($receiver, ['overdue' => true]));
        $this->assertCount(1, (new TaskAggregator)->itemsFor($receiver, ['sources' => ['chemical_exposure_followup'], 'overdue' => true]));
        $versionCount = DB::table('chemical_exposure_source_versions')->count();
        $this->artisan('tasks:escalate')->assertExitCode(0);
        $this->assertSame(0, DB::table('task_escalations')->where('source', 'chemical_exposure_followup')->count());
        $this->assertTrue(DB::table('task_escalations')->where('source', 'followup')->where('item_id', $ordinary->id)->where('level', 1)->exists());
        $this->assertGreaterThan(0, $receiver->notifications()->count());
        foreach (DB::table('notifications')->pluck('data') as $data) {
            $this->assertStringNotContainsString('Private follow-ups', $data);
            $this->assertStringNotContainsString('PRIVATE-', $data);
            $this->assertStringNotContainsString('2026-10-08 01:00', $data);
        }
        $this->assertSame($versionCount, DB::table('chemical_exposure_source_versions')->count());
        $this->assertNull($child->fresh()->completed_at);
    }

    public function test_repeated_generic_job_never_claims_private_due_work_and_does_not_fabricate_a_private_reminder_receipt(): void
    {
        [$root, $receiver, $child, $delivery] = $this->assignedWork(null, null, null, ['due_at' => '2026-10-08T01:00:00Z']);
        $this->assertCount(1, (new TaskAggregator)->itemsFor($receiver, ['sources' => ['chemical_exposure_followup'], 'overdue' => true]));
        $receipts = DB::table('chemical_exposure_command_receipts')->count();
        $this->artisan('tasks:escalate')->assertExitCode(0);
        $this->artisan('tasks:escalate')->assertExitCode(0);
        $this->assertSame(0, DB::table('task_escalations')->where('source', 'chemical_exposure_followup')->count());
        $this->assertSame(0, $receiver->notifications()->count());
        $this->assertSame($receipts, DB::table('chemical_exposure_command_receipts')->count());
        $this->assertSame('pending', $delivery->fresh()->state); $this->assertSame(0, $delivery->fresh()->attempts);
        $this->assertSame(1, $this->ownTasks()->navigationBadgeFor($receiver)['badge']);
        // Source-authorized private overdue reminders remain REQUIRED owner work; this guard is not their delivery proof.
    }
}
