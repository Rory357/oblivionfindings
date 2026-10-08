<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrPolicyVersion;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\StaffBackgroundCheck;
use App\Models\StaffCredential;
use App\Models\StaffTrainingRecord;
use App\Models\User;
use App\Services\Eligibility\WorkforceQualificationEvidence;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Explicit operational mappings consume recorded evidence for the complete duty. */
class WorkforceQualificationEvidenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Shift $shift;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 00:00:00', 'UTC'));
        config(['app.worker_timezone' => 'Pacific/Auckland', 'app.timezone' => 'UTC']);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake();
        $this->worker = User::factory()->create(['approved_at' => now(), 'external_clinical_account' => false]);
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $this->shift = Shift::factory()->create(['client_id' => $client->id, 'site_id' => $site->id,
            'service_context_id' => null, 'user_id' => $this->worker->id, 'status' => 'scheduled',
            'starts_at' => $this->instant('2026-10-12 08:00'), 'ends_at' => $this->instant('2026-10-12 12:00'),
            'created_by' => $this->worker->id, 'coverage_roles' => [], 'required_licence_class' => null,
            'required_licence_endorsements' => [], 'shift_type' => 'standard', 'is_sleepover' => false, 'is_on_call' => false]);
    }

    protected function tearDown(): void
    {
        try {
            DB::disconnect('wf32_evidence_writer');
            if ($this->committed && DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction()) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    #[DataProvider('evidenceTypes')]
    public function test_all_supported_explicit_mappings_use_their_actual_recorded_source(string $type): void
    {
        $requirement = $this->requirement($type);
        $this->record($type, $requirement);
        $state = $this->state();
        $queue = $this->queue();
        $this->assertSame(['passed' => true, 'failures' => []], $this->check($requirement, true));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    #[DataProvider('evidenceTypes')]
    public function test_recorded_source_failure_is_not_softened_by_a_non_hard_stop_catalogue_flag(string $type): void
    {
        $requirement = $this->requirement($type);
        $source = $this->record($type, $requirement);
        match ($type) {
            'credential' => $source->update(['expires_at' => '2026-10-11']),
            'training_course' => $source->update(['expires_at' => $this->instant('2026-10-12 10:00')]),
            'background_check' => $source->update(['check_date' => '2026-10-13']),
            'policy_attestation' => HrPolicyVersion::whereKey($source->policy_version_id)->update(['is_current' => false]),
            'driver_licence' => $source->update(['status' => 'suspended']),
            'manual' => $source->update(['status' => 'non_compliant']),
        };
        $state = $this->state();
        $queue = $this->queue();
        $result = $this->check($requirement, true);
        $this->assertFalse($result['passed']);
        $this->assertCount(1, $result['failures']);
        $this->assertSame($requirement->id, $result['failures'][0]['requirement_id']);
        $this->assertSame($requirement->code, $result['failures'][0]['code']);
        $this->assertNotEmpty($result['failures'][0]['reason']);
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function evidenceTypes(): array
    {
        return array_combine($types = ['credential', 'training_course', 'background_check', 'policy_attestation', 'driver_licence', 'manual'], array_map(fn ($type) => [$type], $types));
    }

    #[DataProvider('dateWindows')]
    public function test_date_evidence_covers_the_worker_calendar_and_exclusive_midnight_end(string $start, string $end, string $expiry, bool $passes): void
    {
        $requirement = $this->requirement('credential');
        $this->record('credential', $requirement)->update(['issued_at' => '2026-01-01', 'expires_at' => $expiry]);
        $this->shift->update(['starts_at' => $this->instant($start), 'ends_at' => $this->instant($end)]);
        $before = $this->shift->fresh()->getRawOriginal();
        $this->assertSame($passes, $this->check($requirement, true)['passed']);
        $this->assertSame($before, $this->shift->fresh()->getRawOriginal());
    }

    public static function dateWindows(): array
    {
        return ['midnight exclusive' => ['2026-10-12 22:00', '2026-10-13 00:00', '2026-10-12', true],
            'overnight next day' => ['2026-10-12 22:00', '2026-10-13 00:01', '2026-10-12', false],
            'daylight saving jump' => ['2026-09-27 00:00', '2026-09-27 04:00', '2026-09-27', true]];
    }

    #[DataProvider('instantWindows')]
    public function test_instant_sources_must_be_valid_at_start_and_until_exact_duty_end(string $change, bool $passes): void
    {
        $requirement = $this->requirement('training_course');
        $record = $this->record('training_course', $requirement);
        $record->update(match ($change) {
            'exact end' => ['expires_at' => $this->shift->ends_at],
            'before end' => ['expires_at' => $this->shift->ends_at->copy()->subSecond()],
            'after start' => ['completed_at' => $this->shift->starts_at->copy()->addSecond()],
        });
        $this->assertSame($passes, $this->check($requirement, true)['passed']);
    }

    public static function instantWindows(): array
    {
        return ['exact end' => ['exact end', true], 'before end' => ['before end', false], 'future completion' => ['after start', false]];
    }

    #[DataProvider('exemptions')]
    public function test_an_explicit_recorded_exemption_must_cover_the_whole_duty(string $expiry, string $approved, bool $passes): void
    {
        $requirement = $this->requirement('credential');
        HrStaffComplianceStatus::factory()->create(['user_id' => $this->worker->id, 'requirement_id' => $requirement->id,
            'evidence_type' => 'manual', 'status' => 'compliant', 'exemption_reason' => 'Existing approved operational exemption',
            'exempted_by' => $this->worker->id, 'exempted_at' => $this->instant($approved), 'exempted_until' => $expiry]);
        $this->assertSame($passes, $this->check($requirement, true)['passed']);
    }

    public static function exemptions(): array
    {
        return ['valid date' => ['2026-10-12', '2026-10-01 08:00', true],
            'prior date' => ['2026-10-11', '2026-10-01 08:00', false],
            'future approval' => ['2026-10-12', '2026-10-12 08:01', false]];
    }

    #[DataProvider('invalidMappings')]
    public function test_invalid_configured_evidence_is_unavailable_instead_of_silently_unmapped(string $change): void
    {
        $requirement = $this->requirement('credential');
        $this->record('credential', $requirement);
        $requirement->update($change === 'inactive' ? ['is_active' => false] : ['check_type' => 'unconfigured']);
        $before = $this->state();
        $queue = $this->queue();
        try {
            $this->check($requirement, true);
            $this->fail('Invalid configured evidence must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The configured qualification evidence is unavailable.', $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function invalidMappings(): array
    {
        return ['inactive' => ['inactive'], 'unsupported' => ['unsupported']];
    }

    #[DataProvider('committedChanges')]
    public function test_current_evidence_consumes_independent_commits_despite_loaded_relations_and_primed_rr(string $change, string $type, bool $initial, bool $current): void
    {
        $requirement = $this->requirement($type);
        $source = $change === 'new credential' ? null : $this->record($type, $requirement);
        $this->commitFixtures();
        $service = app(WorkforceQualificationEvidence::class);
        $this->assertSame($initial, $service->check($this->worker, collect([$requirement]), $this->shift)['passed']);
        $writer = $this->writer();
        DB::beginTransaction();
        try {
            $this->worker->load(['staffCredentials', 'staffTrainingRecords', 'staffBackgroundChecks']);
            $old = $this->state();
            $writer->transaction(function () use ($change, $requirement, $source, $writer): void {
                match ($change) {
                    'credential expiry' => $writer->table('staff_credentials')->where('id', $source->id)->update(['expires_at' => '2026-10-11']),
                    'driver suspension' => $writer->table('hr_driver_eligibility')->where('id', $source->id)->update(['status' => 'suspended']),
                    'policy revision' => $writer->table('hr_policy_versions')->where('id', $source->policy_version_id)->update(['is_current' => false]),
                    'new credential' => $writer->table('staff_credentials')->insert(['user_id' => $this->worker->id, 'type' => $requirement->code,
                        'issuer' => 'Independently committed source', 'issued_at' => '2026-01-01', 'expires_at' => '2027-01-01', 'created_at' => now(), 'updated_at' => now()]),
                };
            });
            $this->assertSame($old, $this->state(), 'The ordinary connection must really retain its old repeatable-read view.');
            $this->assertSame($initial, $service->check($this->worker, collect([$requirement]), $this->shift)['passed']);
            $committed = $this->state($writer);
            $this->assertNotSame($old, $committed);
            $queue = $this->queue();
            $result = $service->check($this->worker, collect([$requirement]), $this->shift, true);
            $this->assertSame($current, $result['passed']);
            $this->assertSame($queue, $this->queue());
            DB::commit();
            $this->assertSame($committed, $this->state());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public static function committedChanges(): array
    {
        return ['credential expired' => ['credential expiry', 'credential', true, false],
            'driver suspended' => ['driver suspension', 'driver_licence', true, false],
            'current policy version removed' => ['policy revision', 'policy_attestation', true, false],
            'new recorded credential' => ['new credential', 'credential', false, true]];
    }

    private function requirement(string $type): HrComplianceRequirement
    {
        return HrComplianceRequirement::factory()->create(['check_type' => $type, 'hard_stop' => false,
            'validity_months' => null, 'is_active' => true, 'reference_id' => null]);
    }

    private function record(string $type, HrComplianceRequirement $requirement)
    {
        return match ($type) {
            'credential' => StaffCredential::create(['user_id' => $this->worker->id, 'type' => $requirement->code,
                'issuer' => 'Explicit HR qualification', 'issued_at' => '2026-01-01', 'expires_at' => '2027-01-01']),
            'training_course' => StaffTrainingRecord::create(['user_id' => $this->worker->id,
                'hr_course_id' => HrCourse::factory()->create(['compliance_requirement_id' => $requirement->id])->id,
                'status' => 'completed', 'completed_at' => Carbon::parse('2026-01-01', 'UTC'), 'expires_at' => Carbon::parse('2027-01-01', 'UTC')]),
            'background_check' => StaffBackgroundCheck::create(['user_id' => $this->worker->id, 'check_type' => 'police_check',
                'status' => 'clear', 'check_date' => '2026-01-01', 'expires_at' => '2027-01-01', 'created_by' => $this->worker->id]),
            'policy_attestation' => $this->attestation($requirement),
            'driver_licence' => HrDriverEligibility::create(['user_id' => $this->worker->id, 'licence_class' => '1',
                'licence_expires_at' => '2027-01-01', 'can_drive_clients' => true, 'can_drive_clients_approved_by' => $this->worker->id,
                'can_drive_clients_approved_at' => now(), 'status' => 'eligible']),
            'manual' => HrStaffComplianceStatus::factory()->create(['user_id' => $this->worker->id, 'requirement_id' => $requirement->id,
                'evidence_type' => 'manual', 'status' => 'compliant', 'valid_from' => '2026-01-01', 'expires_at' => '2027-01-01',
                'exemption_reason' => null, 'exempted_at' => null, 'exempted_until' => null]),
        };
    }

    private function attestation(HrComplianceRequirement $requirement): HrPolicyAttestation
    {
        $policy = HrPolicy::factory()->create(['is_active' => true, 'requires_attestation' => true]);
        $requirement->update(['reference_id' => $policy->id]);
        $version = HrPolicyVersion::create(['policy_id' => $policy->id, 'version_number' => 1, 'content_summary' => 'Synthetic policy',
            'document_path' => 'synthetic/qualification-policy.pdf', 'effective_from' => '2026-01-01', 'is_current' => true, 'published_by' => $this->worker->id]);

        return HrPolicyAttestation::create(['user_id' => $this->worker->id, 'policy_id' => $policy->id,
            'policy_version_id' => $version->id, 'attested_at' => Carbon::parse('2026-01-02', 'UTC'), 'attestation_method' => 'digital']);
    }

    private function check(HrComplianceRequirement $requirement, bool $current): array
    {
        return app(WorkforceQualificationEvidence::class)->check($this->worker, collect([$requirement]), $this->shift, $current);
    }

    private function instant(string $time): Carbon
    {
        return Carbon::parse($time, 'Pacific/Auckland')->utc();
    }

    private function state(?Connection $connection = null): array
    {
        $tables = ['hr_compliance_requirements', 'hr_courses', 'staff_credentials', 'staff_training_records', 'staff_background_checks',
            'hr_policy_versions', 'hr_policy_attestations', 'hr_driver_eligibility', 'hr_staff_compliance_status',
            'shifts', 'audit_logs', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations'];

        return array_combine($tables, array_map(fn ($table) => ($connection ?? DB::connection())->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), $tables));
    }

    private function queue(): array
    {
        return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
    }

    private function commitFixtures(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertSame($connection->getDatabaseName(), $connection->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertSame(1, $connection->transactionLevel());
        DB::commit();
        $this->committed = true;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function writer(): Connection
    {
        $name = 'wf32_evidence_writer';
        config(['database.connections.'.$name => [...DB::connection()->getConfig(), 'name' => $name]]);
        DB::purge($name);
        $writer = DB::connection($name);
        $this->assertSame($name, $writer->getName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $this->assertSame($writer->getDatabaseName(), $writer->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertNotSame(DB::connection()->getPdo(), $writer->getPdo());
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertFalse($writer->getPdo()->inTransaction());

        return $writer;
    }
}
