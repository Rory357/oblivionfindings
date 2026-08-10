<?php

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Domain\Hr\Services\HrComplianceReminderDeliveryService;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Hr\ComplianceController;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ComplianceReminderNotification;
use App\Services\UserSiteAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class FailingComplianceStatusPersistController extends ComplianceController
{
    protected function persistComplianceStatus(HrStaffComplianceStatus $status): void
    {
        throw new RuntimeException('Forced compliance persistence failure.');
    }
}

class EndingComplianceSelectionLockService extends PeopleMutationLockService
{
    public function __construct(private readonly int $targetProfileId) {}

    public function lock(iterable $userIds, iterable $profileIds = []): array
    {
        HrEmployeeProfile::query()->whereKey($this->targetProfileId)->update([
            'end_date' => now()->subDay()->toDateString(),
        ]);

        return parent::lock($userIds, $profileIds);
    }
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->complianceMutationSite = Site::factory()->create(['name' => 'Compliance Mutation Site']);
    $this->complianceMutationHiddenSite = Site::factory()->create(['name' => 'Hidden Compliance Mutation Site']);
    $this->complianceMutationViewer = complianceMutationStaff(
        'Compliance Mutation Manager',
        $this->complianceMutationSite,
        ['role' => 'hr'],
        ['position_role' => 'hr'],
    );
    $this->complianceMutationViewer->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->complianceMutationAllowed = complianceMutationStaff(
        'Allowed Compliance Mutation Worker',
        $this->complianceMutationSite,
    );
    $this->complianceMutationHidden = complianceMutationStaff(
        'Hidden Compliance Mutation Worker',
        $this->complianceMutationHiddenSite,
    );
    $this->complianceMutationEnded = complianceMutationStaff(
        'Ended Compliance Mutation Worker',
        $this->complianceMutationSite,
        [],
        ['end_date' => now()->subDay()->toDateString()],
    );
    $this->complianceMutationRequirement = HrComplianceRequirement::query()->create([
        'code' => 'CANONICAL-MUTATION',
        'name' => 'Canonical mutation requirement',
        'category' => 'training',
        'check_type' => 'manual',
        'hard_stop' => false,
        'is_active' => true,
        'created_by' => $this->complianceMutationViewer->id,
    ]);
});

function complianceMutationStaff(
    string $name,
    Site $site,
    array $userOverrides = [],
    array $profileOverrides = [],
): User {
    $user = User::factory()->create([
        'name' => $name,
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-COMP-MUT-'.$user->id,
        'work_email' => str($name)->slug().'@work.example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        ...$profileOverrides,
    ]);

    return $user;
}

function complianceMutationStatus(
    User $staff,
    HrComplianceRequirement $requirement,
    array $overrides = [],
): HrStaffComplianceStatus {
    return HrStaffComplianceStatus::query()->create([
        'user_id' => $staff->id,
        'requirement_id' => $requirement->id,
        'status' => 'expired',
        'evidence_type' => 'manual',
        ...$overrides,
    ]);
}

test('recording a status conceals inaccessible staff before payload validation', function () {
    config(['app.debug' => false]);

    $this->actingAs($this->complianceMutationViewer)
        ->post('/hr/compliance/staff/'.$this->complianceMutationAllowed->id.'/status', [
            'requirement_id' => $this->complianceMutationRequirement->id,
            'status' => 'compliant',
        ])
        ->assertSessionHas('success');
    $this->assertDatabaseHas('hr_staff_compliance_status', [
        'user_id' => $this->complianceMutationAllowed->id,
        'requirement_id' => $this->complianceMutationRequirement->id,
        'status' => 'compliant',
    ]);

    $responses = [
        $this->actingAs($this->complianceMutationViewer)
            ->postJson('/hr/compliance/staff/'.$this->complianceMutationHidden->id.'/status', []),
        $this->actingAs($this->complianceMutationViewer)
            ->postJson('/hr/compliance/staff/'.$this->complianceMutationEnded->id.'/status', []),
        $this->actingAs($this->complianceMutationViewer)
            ->postJson('/hr/compliance/staff/99999999/status', []),
        $this->actingAs($this->complianceMutationViewer)
            ->postJson('/hr/compliance/staff/not-a-number/status', []),
    ];
    expect(collect($responses)->map(fn ($response) => [
        'status' => $response->status(),
        'body' => $response->json(),
    ])->unique()->values()->all())->toBe([
        ['status' => 404, 'body' => ['message' => '']],
    ]);
});

test('status update exemption and evidence routes conceal records owned by inaccessible staff', function () {
    config(['app.debug' => false]);
    Storage::fake('private');
    $allowedPath = 'hr-compliance/evidence/allowed.txt';
    $hiddenPath = 'hr-compliance/evidence/hidden.txt';
    Storage::disk('private')->put($allowedPath, 'allowed evidence');
    Storage::disk('private')->put($hiddenPath, 'hidden evidence');
    $allowedStatus = complianceMutationStatus($this->complianceMutationAllowed, $this->complianceMutationRequirement, [
        'evidence_disk' => 'private',
        'evidence_path' => $allowedPath,
        'evidence_filename' => 'allowed.txt',
        'evidence_mime' => 'text/plain',
    ]);
    $hiddenStatus = complianceMutationStatus($this->complianceMutationHidden, $this->complianceMutationRequirement, [
        'evidence_disk' => 'private',
        'evidence_path' => $hiddenPath,
        'evidence_filename' => 'hidden.txt',
        'evidence_mime' => 'text/plain',
    ]);

    $this->actingAs($this->complianceMutationViewer)
        ->get('/hr/compliance/status/'.$allowedStatus->id.'/evidence')
        ->assertOk()
        ->assertStreamedContent('allowed evidence');

    $this->actingAs($this->complianceMutationViewer)
        ->putJson('/hr/compliance/status/'.$hiddenStatus->id, [])
        ->assertNotFound();
    $this->actingAs($this->complianceMutationViewer)
        ->postJson('/hr/compliance/status/'.$hiddenStatus->id.'/exempt', [
            'exemption_reason' => 'Must not apply',
            'acknowledge' => true,
        ])
        ->assertNotFound();
    $this->actingAs($this->complianceMutationViewer)
        ->getJson('/hr/compliance/status/'.$hiddenStatus->id.'/evidence')
        ->assertNotFound();

    expect($hiddenStatus->fresh()->status)->toBe('expired')
        ->and($hiddenStatus->fresh()->exemption_reason)->toBeNull();
});

test('hidden missing and malformed compliance status IDs have identical mutation concealment', function () {
    config(['app.debug' => false]);
    $hiddenStatus = complianceMutationStatus($this->complianceMutationHidden, $this->complianceMutationRequirement);
    $endedStatus = complianceMutationStatus($this->complianceMutationEnded, $this->complianceMutationRequirement);

    $responses = [
        $this->actingAs($this->complianceMutationViewer)
            ->putJson('/hr/compliance/status/'.$hiddenStatus->id, []),
        $this->actingAs($this->complianceMutationViewer)
            ->putJson('/hr/compliance/status/'.$endedStatus->id, []),
        $this->actingAs($this->complianceMutationViewer)
            ->putJson('/hr/compliance/status/99999999', []),
        $this->actingAs($this->complianceMutationViewer)
            ->putJson('/hr/compliance/status/not-a-number', []),
        $this->actingAs($this->complianceMutationViewer)
            ->putJson('/hr/compliance/status/'.str_repeat('9', 80), []),
    ];

    expect(collect($responses)->map(fn ($response) => [
        'status' => $response->status(),
        'body' => $response->json(),
    ])->unique()->values()->all())->toBe([
        ['status' => 404, 'body' => ['message' => '']],
    ]);
});

test('mixed visible and hidden bulk compliance actions fail atomically', function () {
    Notification::fake();
    $payload = [
        'user_ids' => [$this->complianceMutationAllowed->id, $this->complianceMutationHidden->id],
    ];

    $this->actingAs($this->complianceMutationViewer)
        ->post('/hr/compliance/bulk-record', [
            ...$payload,
            'requirement_id' => $this->complianceMutationRequirement->id,
            'status' => 'compliant',
        ])
        ->assertSessionHasErrors('user_ids');
    expect(HrStaffComplianceStatus::query()
        ->where('requirement_id', $this->complianceMutationRequirement->id)
        ->count())->toBe(0);

    $this->actingAs($this->complianceMutationViewer)
        ->post('/hr/compliance/bulk-remind', $payload)
        ->assertSessionHasErrors('user_ids');
    Notification::assertNothingSent();

    $this->actingAs($this->complianceMutationViewer)
        ->post('/hr/compliance/bulk-exempt', [
            ...$payload,
            'requirement_id' => $this->complianceMutationRequirement->id,
            'exemption_reason' => 'Must be atomic',
            'acknowledge' => true,
        ])
        ->assertSessionHasErrors('user_ids');
    expect(HrStaffComplianceStatus::query()
        ->where('requirement_id', $this->complianceMutationRequirement->id)
        ->count())->toBe(0);
});

test('bulk compliance actions succeed for a fully visible current selection', function () {
    Notification::fake();
    $second = complianceMutationStaff('Second Allowed Compliance Worker', $this->complianceMutationSite);
    $ids = [$this->complianceMutationAllowed->id, $second->id];

    $this->actingAs($this->complianceMutationViewer)
        ->post('/hr/compliance/bulk-record', [
            'user_ids' => $ids,
            'requirement_id' => $this->complianceMutationRequirement->id,
            'status' => 'compliant',
        ])
        ->assertSessionHas('success');
    expect(HrStaffComplianceStatus::query()
        ->where('requirement_id', $this->complianceMutationRequirement->id)
        ->whereIn('user_id', $ids)
        ->count())->toBe(2);

    $this->actingAs($this->complianceMutationViewer)
        ->post('/hr/compliance/bulk-remind', ['user_ids' => $ids])
        ->assertSessionHas('success');
    Notification::assertSentTo($this->complianceMutationAllowed, ComplianceReminderNotification::class);
    Notification::assertSentTo($second, ComplianceReminderNotification::class);
});

test('bulk compliance selection is reauthorised after the shared People lock', function () {
    $this->app->instance(
        PeopleMutationLockService::class,
        new EndingComplianceSelectionLockService(
            $this->complianceMutationAllowed->hrEmployeeProfile->id,
        ),
    );

    $this->actingAs($this->complianceMutationViewer)
        ->post('/hr/compliance/bulk-record', [
            'user_ids' => [$this->complianceMutationAllowed->id],
            'requirement_id' => $this->complianceMutationRequirement->id,
            'status' => 'compliant',
        ])
        ->assertSessionHasErrors('user_ids');

    expect(HrStaffComplianceStatus::query()
        ->where('requirement_id', $this->complianceMutationRequirement->id)
        ->count())->toBe(0);
});

test('compliance evidence replacement deletes the old private file only after commit', function () {
    Storage::fake('private');
    $oldPath = 'hr-compliance/evidence/old-evidence.pdf';
    Storage::disk('private')->put($oldPath, 'old evidence');
    $status = complianceMutationStatus($this->complianceMutationAllowed, $this->complianceMutationRequirement, [
        'evidence_disk' => 'private',
        'evidence_path' => $oldPath,
        'evidence_filename' => 'old-evidence.pdf',
        'evidence_mime' => 'application/pdf',
    ]);

    $this->actingAs($this->complianceMutationViewer)
        ->put('/hr/compliance/status/'.$status->id, [
            'status' => 'compliant',
            'evidence_file' => UploadedFile::fake()->create('new-evidence.pdf', 20, 'application/pdf'),
        ])
        ->assertSessionHas('success');

    $newPath = $status->fresh()->evidence_path;
    expect($newPath)->not->toBe($oldPath);
    Storage::disk('private')->assertExists($newPath);
    Storage::disk('private')->assertMissing($oldPath);
});

test('a rolled back compliance evidence update removes the new file and preserves the old file', function () {
    Storage::fake('private');
    $oldPath = 'hr-compliance/evidence/committed-old.pdf';
    Storage::disk('private')->put($oldPath, 'committed old evidence');
    $status = complianceMutationStatus($this->complianceMutationAllowed, $this->complianceMutationRequirement, [
        'evidence_disk' => 'private',
        'evidence_path' => $oldPath,
        'evidence_filename' => 'committed-old.pdf',
        'evidence_mime' => 'application/pdf',
    ]);
    $this->app->bind(ComplianceController::class, fn () => new FailingComplianceStatusPersistController(
        app(ComplianceMatrixService::class),
        app(UserSiteAccessService::class),
        app(PeopleMutationLockService::class),
        app(HrComplianceReminderDeliveryService::class),
    ));
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->complianceMutationViewer)
        ->put('/hr/compliance/status/'.$status->id, [
            'status' => 'compliant',
            'evidence_file' => UploadedFile::fake()->create('rolled-back.pdf', 20, 'application/pdf'),
        ]))->toThrow(RuntimeException::class, 'Forced compliance persistence failure.');

    expect($status->fresh()->evidence_path)->toBe($oldPath);
    Storage::disk('private')->assertExists($oldPath);
    expect(Storage::disk('private')->allFiles())->toBe([$oldPath]);
});
