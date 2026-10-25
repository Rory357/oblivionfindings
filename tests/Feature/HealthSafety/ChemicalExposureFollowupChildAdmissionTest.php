<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\User;
use App\Notifications\HealthSafety\ChemicalExposureFollowupAssignedNotification;
use App\Services\HealthSafety\ChemicalExposureFollowupDestinationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/** Whole real-source consuming-order gate; Source preflight and layered consumer patch required. UNRUN. */
final class ChemicalExposureFollowupChildAdmissionTest extends ChemicalExposureTaskReadTestCase
{
    private function foreignAcceptance(ChemicalExposureFollowup $target, string $family): array
    {
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $this->worker->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $foreignReceiver = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view'], $this->foreignSite);
        $root = $this->exposure(['site_id' => $this->foreignSite->id]);
        [, , $foreign] = $this->assignedWork(null, $foreignReceiver, $root);
        $version = ChemicalExposureSourceVersion::where('source_type', 'followup')->where('source_id', $foreign->id)->sole();
        $table = $family === 'version' ? 'chemical_exposure_source_versions' : 'chemical_exposure_command_receipts';
        $row = $family === 'version' ? DB::table($table)->where('id', $version->id)
            : DB::table($table)->where('source_version_id', $version->id);
        $before = (array) $row->sole();
        DB::table($table)->where('id', $before['id'])->update(['source_id' => $target->id]);
        return [$table, $before['id'], $before['source_id']];
    }

    private function restoreForeign(array $restore): void
    {
        [$table, $id, $sourceId] = $restore;
        DB::table($table)->where('id', $id)->update(['source_id' => $sourceId]);
    }

    private function recordChildBodies(array &$bodies): void
    {
        ChemicalExposureFollowup::retrieved(function ($row) use (&$bodies): void {
            if (array_key_exists('instruction', $row->getAttributes())) { $bodies[] = (int) $row->id; }
        });
    }

    private function deliver(User $viewer, ChemicalExposureSourceDelivery $delivery): DatabaseNotification
    {
        app(ChemicalExposureFollowupDestinationService::class)->deliverForViewer($this->manager, $delivery->id);
        return DatabaseNotification::where('type', ChemicalExposureFollowupAssignedNotification::class)
            ->where('notifiable_id', $viewer->id)->where('data->delivery_id', $delivery->id)->sole();
    }

    public function test_tasks_reject_retained_foreign_version_and_receipt_before_child_body_and_keep_healthy_neighbor(): void
    {
        [$root, $viewer, $child] = $this->assignedWork();
        [, , $healthy] = $this->assignedWork(null, $viewer);
        foreach (['version', 'receipt'] as $family) {
            $restore = $this->foreignAcceptance($child, $family); $bodies = [];
            $this->recordChildBodies($bodies);
            try {
                $this->assertSame(['chemical_exposure_followup-'.$healthy->id], array_column($this->ownTasks()->itemsFor($viewer), 'id'));
                $this->assertNotContains($child->id, $bodies, 'Foreign retained kind/ID ownership must refuse before instruction/name hydration.');
            } finally { ChemicalExposureFollowup::flushEventListeners(); $this->restoreForeign($restore); }
            $this->assertCount(2, $this->ownTasks()->itemsFor($viewer));
        }
    }

    public function test_first_full_child_fetch_matches_preflight_markers_after_actual_identity_read_interleaving(): void
    {
        [$root, $viewer, $child] = $this->assignedWork(); [, , $healthy] = $this->assignedWork(null, $viewer);
        $before = (array) DB::table('chemical_exposure_followups')->where('id', $child->id)->sole();
        $identityReads = 0; $mutated = false; $bodies = [];
        ChemicalExposureFollowup::retrieved(function ($row) use ($child, &$identityReads, &$mutated, &$bodies): void {
            if ((int) $row->id !== (int) $child->id) { return; }
            if (array_key_exists('instruction', $row->getAttributes())) { $bodies[] = (int) $row->id; return; }
            if (array_key_exists('source_schema_version', $row->getAttributes()) && ++$identityReads === 2) {
                // Initial candidate, then canonical preflight identity. Mutate after that second
                // snapshot is materialized so the exact marker-conditional body query is essential.
                $mutated = true; DB::table('chemical_exposure_followups')->where('id', $child->id)
                    ->update(['source_schema_version' => 0, 'source_revision' => 0]);
            }
        });
        try {
            $this->assertSame(['chemical_exposure_followup-'.$healthy->id], array_column($this->ownTasks()->itemsFor($viewer), 'id'));
            $this->assertTrue($mutated); $this->assertSame([], $bodies);
        } finally {
            ChemicalExposureFollowup::flushEventListeners();
            DB::table('chemical_exposure_followups')->where('id', $child->id)->update($before);
        }
        $this->assertCount(2, $this->ownTasks()->itemsFor($viewer));
    }

    public function test_reproof_after_fingerprint_capture_admits_identity_again_before_second_child_body(): void
    {
        [$root, $viewer, $child] = $this->assignedWork(); [, , $healthy] = $this->assignedWork(null, $viewer);
        $version = ChemicalExposureSourceVersion::where('source_type', 'followup')->where('source_id', $child->id)->sole();
        $before = (array) DB::table('chemical_exposure_followups')->where('id', $child->id)->sole();
        $mutated = false; $lateBodies = [];
        ChemicalExposureSourceVersion::retrieved(function ($row) use ($version, $child, &$mutated): void {
            if (! $mutated && (int) $row->id === (int) $version->id && array_key_exists('task_fingerprint', $row->getAttributes())) {
                $mutated = true; DB::table('chemical_exposure_followups')->where('id', $child->id)->update([
                    'source_schema_version' => 0, 'source_revision' => 0, 'instruction' => 'PRIVATE-UNACCEPTED-LATE-BODY']);
            }
        });
        ChemicalExposureFollowup::retrieved(function ($row) use ($child, &$mutated, &$lateBodies): void {
            if ($mutated && (int) $row->id === (int) $child->id && array_key_exists('instruction', $row->getAttributes())) {
                $lateBodies[] = (int) $row->id;
            }
        });
        try {
            $this->assertSame(['chemical_exposure_followup-'.$healthy->id], array_column($this->ownTasks()->itemsFor($viewer), 'id'));
            $this->assertTrue($mutated); $this->assertSame([], $lateBodies);
        } finally {
            ChemicalExposureFollowup::flushEventListeners(); ChemicalExposureSourceVersion::flushEventListeners();
            DB::table('chemical_exposure_followups')->where('id', $child->id)->update($before);
        }
        $this->assertCount(2, $this->ownTasks()->itemsFor($viewer));
    }

    public function test_actual_delivery_refuses_retained_foreign_child_acceptance_before_body_or_inbox_persistence(): void
    {
        [$root, $viewer, $child, $delivery] = $this->assignedWork();
        $before = (array) DB::table('chemical_exposure_source_deliveries')->where('id', $delivery->id)->sole();
        foreach (['version', 'receipt'] as $family) {
            $restore = $this->foreignAcceptance($child, $family); $bodies = [];
            $this->recordChildBodies($bodies);
            try {
                $this->integrity(fn () => app(ChemicalExposureFollowupDestinationService::class)->deliverForViewer($this->manager, $delivery->id));
                $this->assertNotContains($child->id, $bodies);
                $this->assertFalse(DatabaseNotification::where('type', ChemicalExposureFollowupAssignedNotification::class)
                    ->where('data->delivery_id', $delivery->id)->exists());
                $this->assertSame($before, (array) DB::table('chemical_exposure_source_deliveries')->where('id', $delivery->id)->sole());
            } finally { ChemicalExposureFollowup::flushEventListeners(); $this->restoreForeign($restore); }
        }
        $this->assertSame($viewer->id, (int) $this->deliver($viewer, $delivery)->notifiable_id);
    }

    public function test_actual_notice_admission_refuses_before_child_body_and_preserves_other_real_private_notice(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s private follow-up notice reader is not mounted: the notification centre (BoardPackAccessService::visibleNotificationQuery) refuses an H&S read context. Today nothing in the app writes these notices (only the unmounted FollowupDestinationService::deliverForViewer does), so a follow-up assignee gets no notice, only the task in /tasks, and nobody can see one.', 'Job 3: mount the follow-up inbox delivery together with its notification-centre reader, never the writer alone.'));
        [$root, $viewer, $child, $delivery] = $this->assignedWork(); $notice = $this->deliver($viewer, $delivery);
        [, , $healthy, $healthyDelivery] = $this->assignedWork(null, $viewer); $healthyNotice = $this->deliver($viewer, $healthyDelivery);
        foreach (['version', 'receipt'] as $family) {
            $restore = $this->foreignAcceptance($child, $family); $bodies = [];
            $this->recordChildBodies($bodies);
            try {
                $ids = app(\App\Services\HealthSafety\ChemicalExposureNotificationAccessService::class)->materialize($viewer,
                    fn ($current, $context) => app(\App\Domain\Governance\Services\BoardPackAccessService::class)
                        ->visibleNotificationQuery($current, false, $context)->pluck('notifications.id')->all());
                $this->assertSame([$healthyNotice->id], $ids);
                $this->assertNotContains($child->id, $bodies);
                $this->assertNull($notice->fresh()->read_at);
            } finally { ChemicalExposureFollowup::flushEventListeners(); $this->restoreForeign($restore); }
        }
        $this->assertEqualsCanonicalizing([$notice->id, $healthyNotice->id], app(\App\Services\HealthSafety\ChemicalExposureNotificationAccessService::class)
            ->materialize($viewer, fn ($current, $context) => app(\App\Domain\Governance\Services\BoardPackAccessService::class)
                ->visibleNotificationQuery($current, false, $context)->pluck('notifications.id')->all()));
    }
}
