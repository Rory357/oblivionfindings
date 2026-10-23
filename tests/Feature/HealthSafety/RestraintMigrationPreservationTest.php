<?php

namespace Tests\Feature\HealthSafety;

use App\Models\RestraintEvent;
use App\Models\RestraintEventAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RestraintMigrationPreservationTest extends RestraintEventCommandTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_10_08_180700_add_restraint_event_source_commands.php');
    }

    public function test_legacy_capture_review_attribution_and_null_plan_are_not_backfilled_as_approval_or_ownership(): void
    {
        $row = RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'reviewed_by' => $this->actor->id, 'reviewed_at' => now()->subDay(), 'behaviour_support_plan_id' => null]));
        $this->assertNull($row->fresh()->source_schema_version);
        $this->assertNull($row->fresh()->plan_authorization_snapshot);
        $this->assertNull($row->fresh()->review_owner_id);
        $this->assertNull($row->fresh()->reviewed_source_revision);
        $this->assertSame($this->actor->id, $row->fresh()->reviewed_by);
    }

    public function test_each_metadata_only_event_state_refuses_down_before_any_destructive_change(): void
    {
        $row = RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id]));
        foreach (['source_schema_version' => 0, 'creation_fingerprint' => '', 'recorded_client_id' => 0, 'recorded_site_id' => 0, 'recorded_stay_id' => 0, 'behaviour_support_plan_version_id' => 0, 'plan_authorization_state' => '', 'plan_authorization_snapshot' => [], 'review_owner_id' => 0, 'reviewed_source_revision' => 0, 'review_assignment_revision' => 1, 'source_revision' => 2, 'lock_version' => 2] as $field => $value) {
            $original = $row->fresh()->getRawOriginal($field);
            $row->forceFill([$field => $value])->saveQuietly();
            try {
                $this->migration()->down();
                $this->fail('Meaningful metadata must prevent rollback.');
            } catch (RuntimeException) {
                $this->assertTrue(Schema::hasTable('restraint_command_receipts'));
                $this->assertSame($value, $row->fresh()->{$field});
            }
            DB::table('restraint_events')->where('id', $row->id)->update([$field => $original]);
        }
    }

    public function test_receipts_review_and_delivery_provenance_make_down_refuse_without_erasing_source(): void
    {
        $row = $this->event();
        $before = $row->fresh()->getAttributes();
        try {
            $this->migration()->down();
            $this->fail('Accepted source history must be retained.');
        } catch (RuntimeException) {
            $this->assertSame($before, $row->fresh()->getAttributes());
            $this->assertDatabaseCount('restraint_command_receipts', 1);
            $this->assertDatabaseCount('restraint_source_deliveries', 2);
        }
    }

    public function test_evidence_empty_json_and_zero_revision_are_retained_without_public_reopening(): void
    {
        $row = RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id]));
        $a = RestraintEventAttachment::create(['restraint_event_id' => $row->id, 'disk' => 'private', 'path' => 'restraint_attachments/missing', 'original_name' => 'legacy', 'uploader_snapshot' => [], 'source_revision' => 0]);
        try {
            $this->migration()->down();
            $this->fail('Empty retained evidence is still provenance.');
        } catch (RuntimeException) {
            $this->assertSame([], $a->fresh()->uploader_snapshot);
            $this->assertSame(0, $a->fresh()->source_revision);
            $this->assertSame('private', $a->fresh()->disk);
        }
    }
}
