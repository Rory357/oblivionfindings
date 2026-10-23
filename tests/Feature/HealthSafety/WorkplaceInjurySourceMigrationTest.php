<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ReturnToWorkPlan;
use App\Models\User;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjury;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class WorkplaceInjurySourceMigrationTest extends WorkplaceInjurySourceTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_10_12_190000_create_workplace_injury_source_history.php');
    }

    public function test_rollback_refuses_persisted_command_and_immutable_source_evidence(): void
    {
        $injury = $this->injury();
        try { $this->migration()->down(); $this->fail('Independent source evidence must be retained.'); }
        catch (LogicException $error) { $this->assertStringContainsString('evidence', $error->getMessage()); }
        $this->assertTrue(Schema::hasTable('workplace_injury_source_versions'));
        $this->assertSame(1, $injury->fresh()->source_revision);
    }

    public function test_rollback_refuses_empty_json_attribution_even_without_history_rows(): void
    {
        $injury = WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->create([
            'user_id' => $this->worker->id, 'site_id' => $this->site->id]));
        $assessment = WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $injury->id, 'user_id' => $this->manager->id, 'worker_snapshot' => []]));
        try { $this->migration()->down(); $this->fail('Empty retained JSON is present attribution evidence.'); }
        catch (LogicException $error) { $this->assertStringContainsString('attribution', $error->getMessage()); }
        $this->assertSame([], $assessment->fresh()->worker_snapshot);
        $this->assertSame($this->manager->id, (int) $assessment->fresh()->user_id);
    }

    public function test_additive_migration_never_backfills_ambiguous_legacy_assessor_carrier(): void
    {
        $injury = WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->create([
            'user_id' => $this->worker->id, 'site_id' => $this->site->id]));
        $assessment = WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $injury->id, 'user_id' => $this->manager->id]));
        $plan = ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $injury->id, 'worker_id' => $this->worker->id]));
        $this->assertSame(0, $injury->source_schema_version);
        $this->assertSame(0, $assessment->source_revision);
        $this->assertSame('legacy_unknown', $assessment->attribution_state);
        $this->assertNull($assessment->worker_id);
        $this->assertNull($assessment->assessor_user_id);
        $this->assertNull($plan->worker_snapshot);
        $this->assertSame($this->manager->id, (int) $assessment->user_id);
        $this->assertSame(0, DB::table('workplace_injury_source_versions')->count());
    }


    public function test_guarded_down_up_preserves_original_legacy_values_and_unknown_attribution(): void
    {
        $connection = DB::connection();
        if (app()->environment() !== 'testing' || $connection->getDriverName() !== 'mysql'
            || ! str_contains(strtolower($connection->getDatabaseName()), 'test')) {
            throw new \RuntimeException('Migration roundtrip requires the admitted MySQL test schema.');
        }
        $injury = WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->create([
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'notes' => 'Retained legacy medical narrative']));
        $assessment = WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $injury->id, 'user_id' => $this->manager->id]));
        $before = (array) DB::table('work_capacity_assessments')->where('id', $assessment->id)->first();
        $original = array_diff_key($before, array_flip(['source_schema_version', 'source_revision', 'worker_id', 'assessor_user_id',
            'recorded_by', 'attribution_state', 'worker_snapshot', 'assessor_snapshot', 'recorder_snapshot']));
        $this->beforeApplicationDestroyed(\Tests\Support\CommittedFixtureCleanup::capture()->restore(...));
        $this->assertSame(1, $connection->transactionLevel());
        $connection->commit();
        $migration = $this->migration();
        $down = false;
        try {
            $migration->down();
            $down = true;
            $this->assertSame($original, (array) DB::table('work_capacity_assessments')->where('id', $assessment->id)->first());
            $this->assertSame('Retained legacy medical narrative', DB::table('workplace_injuries')->where('id', $injury->id)->value('notes'));
            $migration->up();
            $down = false;
            $after = WorkCapacityAssessment::findOrFail($assessment->id);
            $this->assertSame($this->manager->id, (int) $after->user_id);
            $this->assertSame('legacy_unknown', $after->attribution_state);
            $this->assertNull($after->worker_id);
            $this->assertNull($after->assessor_user_id);
            $this->assertSame(0, $after->source_revision);
        } finally {
            if ($down) { $migration->up(); }
            $connection->beginTransaction();
        }
    }

    public function test_new_history_and_receipt_model_writes_are_immutable(): void
    {
        $injury = $this->injury();
        $version = $injury->sourceVersions()->sole();
        try { $version->forceFill(['command' => 'rewritten'])->save(); $this->fail('Version update must fail.'); }
        catch (LogicException $error) { $this->assertStringContainsString('immutable', $error->getMessage()); }
        $receipt = \App\Models\WorkplaceInjuryCommandReceipt::where('workplace_injury_id', $injury->id)->sole();
        try { $receipt->delete(); $this->fail('Receipt deletion must fail.'); }
        catch (LogicException $error) { $this->assertStringContainsString('retained', $error->getMessage()); }
        $this->assertSame('create', $version->fresh()->command);
        $this->assertNotNull($receipt->fresh());
    }
}
