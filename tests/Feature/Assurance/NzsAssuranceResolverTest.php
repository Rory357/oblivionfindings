<?php

namespace Tests\Feature\Assurance;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Enums\AssuranceStatus;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\SiteCertification;
use App\Models\StaffTrainingRecord;
use App\Models\TrainingCourse;
use App\Models\User;
use App\Services\Assurance\NzsAssuranceResolver;
use App\Services\Assurance\SiteCertificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NzsAssuranceResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_certification_requires_current_site_module_provenance_and_readable_unchanged_evidence(): void
    {
        $resolver = app(NzsAssuranceResolver::class);
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $reviewer = User::factory()->create();

        $this->assertSame(AssuranceStatus::UNKNOWN, $resolver->certificationForSite($site->id));
        $otherCertification = $this->validCertification($otherSite, $reviewer, 'other-site.pdf');
        SiteCertification::query()->create([
            'site_id' => $site->id,
            'certification_type' => 'first_aid',
            'name' => 'Wrong module evidence',
            'status' => 'current',
        ]);
        $this->assertSame(AssuranceStatus::UNKNOWN, $resolver->certificationForSite($site->id));

        $missing = SiteCertification::query()->create([
            'site_id' => $site->id,
            'certification_type' => NzsAssuranceResolver::CERTIFICATION_TYPE,
            'name' => 'Unsubstantiated certification',
            'status' => 'current',
            'issued_date' => now()->subYear(),
            'expiry_date' => now()->addYear(),
        ]);
        $this->assertSame(AssuranceStatus::ACTION_REQUIRED, $resolver->certificationForSite($site->id));

        $missing->forceFill([
            'issuing_body' => 'HealthCERT',
            'reference_number' => 'FORGED-OTHER-SITE',
            'document_disk' => 'private',
            'document_path' => $otherCertification->document_path,
            'evidence_sha256' => $otherCertification->evidence_sha256,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();
        $this->assertSame(AssuranceStatus::ACTION_REQUIRED, $resolver->certificationForSite($site->id));

        $valid = $this->validCertification($site, $reviewer, 'current.pdf');
        $this->assertSame(AssuranceStatus::CERTIFIED, $resolver->certificationForSite($site->id));

        $reviewer->hrEmployeeProfile->forceFill([
            'primary_site_id' => $otherSite->id,
            'secondary_site_ids' => [],
        ])->save();
        $this->assertSame(
            AssuranceStatus::ACTION_REQUIRED,
            app(NzsAssuranceResolver::class)->certificationForSite($site->id),
        );
        $this->authoriseReviewerForSite($reviewer, $site);

        Storage::disk('private')->put($valid->document_path, 'tampered evidence');
        $this->assertSame(AssuranceStatus::ACTION_REQUIRED, $resolver->certificationForSite($site->id));

        $valid->forceFill(['expiry_date' => now()->subDay()])->save();
        $this->assertSame(AssuranceStatus::ACTION_REQUIRED, $resolver->certificationForSite($site->id));

        $valid->forceFill([
            'expiry_date' => now()->addYear(),
            'revoked_at' => now(),
            'revoked_by' => $reviewer->id,
            'revocation_reason' => 'Regulator revoked the certificate.',
        ])->save();
        $this->assertSame(AssuranceStatus::ACTION_REQUIRED, $resolver->certificationForSite($site->id));

        $valid->delete();
        $this->assertSame(AssuranceStatus::ACTION_REQUIRED, $resolver->certificationForSite($site->id));
        $this->assertNotNull($missing->fresh());
    }

    public function test_certification_storage_failure_is_unknown_and_never_green(): void
    {
        $site = Site::factory()->create();
        $reviewer = User::factory()->create();
        $this->validCertification($site, $reviewer, 'unavailable.pdf');
        Storage::forgetDisk('private');
        config()->set('filesystems.disks.private', ['driver' => 'not-a-driver']);

        $this->assertSame(
            AssuranceStatus::UNKNOWN,
            app(NzsAssuranceResolver::class)->certificationForSite($site->id),
        );
    }

    public function test_canonical_writer_rejects_foreign_reviewers_and_versions_evidence_history(): void
    {
        $site = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $actor = User::factory()->create();
        $reviewer = User::factory()->create();
        $this->authoriseReviewerForSite($reviewer, $foreignSite);
        $original = app(SiteCertificationService::class)->create($site, $actor, [
            'certification_type' => NzsAssuranceResolver::CERTIFICATION_TYPE,
            'name' => 'Ngā Paerewa certification',
            'status' => 'current',
        ]);

        try {
            app(SiteCertificationService::class)->update($site, $original->id, $actor, [
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);
            $this->fail('A reviewer from another Site was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reviewed_by', $exception->errors());
        }
        $this->assertDatabaseCount('site_certifications', 1);

        $this->authoriseReviewerForSite($reviewer, $site);
        $foreignPath = 'site-certifications/'.$foreignSite->id.'/replay.pdf';
        Storage::disk('private')->put($foreignPath, 'foreign Site evidence');
        try {
            app(SiteCertificationService::class)->update($site, $original->id, $actor, [
                'document_path' => $foreignPath,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);
            $this->fail('Evidence stored under another Site was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document_path', $exception->errors());
        }
        $this->assertDatabaseCount('site_certifications', 1);

        $path = 'site-certifications/'.$site->id.'/writer-evidence.pdf';
        $contents = 'writer-backed certification evidence';
        Storage::disk('private')->put($path, $contents);
        $successor = app(SiteCertificationService::class)->update($site, $original->id, $actor, [
            'issuing_body' => 'HealthCERT',
            'reference_number' => 'WRITER-NZS',
            'issued_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'document_path' => $path,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $this->assertSame($original->id, $successor->supersedes_certification_id);
        $this->assertSame(hash('sha256', $contents), $successor->evidence_sha256);
        $revoked = SiteCertification::withTrashed()->findOrFail($original->id);
        $this->assertTrue($revoked->trashed());
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame(
            AssuranceStatus::CERTIFIED,
            app(NzsAssuranceResolver::class)->certificationForSite($site->id),
        );
    }

    public function test_first_aid_cover_requires_every_site_shift_to_be_fully_covered_by_current_site_authorised_competency(): void
    {
        $resolver = app(NzsAssuranceResolver::class);
        $site = Site::factory()->create();
        $staff = User::factory()->create();
        $start = now()->addDay()->startOfHour();
        $end = $start->copy()->addHours(8);

        $this->assertSame(
            AssuranceStatus::UNKNOWN,
            $resolver->firstAidCoverageForSite($site->id, $start->copy()->subHour(), $end->copy()->addHour()),
        );

        $shift = Shift::factory()->forSite($site)->create([
            'client_id' => null,
            'service_context_id' => null,
            'user_id' => $staff->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'status' => 'scheduled',
        ]);
        $this->assertSame(
            AssuranceStatus::ACTION_REQUIRED,
            $resolver->firstAidCoverageForSite($site->id, $start->copy()->subHour(), $end->copy()->addHour()),
        );

        [$status, $record] = $this->currentFirstAidCompetency($staff, $site);
        $this->assertSame(
            AssuranceStatus::CERTIFIED,
            $resolver->firstAidCoverageForSite($site->id, $start->copy()->subHour(), $end->copy()->addHour()),
        );

        $canonicalCertificatePath = $record->certificate_path;
        $replayedCertificatePath = 'hr/training/certificates/'.($record->id + 1000).'/replay.pdf';
        Storage::disk('private')->put($replayedCertificatePath, 'another training certificate');
        $record->forceFill(['certificate_path' => $replayedCertificatePath])->save();
        $this->assertSame(
            AssuranceStatus::ACTION_REQUIRED,
            $resolver->firstAidCoverageForSite($site->id, $start->copy()->subHour(), $end->copy()->addHour()),
        );
        $record->forceFill(['certificate_path' => $canonicalCertificatePath])->save();

        $record->forceFill(['expires_at' => $end->copy()->subMinute()])->save();
        $this->assertSame(
            AssuranceStatus::ACTION_REQUIRED,
            $resolver->firstAidCoverageForSite($site->id, $start->copy()->subHour(), $end->copy()->addHour()),
        );

        $record->forceFill(['expires_at' => $end->copy()->addMonth()])->save();
        $status->forceFill(['expires_at' => $end->copy()->addMonth()])->save();
        $unqualified = User::factory()->create();
        Shift::factory()->forSite($site)->create([
            'client_id' => null,
            'service_context_id' => null,
            'user_id' => $unqualified->id,
            'starts_at' => $start,
            'ends_at' => $end->copy()->addHour(),
            'status' => 'scheduled',
        ]);
        $this->assertSame(
            AssuranceStatus::ACTION_REQUIRED,
            $resolver->firstAidCoverageForSite($site->id, $start->copy()->subHour(), $end->copy()->addHours(2)),
        );

        $otherSite = Site::factory()->create();
        $staff->hrEmployeeProfile->forceFill(['primary_site_id' => $otherSite->id])->save();
        $this->assertSame(
            AssuranceStatus::ACTION_REQUIRED,
            $resolver->firstAidCoverageForSite($site->id, $start->copy()->subHour(), $end->copy()->addHours(2)),
        );

        $staff->hrEmployeeProfile->forceFill(['primary_site_id' => $site->id])->save();
        Storage::forgetDisk('private');
        config()->set('filesystems.disks.private', ['driver' => 'not-a-driver']);
        $this->assertSame(
            AssuranceStatus::UNKNOWN,
            $resolver->firstAidCoverageForSite($site->id, $start->copy()->subHour(), $end->copy()->addHours(2)),
        );
    }

    public function test_site_bound_and_explicit_global_users_receive_only_their_resolver_backed_scope(): void
    {
        $this->seed(RbacSeeder::class);
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $reviewer = User::factory()->create();
        $this->validCertification($siteA, $reviewer, 'site-a.pdf');
        $this->validCertification($siteB, $reviewer, 'site-b.pdf');

        $siteUser = $this->userWithPermissions(['hazards.view']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $siteUser->id,
            'primary_site_id' => $siteA->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);

        $this->actingAs($siteUser)
            ->get('/health-safety/events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('nzsAssurance.certification_status', AssuranceStatus::CERTIFIED->value));

        $this->actingAs($siteUser)
            ->get('/health-safety/events?site_id='.$siteB->id)
            ->assertForbidden();

        $globalUser = $this->userWithPermissions(['hazards.view', 'healthSafety.viewAllSites']);
        $this->actingAs($globalUser)
            ->get('/health-safety/events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('nzsAssurance.certification_status', AssuranceStatus::CERTIFIED->value));

        $unsubstantiatedSite = Site::factory()->create();
        SiteCertification::query()->create([
            'site_id' => $unsubstantiatedSite->id,
            'certification_type' => NzsAssuranceResolver::CERTIFICATION_TYPE,
            'name' => 'Unsubstantiated Site certification',
            'status' => 'current',
        ]);
        $siteAdmin = $this->userWithPermissions(['hazards.view', 'sites.viewAll']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $siteAdmin->id,
            'primary_site_id' => $siteA->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $this->actingAs($siteAdmin)
            ->get('/health-safety/events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('nzsAssurance.certification_status', AssuranceStatus::CERTIFIED->value));

        $siteComplianceUser = $this->userWithPermissions(['sites.viewAny']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $siteComplianceUser->id,
            'primary_site_id' => $unsubstantiatedSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $this->actingAs($siteComplianceUser)
            ->get("/sites/{$unsubstantiatedSite->id}/compliance")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('nzsAssurance.certification_status', AssuranceStatus::ACTION_REQUIRED->value)
                ->where('stats.current', 0));
    }

    public function test_concurrent_replacements_serialize_one_current_head_on_mysql(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $site = Site::factory()->create();
        $actor = User::factory()->create();
        $database = $connection->getDatabaseName();
        $token = Str::uuid()->toString();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."nzs-assurance-release-{$token}";
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."nzs-assurance-ready-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."nzs-assurance-ready-b-{$token}",
        ];
        $attemptPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."nzs-assurance-attempt-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."nzs-assurance-attempt-b-{$token}",
        ];
        $processes = [];
        $connection->commit();

        try {
            $connection->beginTransaction();
            Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            foreach (['CONCURRENT-A', 'CONCURRENT-B'] as $index => $reference) {
                $processes[] = $this->startCertificationWorker(
                    $site->id, $actor->id, $reference, $readyPaths[$index],
                    $attemptPaths[$index], $releasePath, $database,
                );
            }

            $this->waitForFiles($readyPaths, 'Concurrent certification workers did not connect.');
            touch($releasePath);
            $this->waitForFiles($attemptPaths, 'Concurrent certification workers did not reach the writer.');
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), trim($process->getErrorOutput()));
            }

            $connection->commit();
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()));
            }

            $history = SiteCertification::withTrashed()->where('site_id', $site->id)->orderBy('id')->get();
            $this->assertCount(2, $history);
            $this->assertCount(1, $history->filter(fn (SiteCertification $row) => ! $row->trashed()));
            $this->assertCount(1, $history->filter(fn (SiteCertification $row) => $row->trashed() && $row->revoked_at));
            $this->assertSame($history->first()->id, $history->last()->supersedes_certification_id);
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([...$readyPaths, ...$attemptPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            try {
                SiteCertification::withTrashed()->where('site_id', $site->id)
                    ->orderByDesc('id')->get()->each->forceDelete();
                DB::table('audit_logs')->where('auditable_type', SiteCertification::class)->delete();
                DB::table('users')->where('id', $actor->id)->delete();
                DB::table('sites')->where('id', $site->id)->delete();
            } finally {
                $connection->beginTransaction();
            }
        }
    }

    private function validCertification(Site $site, User $reviewer, string $filename): SiteCertification
    {
        $this->authoriseReviewerForSite($reviewer, $site);
        $path = 'site-certifications/'.$site->id.'/'.$filename;
        $contents = 'authoritative evidence '.$site->id.' '.$filename;
        Storage::disk('private')->put($path, $contents);

        return SiteCertification::query()->create([
            'site_id' => $site->id,
            'certification_type' => NzsAssuranceResolver::CERTIFICATION_TYPE,
            'name' => 'Ngā Paerewa certification',
            'issuing_body' => 'HealthCERT',
            'reference_number' => 'NZS-'.$site->id.'-'.$filename,
            'status' => 'current',
            'issued_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'document_path' => $path,
            'document_disk' => 'private',
            'evidence_sha256' => hash('sha256', $contents),
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'created_by' => $reviewer->id,
        ]);
    }

    private function authoriseReviewerForSite(User $reviewer, Site $site): void
    {
        $profile = $reviewer->hrEmployeeProfile()->first();
        if (! $profile) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $reviewer->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
            ]);
            $reviewer->unsetRelation('hrEmployeeProfile');

            return;
        }

        $sites = collect($profile->secondary_site_ids ?? [])
            ->push($profile->primary_site_id)
            ->push($site->id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $profile->forceFill([
            'primary_site_id' => $sites->shift(),
            'secondary_site_ids' => $sites->values()->all(),
            'is_active' => true,
        ])->save();
        $reviewer->unsetRelation('hrEmployeeProfile');
    }

    /** @return array{HrStaffComplianceStatus,StaffTrainingRecord} */
    private function currentFirstAidCompetency(User $staff, Site $site): array
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $staff->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'is_first_aider' => true,
        ]);
        $requirement = HrComplianceRequirement::factory()->create([
            'code' => NzsAssuranceResolver::FIRST_AID_REQUIREMENT_CODE,
            'is_active' => true,
        ]);
        $course = HrCourse::factory()->create([
            'code' => 'CURRENT-FIRST-AID',
            'compliance_requirement_id' => $requirement->id,
            'is_active' => true,
        ]);
        $legacyCourse = TrainingCourse::factory()->create([
            'code' => 'CURRENT-FIRST-AID-LEGACY',
            'active' => true,
        ]);
        $record = StaffTrainingRecord::query()->create([
            'user_id' => $staff->id,
            'training_course_id' => $legacyCourse->id,
            'hr_course_id' => $course->id,
            'status' => 'completed',
            'completed_at' => now()->subMonth(),
            'completion_date' => now()->subMonth()->toDateString(),
            'expires_at' => now()->addYear(),
            'certificate_number' => 'FA-'.$staff->id,
        ]);
        $path = 'hr/training/certificates/'.$record->id.'/first-aid.pdf';
        Storage::disk('private')->put($path, 'current first aid certificate');
        $record->forceFill(['certificate_path' => $path])->save();
        $status = HrStaffComplianceStatus::factory()->create([
            'user_id' => $staff->id,
            'requirement_id' => $requirement->id,
            'status' => 'compliant',
            'evidence_type' => 'training_record',
            'evidence_id' => $record->id,
            'valid_from' => now()->subMonth(),
            'expires_at' => now()->addYear(),
        ]);
        $staff->load('hrEmployeeProfile');

        return [$status, $record];
    }

    /** @param list<string> $permissionKeys */
    private function userWithPermissions(array $permissionKeys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync(
            $permissions->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]),
        );

        return $user;
    }

    private function startCertificationWorker(
        int $siteId, int $actorId, string $reference, string $readyPath,
        string $attemptPath, string $releasePath, string $database,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$site = App\Models\Site::query()->findOrFail((int) $argv[2]);
$actor = App\Models\User::query()->findOrFail((int) $argv[3]);
file_put_contents($argv[5], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[7])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the NZS assurance concurrency barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[6], 'attempting');
$row = $app->make(App\Services\Assurance\SiteCertificationService::class)->create($site, $actor, [
    'certification_type' => App\Services\Assurance\NzsAssuranceResolver::CERTIFICATION_TYPE,
    'name' => 'Concurrent certification',
    'reference_number' => $argv[4],
    'status' => 'current',
]);
echo json_encode(['id' => $row->id, 'reference' => $row->reference_number], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process([
            PHP_BINARY, '-r', $worker, base_path(), (string) $siteId, (string) $actorId,
            $reference, $readyPath, $attemptPath, $releasePath,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => $database,
            'QUEUE_CONNECTION' => 'sync',
        ]);
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /** @param list<string> $paths */
    private function waitForFiles(array $paths, string $message): void
    {
        $deadline = microtime(true) + 15;
        do {
            if (collect($paths)->every(fn (string $path) => is_file($path))) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException($message);
    }
}
