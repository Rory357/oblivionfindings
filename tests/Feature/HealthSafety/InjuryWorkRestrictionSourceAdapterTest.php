<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\WorkRestrictionSourceAdapter;
use App\Models\AuditLog;
use App\Models\ModifiedDuty;
use App\Models\Permission;
use App\Models\ReturnToWorkPlan;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjurySourceVersion;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\InjuryWorkRestrictionSourceAdapter;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;

/** Prepared source tests only. HR's complete decision/staging suites remain separate integration prerequisites. */
final class InjuryWorkRestrictionSourceAdapterTest extends WorkplaceInjurySourceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function adapter(): InjuryWorkRestrictionSourceAdapter
    {
        return app(InjuryWorkRestrictionSourceAdapter::class);
    }

    private function reads(callable $operation): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within($operation));
    }

    private function sourceRecord(string $type = 'capacity_assessment'): array
    {
        $injury = $this->injury();
        $receipt = match ($type) {
            'injury' => ['source_id' => $injury->id, 'source_version_id' => WorkplaceInjurySourceVersion::where('workplace_injury_id', $injury->id)->sole()->id],
            'capacity_assessment' => $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury)),
            'rtw_plan' => $this->recovery()->createPlan($injury, $this->manager, $this->planPayload($injury)),
            'modified_duty' => $this->duty($injury),
        };
        $version = WorkplaceInjurySourceVersion::findOrFail($receipt['source_version_id']);

        return [$injury, ['injury_id' => (int) $injury->id, 'expected_injury_revision' => (int) $injury->fresh()->source_revision,
            'source_type' => $type, 'source_id' => (int) $receipt['source_id'],
            'expected_source_revision' => (int) $version->aggregate_revision, 'expected_source_version_id' => (int) $version->id]];
    }

    private function duty(WorkplaceInjury $injury): array
    {
        $accepted = $this->recovery()->createPlan($injury, $this->manager, $this->planPayload($injury));
        $plan = ReturnToWorkPlan::findOrFail($accepted['source_id']);

        return $this->recovery()->addModifiedDuty($injury, $plan, $this->manager, $this->command($injury,
            ['start_date' => '2026-10-12', 'end_date' => '2026-10-20', 'modified_duties_description' => 'Private proposed duty', 'hours_per_day' => 4]));
    }

    private function current(array $expected, ?User $actor = null): array
    {
        return $this->reads(fn ($reads) => $this->adapter()->current($actor ?? $this->medicalReader, $expected, $reads));
    }

    private function recorded(array $source, ?User $actor = null): void
    {
        $this->reads(fn ($reads) => $this->adapter()->assertRecorded($actor ?? $this->medicalReader, $source, $reads));
    }

    private function integrity(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Damaged or expired source evidence must not be accepted.');
        } catch (LogicException $failure) {
            $this->assertNotSame('', $failure->getMessage());
        }
    }

    private function allow(User $actor, string $key): void
    {
        $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => true]]);
    }

    public static function types(): array
    {
        return ['injury' => ['injury'], 'assessment' => ['capacity_assessment'], 'plan' => ['rtw_plan'], 'duty' => ['modified_duty']];
    }

    #[DataProvider('types')]
    public function test_real_domain_sources_return_only_the_agreed_scalar_recording_contract(string $type): void
    {
        [$injury, $expected] = $this->sourceRecord($type);
        $source = $this->current($expected);
        $this->assertInstanceOf(WorkRestrictionSourceAdapter::class, $this->adapter());
        $this->assertSame(['injury_id', 'injury_revision', 'worker_id', 'source_site_id', 'source', 'attribution_state',
            'recorded_by', 'recorded_at', 'coordinator_user_id'], array_keys($source));
        $this->assertSame(['type' => $type, 'id' => $expected['source_id'], 'revision' => $expected['expected_source_revision'],
            'version_id' => $expected['expected_source_version_id']], $source['source']);
        $this->assertSame((int) $injury->fresh()->source_revision, $source['injury_revision']);
        $this->assertSame($this->worker->id, $source['worker_id']);
        $this->assertSame($this->site->id, $source['source_site_id']);
        $this->assertSame($this->manager->id, $source['recorded_by']);
        $this->assertSame('recorded', $source['attribution_state']);
        $this->assertNull($source['coordinator_user_id']);
        foreach (['Private', 'notes', 'restrictions', 'medical_clearance', 'assessment_summary', 'assessor', 'approved', 'fitness', 'recorder_snapshot'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($source));
        }
        $this->recorded($source);
    }

    public function test_source_recording_keeps_microseconds_and_coordinator_id_without_name(): void
    {
        $this->travelTo(now()->setTime(1, 0, 0, 123456));
        [$injury, $expected] = $this->sourceRecord();
        $this->source()->assign($injury, $this->manager, $this->command($injury,
            ['coordinator_user_id' => $this->factual->id, 'reason' => 'Named source coordinator', 'next_review_date' => '2026-10-20']));
        $expected['expected_injury_revision'] = (int) $injury->fresh()->source_revision;
        $source = $this->current($expected);
        $this->assertStringEndsWith('.123456Z', $source['recorded_at']);
        $this->assertSame($this->factual->id, $source['coordinator_user_id']);
        $this->assertStringNotContainsString($this->factual->name, json_encode($source));
        $this->recorded($source);
    }

    public function test_caller_connection_and_transaction_lifetime_are_reused_and_parent_precedes_private_child(): void
    {
        [, $expected] = $this->sourceRecord();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->reads(function ($reads) use ($expected, $connection, $pdo): void {
                $level = $connection->transactionLevel();
                $this->adapter()->current($this->medicalReader, $expected, $reads);
                $this->assertSame($connection, DB::connection());
                $this->assertSame($pdo, DB::connection()->getPdo());
                $this->assertSame($level, $connection->transactionLevel());
            });
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
        }
        $parent = array_find_key($queries, fn ($query) => str_contains($query, 'from `workplace_injuries`'));
        $child = array_find_key($queries, fn ($query) => str_contains($query, 'from `work_capacity_assessments`'));
        $version = array_find_key($queries, fn ($query) => str_contains($query, 'from `workplace_injury_source_versions`'));
        $this->assertNotNull($parent);
        $this->assertNotNull($child);
        $this->assertNotNull($version);
        $this->assertLessThan($child, $parent);
        $this->assertLessThan($version, $parent);
        $this->assertStringContainsString('for share nowait', $queries[$parent]);
        $this->assertStringContainsString('for share nowait', $queries[$child]);
    }

    public function test_expired_reads_reject_both_new_admission_and_recovery(): void
    {
        [, $expected] = $this->sourceRecord();
        $source = $this->current($expected);
        $expired = $this->reads(fn ($reads) => $reads);
        $this->integrity(fn () => $this->adapter()->current($this->medicalReader, $expected, $expired));
        $this->integrity(fn () => $this->adapter()->assertRecorded($this->medicalReader, $source, $expired));
    }

    public function test_foreign_physical_connection_cannot_consume_the_supplied_reads(): void
    {
        [, $expected] = $this->sourceRecord();
        $original = DB::getDefaultConnection();
        $foreign = 'injury_adapter_foreign';
        config(['database.connections.'.$foreign => config('database.connections.'.$original)]);
        try {
            $this->reads(function ($reads) use ($expected, $original, $foreign): void {
                DB::setDefaultConnection($foreign);
                try {
                    $this->integrity(fn () => $this->adapter()->current($this->medicalReader, $expected, $reads));
                } finally {
                    DB::setDefaultConnection($original);
                }
                $this->assertSame('recorded', $this->adapter()->current($this->medicalReader, $expected, $reads)['attribution_state']);
            });
        } finally {
            DB::setDefaultConnection($original);
            DB::purge($foreign);
        }
    }

    public function test_same_callback_refuses_foreign_actor_models_and_authority_model_escape(): void
    {
        [, $expected] = $this->sourceRecord();
        $original = DB::getDefaultConnection();
        $foreign = 'injury_adapter_foreign_actor';
        config(['database.connections.'.$foreign => config('database.connections.'.$original)]);
        $alien = clone $this->medicalReader;
        $alien->setConnection($foreign);
        try {
            $this->reads(function ($reads) use ($expected, $alien): void {
                $this->integrity(fn () => $this->adapter()->current($alien, $expected, $reads));
                $this->integrity(fn () => $this->access()->withCurrentReads($this->medicalReader, $reads, fn ($current) => $current));
            });
        } finally {
            DB::purge($foreign);
        }
    }

    public function test_aggregate_and_selected_typed_revisions_are_distinct_new_admission_preconditions(): void
    {
        [$injury, $expected] = $this->sourceRecord();
        $source = $this->current($expected);
        $this->source()->assign($injury, $this->manager, $this->command($injury,
            ['coordinator_user_id' => $this->factual->id, 'reason' => 'Later aggregate coordination', 'next_review_date' => '2026-10-20']));
        $this->validation(fn () => $this->current($expected), 'expected_injury_revision');
        $expected['expected_injury_revision'] = (int) $injury->fresh()->source_revision;
        $fresh = $this->current($expected);
        $this->assertSame($source['source'], $fresh['source']);
        $this->assertGreaterThan($fresh['source']['revision'], $fresh['injury_revision']);
        $this->validation(fn () => $this->current([...$expected, 'expected_source_revision' => $fresh['injury_revision']]), 'expected_source_revision');
        $this->recorded($source);
    }

    public function test_recovery_retains_original_plan_version_after_lawful_later_private_revision(): void
    {
        [$injury, $expected] = $this->sourceRecord('rtw_plan');
        $original = $this->current($expected);
        $plan = ReturnToWorkPlan::findOrFail($expected['source_id']);
        $changed = $this->recovery()->updatePlan($injury, $plan, $this->manager, $this->command($injury,
            ['review_notes' => 'Lawfully recorded later private source fact']));
        $this->assertNotSame($original['source']['version_id'], $changed['source_version_id']);
        $this->recorded($original);
        $this->validation(fn () => $this->current([...$expected, 'expected_injury_revision' => (int) $injury->fresh()->source_revision]), 'expected_source_revision');
        $fresh = $this->current([...$expected, 'expected_injury_revision' => (int) $injury->fresh()->source_revision,
            'expected_source_revision' => (int) $plan->fresh()->source_revision, 'expected_source_version_id' => (int) $changed['source_version_id']]);
        $this->assertNotSame($original['source'], $fresh['source']);
        $this->assertSame($expected['expected_source_version_id'], $original['source']['version_id']);
    }

    public function test_recorded_source_can_recover_after_physical_commit_but_current_authority_loss_still_denies(): void
    {
        [, $expected] = $this->sourceRecord();
        $source = $this->current($expected);
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertSame('testing', app()->environment());
        $this->assertStringContainsString('test', strtolower($connection->getDatabaseName()));
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $connection->commit();
        try {
            $this->assertSame(0, $connection->transactionLevel());
            $this->recorded($source);
            $this->revoke($this->medicalReader, 'hr.wellbeing.view');
            $this->denial(fn () => $this->recorded($source));
        } finally {
            $connection->beginTransaction();
        }
    }

    public function test_warmed_private_grant_revocation_and_restoration_rechecks_current_and_recovery(): void
    {
        [, $expected] = $this->sourceRecord();
        $source = $this->current($expected);
        $this->medicalReader->load('roles.permissions', 'permissionOverrides');
        $this->revoke($this->medicalReader, 'hr.wellbeing.view');
        $this->denial(fn () => $this->current($expected));
        $this->denial(fn () => $this->recorded($source));
        $this->allow($this->medicalReader, 'hr.wellbeing.view');
        $this->assertSame($source, $this->current($expected));
        $this->recorded($source);
    }

    public function test_factual_or_capture_only_grants_never_select_private_source_or_version_content(): void
    {
        [, $expected] = $this->sourceRecord();
        foreach ([$this->factual, $this->capturer] as $actor) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->denial(fn () => $this->current($expected, $actor));
                $sql = strtolower(implode("\n", array_column(DB::getQueryLog(), 'query')));
            } finally {
                DB::disableQueryLog();
            }
            foreach (['workplace_injuries', 'work_capacity_assessments', 'workplace_injury_source_versions', 'hr_documents'] as $private) {
                $this->assertStringNotContainsString($private, $sql);
            }
        }
    }

    public function test_current_site_revocation_and_restoration_preserve_historical_worker_identity(): void
    {
        [, $expected] = $this->sourceRecord();
        $source = $this->current($expected);
        $other = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::where('user_id', $this->medicalReader->id)->update(['primary_site_id' => $other->id]);
        $this->denial(fn () => $this->current($expected), 404);
        $this->denial(fn () => $this->recorded($source), 404);
        HrEmployeeProfile::where('user_id', $this->medicalReader->id)->update(['primary_site_id' => $this->site->id]);
        HrEmployeeProfile::where('user_id', $this->worker->id)->update(['is_active' => false, 'end_date' => '2026-10-11']);
        $this->assertSame($source, $this->current($expected));
        $this->recorded($source);
    }

    public function test_inactive_site_inactive_staff_and_external_actor_cannot_recover_private_source(): void
    {
        [, $expected] = $this->sourceRecord();
        $source = $this->current($expected);
        $this->site->update(['is_active' => false]);
        $this->denial(fn () => $this->current($expected));
        $this->denial(fn () => $this->recorded($source));
        $this->site->update(['is_active' => true]);
        HrEmployeeProfile::where('user_id', $this->medicalReader->id)->update(['is_active' => false]);
        $this->denial(fn () => $this->recorded($source));
        HrEmployeeProfile::where('user_id', $this->medicalReader->id)->update(['is_active' => true]);
        User::whereKey($this->medicalReader->id)->update(['external_clinical_account' => true]);
        $this->denial(fn () => $this->current($expected));
        $this->denial(fn () => $this->recorded($source));
    }

    public function test_exact_parent_worker_type_and_version_identity_cannot_be_replaced_by_same_site_records(): void
    {
        [, $expected] = $this->sourceRecord();
        $source = $this->current($expected);
        $otherWorker = $this->staff();
        $other = $this->injury(['user_id' => $otherWorker->id]);
        $child = $this->recovery()->recordCapacity($other, $this->manager, $this->capacityPayload($other));
        $this->denial(fn () => $this->current([...$expected, 'source_id' => (int) $child['source_id']]), 404);
        $this->denial(fn () => $this->current([...$expected, 'source_type' => 'injury', 'source_id' => (int) $other->id]), 404);
        $this->denial(fn () => $this->recorded([...$source, 'source' => [...$source['source'], 'version_id' => (int) $child['source_version_id']]]), 404);
        $this->integrity(fn () => $this->recorded([...$source, 'worker_id' => $otherWorker->id]));
    }

    public function test_modified_duty_cannot_reparent_to_another_workers_plan(): void
    {
        [, $expected] = $this->sourceRecord('modified_duty');
        $source = $this->current($expected);
        $other = $this->injury(['user_id' => $this->staff()->id]);
        $plan = $this->recovery()->createPlan($other, $this->manager, $this->planPayload($other));
        DB::table('modified_duties')->where('id', $expected['source_id'])->update(['return_to_work_plan_id' => $plan['source_id']]);
        $this->denial(fn () => $this->current($expected), 404);
        $this->denial(fn () => $this->recorded($source), 404);
    }

    public function test_parent_or_typed_child_marker_downgrade_is_not_legacy_admission(): void
    {
        [$injury, $expected] = $this->sourceRecord();
        $source = $this->current($expected);
        DB::table('work_capacity_assessments')->where('id', $expected['source_id'])->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->integrity(fn () => $this->current($expected));
        $this->integrity(fn () => $this->recorded($source));
        DB::table('work_capacity_assessments')->where('id', $expected['source_id'])->update(['source_schema_version' => 1, 'source_revision' => $expected['expected_source_revision']]);
        DB::table('workplace_injuries')->where('id', $injury->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->integrity(fn () => $this->current($expected));
        $this->integrity(fn () => $this->recorded($source));
    }

    public function test_true_legacy_source_remains_unknown_and_cannot_be_used_as_typed_restriction_evidence(): void
    {
        $legacy = WorkplaceInjury::factory()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id]);
        $this->integrity(fn () => $this->current(['injury_id' => (int) $legacy->id, 'expected_injury_revision' => 1,
            'source_type' => 'injury', 'source_id' => (int) $legacy->id, 'expected_source_revision' => 1, 'expected_source_version_id' => 1]));
        $this->assertSame(0, (int) $legacy->fresh()->source_schema_version);
        $this->assertSame(0, (int) $legacy->fresh()->source_revision);
    }

    public function test_external_private_row_damage_and_missing_or_bad_version_refuse_saved_source(): void
    {
        [, $expected] = $this->sourceRecord();
        $source = $this->current($expected);
        $child = WorkCapacityAssessment::findOrFail($expected['source_id']);
        $before = $child->assessment_summary;
        DB::table('work_capacity_assessments')->where('id', $child->id)->update(['assessment_summary' => 'External unaudited change']);
        $this->integrity(fn () => $this->recorded($source));
        DB::table('work_capacity_assessments')->where('id', $child->id)->update(['assessment_summary' => $before]);
        DB::table('workplace_injury_source_versions')->where('id', $expected['expected_source_version_id'])->update(['content_digest' => str_repeat('0', 64)]);
        $this->integrity(fn () => $this->current($expected));
        $this->integrity(fn () => $this->recorded($source));
        DB::table('workplace_injury_source_versions')->where('id', $expected['expected_source_version_id'])->delete();
        $this->integrity(fn () => $this->recorded($source));
    }

    public function test_tampered_original_historical_version_is_rejected_even_when_current_plan_proof_is_intact(): void
    {
        [$injury, $expected] = $this->sourceRecord('rtw_plan');
        $source = $this->current($expected);
        $plan = ReturnToWorkPlan::findOrFail($expected['source_id']);
        $this->recovery()->updatePlan($injury, $plan, $this->manager, $this->command($injury, ['review_notes' => 'New retained version']));
        DB::table('workplace_injury_source_versions')->where('id', $expected['expected_source_version_id'])->update(['source_type' => 'capacity_assessment']);
        $this->denial(fn () => $this->recorded($source), 404);
    }

    public function test_archived_parent_and_selected_private_child_cannot_be_recovered(): void
    {
        [$injury, $expected] = $this->sourceRecord();
        $source = $this->current($expected);
        WorkCapacityAssessment::findOrFail($expected['source_id'])->deleteQuietly();
        $this->denial(fn () => $this->recorded($source), 404);
        $injury->deleteQuietly();
        $this->denial(fn () => $this->current($expected), 404);
    }

    public function test_source_document_authority_is_current_independent_and_exact_historical_employee(): void
    {
        $injury = $this->injury();
        $document = HrDocument::factory()->create(['employee_profile_id' => HrEmployeeProfile::where('user_id', $this->worker->id)->sole()->id, 'is_restricted' => false]);
        $this->allow($this->manager, 'hr.documents.view');
        $receipt = $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury, ['hr_document_id' => (int) $document->id]));
        $expected = ['injury_id' => (int) $injury->id, 'expected_injury_revision' => (int) $injury->fresh()->source_revision,
            'source_type' => 'capacity_assessment', 'source_id' => (int) $receipt['source_id'],
            'expected_source_revision' => (int) $receipt['version'], 'expected_source_version_id' => (int) $receipt['source_version_id']];
        $this->denial(fn () => $this->current($expected));
        $this->allow($this->medicalReader, 'hr.documents.view');
        $source = $this->current($expected);
        $this->assertStringNotContainsString('document', json_encode($source));
        $this->revoke($this->medicalReader, 'hr.documents.view');
        $this->denial(fn () => $this->recorded($source));
        $this->allow($this->medicalReader, 'hr.documents.view');
        $document->update(['is_restricted' => true]);
        $this->denial(fn () => $this->recorded($source), 404);
        $this->allow($this->medicalReader, 'hr.documents.manage');
        $this->recorded($source);
        $document->update(['employee_profile_id' => HrEmployeeProfile::where('user_id', $this->staff()->id)->sole()->id]);
        $this->denial(fn () => $this->recorded($source), 404);
    }

    public function test_hs_all_sites_does_not_replace_fresh_independent_hr_document_site_authority(): void
    {
        $injury = $this->injury();
        $profile = HrEmployeeProfile::where('user_id', $this->worker->id)->sole();
        $document = HrDocument::factory()->create(['employee_profile_id' => $profile->id, 'is_restricted' => false]);
        $this->allow($this->manager, 'hr.documents.view');
        $receipt = $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury, ['hr_document_id' => (int) $document->id]));
        $expected = ['injury_id' => (int) $injury->id, 'expected_injury_revision' => (int) $injury->fresh()->source_revision,
            'source_type' => 'capacity_assessment', 'source_id' => (int) $receipt['source_id'],
            'expected_source_revision' => (int) $receipt['version'], 'expected_source_version_id' => (int) $receipt['source_version_id']];
        $this->allow($this->medicalReader, 'hr.documents.view');
        $this->allow($this->medicalReader, 'healthSafety.viewAllSites');
        $source = $this->current($expected);
        $this->assertContains($this->site->id, app(UserSiteAccessService::class)->accessibleSiteIds(
            $this->medicalReader, UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS));
        $other = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::where('user_id', $this->medicalReader->id)->update(['primary_site_id' => $other->id]);
        $this->denial(fn () => $this->current($expected), 404);
        $this->denial(fn () => $this->recorded($source), 404);
        $this->allow($this->medicalReader, 'hr.employees.viewAllSites');
        $this->recorded($source);
        $this->revoke($this->medicalReader, 'hr.employees.viewAllSites');
        $this->denial(fn () => $this->recorded($source), 404);
        HrEmployeeProfile::where('user_id', $this->medicalReader->id)->update(['primary_site_id' => $this->site->id]);
        $profile->update(['is_active' => false, 'end_date' => '2026-10-11']);
        $profile->delete();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->reads(function ($reads) use ($source): void {
                $connection = DB::connection();
                $level = $connection->transactionLevel();
                $this->adapter()->assertRecorded($this->medicalReader, $source, $reads);
                $this->assertSame($connection, DB::connection());
                $this->assertSame($level, $connection->transactionLevel());
            });
            $documentQueries = array_filter(array_column(DB::getQueryLog(), 'query'), fn ($query) => str_contains($query, 'from `hr_documents`'));
        } finally {
            DB::disableQueryLog();
        }
        $this->assertNotEmpty($documentQueries);
        foreach ($documentQueries as $query) {
            $this->assertStringContainsString('for share nowait', $query);
            foreach (['`file_path`', '`original_filename`', '`notes`', 'select *'] as $private) {
                $this->assertStringNotContainsString($private, strtolower($query));
            }
        }
        $this->assertSame($source, $this->current($expected));
    }

    public function test_missing_original_version_does_not_fall_back_to_a_valid_later_source(): void
    {
        [$injury, $expected] = $this->sourceRecord('rtw_plan');
        $source = $this->current($expected);
        $plan = ReturnToWorkPlan::findOrFail($expected['source_id']);
        $changed = $this->recovery()->updatePlan($injury, $plan, $this->manager, $this->command($injury, ['review_notes' => 'Later source remains intact']));
        DB::table('workplace_injury_source_versions')->where('id', $source['source']['version_id'])->delete();
        $this->denial(fn () => $this->recorded($source), 404);
        $fresh = $this->current([...$expected, 'expected_injury_revision' => (int) $injury->fresh()->source_revision,
            'expected_source_revision' => (int) $plan->fresh()->source_revision, 'expected_source_version_id' => (int) $changed['source_version_id']]);
        $this->assertSame((int) $changed['source_version_id'], $fresh['source']['version_id']);
    }

    public function test_saved_source_rejects_changed_recording_precision_actor_and_unrecognised_private_fields(): void
    {
        [, $expected] = $this->sourceRecord();
        $source = $this->current($expected);
        $this->integrity(fn () => $this->recorded([...$source, 'recorded_at' => substr($source['recorded_at'], 0, 19).'Z']));
        $this->integrity(fn () => $this->recorded([...$source, 'recorded_by' => $this->worker->id]));
        $this->integrity(fn () => $this->recorded([...$source, 'notes' => 'Never echo private text']));
        $this->integrity(fn () => $this->current([...$expected, 'approval' => true]));
        $this->validation(fn () => $this->current([...$expected, 'source_type' => 'attachment']), 'source_type');
        $this->validation(fn () => $this->current([...$expected, 'expected_source_version_id' => true]), 'expected_source_version_id');
    }

    public function test_caller_normal_write_veto_rolls_back_without_source_mutation_or_workforce_staging(): void
    {
        [, $expected] = $this->sourceRecord();
        $versions = WorkplaceInjurySourceVersion::count();
        DB::enableQueryLog();
        DB::flushQueryLog();
        AuditLog::creating(fn ($row) => $row->action === 'injury_adapter.required_veto' ? false : null);
        try {
            $this->integrity(fn () => $this->reads(function ($reads) use ($expected): void {
                $this->adapter()->current($this->medicalReader, $expected, $reads);
                AuditLog::create(['action' => 'injury_adapter.caller_first_write', 'meta' => [], 'user_id' => $this->medicalReader->id]);
                $required = new AuditLog(['action' => 'injury_adapter.required_veto', 'meta' => [], 'user_id' => $this->medicalReader->id]);
                if ($required->save() !== true) {
                    throw new LogicException('Required caller write was vetoed.');
                }
            }));
            $sql = strtolower(implode("\n", array_column(DB::getQueryLog(), 'query')));
        } finally {
            AuditLog::flushEventListeners();
            DB::disableQueryLog();
        }
        $this->assertSame(0, AuditLog::where('action', 'like', 'injury_adapter.%')->count());
        $this->assertSame($versions, WorkplaceInjurySourceVersion::count());
        $this->assertStringNotContainsString('workforce_', $sql);
        $this->assertSame('recorded', $this->current($expected)['attribution_state']);
    }
}
