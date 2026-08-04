<?php

use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\OnboardingService;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function crossLoopProfile(): HrEmployeeProfile
{
    $user = User::factory()->create();

    return HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-CL-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => test()->site->id,
        'secondary_site_ids' => [],
        'start_date' => now()->addDays(10)->toDateString(),
        'is_active' => true,
    ]);
}

function crossLoopChecklist(HrEmployeeProfile $profile): HrOnboardingChecklist
{
    return HrOnboardingChecklist::query()->create([
        'employee_profile_id' => $profile->id,
        'template_key' => 'support_worker:all',
        'status' => 'in_progress',
        'started_at' => now(),
        'due_date' => now()->addDays(20),
        'created_by' => $profile->user_id,
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    $this->hr->roles()->firstWhere('name', 'hr')->permissions()->syncWithoutDetaching([
        Permission::query()->where('key', 'assets.viewAny')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'position_role' => 'hr',
        'is_active' => true,
        'start_date' => today()->subYear(),
        'end_date' => null,
    ]);
    $this->svc = app(OnboardingService::class);
});

test('generating a checklist auto-enrols the hire in mandatory training from induction tasks', function () {
    HrCourse::query()->create([
        'title' => 'Health & Safety Induction',
        'code' => 'HS-IND',
        'delivery_method' => 'online',
        'is_mandatory' => true,
        'is_active' => true,
    ]);

    HrOnboardingTemplate::query()->create([
        'role' => 'support_worker',
        'site_type' => 'all',
        'is_active' => true,
        'tasks' => [
            ['category' => 'induction', 'title' => 'Complete H&S induction', 'is_required' => true, 'sort_order' => 1],
        ],
    ]);

    $profile = crossLoopProfile();

    $checklist = $this->svc->generateChecklist($profile, $this->hr->id);

    $enrollments = HrCourseEnrollment::query()
        ->where('user_id', $profile->user_id)
        ->where('status', 'enrolled')
        ->count();
    expect($enrollments)->toBe(1);

    // A later onboarding cycle must not double-enrol, while duplicate active
    // checklists remain prohibited by the lifecycle service.
    $this->svc->setChecklistStatus($checklist, 'archived');
    $this->svc->generateChecklist($profile, $this->hr->id);
    expect(HrCourseEnrollment::query()->where('user_id', $profile->user_id)->count())->toBe(1);
});

test('completing a task with an evidence file creates a gated HrDocument', function () {
    Storage::fake('private');
    $checklist = crossLoopChecklist(crossLoopProfile());
    $task = HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id,
        'category' => 'general',
        'title' => 'Signed agreement',
        'is_required' => false,
        'sort_order' => 1,
        'status' => 'pending',
    ]);

    $file = UploadedFile::fake()->create('agreement.pdf', 20, 'application/pdf');

    $this->svc->completeTask($task, $this->hr->id, ['evidence_file' => $file]);

    $task->refresh();
    expect($task->status)->toBe('completed');
    expect($task->hr_document_id)->not->toBeNull();

    $doc = HrDocument::query()->find($task->hr_document_id);
    expect($doc)->not->toBeNull();
    expect($doc->category)->toBe('onboarding');
    expect($doc->storage_disk)->toBe('private');
    expect($doc->employee_profile_id)->toBe($checklist->employee_profile_id);
    Storage::disk('private')->assertExists($doc->storage_path);
});

test('a sign-off task with evidence mints a pending signature request', function () {
    Storage::fake('private');
    $checklist = crossLoopChecklist(crossLoopProfile());
    $task = HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id,
        'category' => 'compliance',
        'title' => 'Police vet',
        'is_required' => true,
        'sort_order' => 1,
        'sign_off_required' => true,
        'status' => 'pending',
    ]);

    $file = UploadedFile::fake()->create('vet.pdf', 10, 'application/pdf');

    $this->svc->completeTask($task, $this->hr->id, [
        'evidence_file' => $file,
        'signed_off_by' => $this->hr->id,
    ]);

    $task->refresh();
    $sig = HrDocumentSignature::query()->where('document_id', $task->hr_document_id)->first();
    expect($sig)->not->toBeNull();
    expect($sig->status)->toBe('pending');
    expect((int) $sig->signer_user_id)->toBe($this->hr->id);
});

test('provisioning an asset assigns it and completes the IT task, idempotently', function () {
    $profile = crossLoopProfile();
    $checklist = crossLoopChecklist($profile);
    // A required task keeps the checklist in_progress across both provisions.
    HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id, 'category' => 'general', 'title' => 'Guard', 'is_required' => true, 'sort_order' => 1, 'status' => 'pending',
    ]);
    $task1 = HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id, 'category' => 'it', 'title' => 'Issue laptop', 'is_required' => false, 'sort_order' => 2, 'status' => 'pending',
    ]);
    $task2 = HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id, 'category' => 'it', 'title' => 'Issue laptop (again)', 'is_required' => false, 'sort_order' => 3, 'status' => 'pending',
    ]);

    $asset = Asset::query()->create(['name' => 'MacBook Pro', 'asset_tag' => 'IT-001', 'status' => 'active']);

    $this->svc->provisionAssetForTask($task1, $asset, $this->hr->id);
    expect($task1->fresh()->status)->toBe('completed');

    $assignment = AssetAssignment::query()->where('asset_id', $asset->id)->whereNull('released_at')->first();
    expect($assignment)->not->toBeNull();
    expect($assignment->assignee_type)->toBe('staff');
    expect((int) $assignment->assignee_id)->toBe($profile->user_id);
    expect($task1->fresh()->notes)->toContain("asset_assignment_id={$assignment->id}");

    // Issuing the SAME asset for another task reuses the active assignment.
    $this->svc->provisionAssetForTask($task2, $asset, $this->hr->id);
    expect(AssetAssignment::query()->where('asset_id', $asset->id)->count())->toBe(1);
    expect($task2->fresh()->status)->toBe('completed');
});

test('auto-pick chooses a free asset and skips retired or already-assigned ones', function () {
    // Retired → skipped; already-assigned → skipped; only the free one qualifies.
    Asset::query()->create(['name' => 'Old Laptop', 'asset_tag' => 'IT-OLD', 'status' => 'retired']);
    $taken = Asset::query()->create(['name' => 'Taken Laptop', 'asset_tag' => 'IT-TKN', 'status' => 'active']);
    AssetAssignment::query()->create([
        'asset_id' => $taken->id, 'assignee_type' => 'staff', 'assignee_id' => 999, 'assigned_at' => now(),
    ]);
    $free = Asset::query()->create(['name' => 'Free Laptop', 'asset_tag' => 'IT-FREE', 'status' => 'active']);

    $picked = $this->svc->autoPickAvailableAsset();
    expect($picked)->not->toBeNull();
    expect($picked->id)->toBe($free->id);

    // Releasing the taken asset makes it eligible again (lowest id wins).
    AssetAssignment::query()->where('asset_id', $taken->id)->update(['released_at' => now()]);
    expect($this->svc->autoPickAvailableAsset()->id)->toBe($taken->id);
});

test('the provision endpoint auto-picks when no asset_id is given', function () {
    $profile = crossLoopProfile();
    $checklist = crossLoopChecklist($profile);
    HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id, 'category' => 'general', 'title' => 'Guard', 'is_required' => true, 'sort_order' => 1, 'status' => 'pending',
    ]);
    $task = HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id, 'category' => 'it', 'title' => 'Issue laptop', 'is_required' => false, 'sort_order' => 2, 'status' => 'pending',
    ]);
    $free = Asset::query()->create([
        'site_id' => $this->site->id,
        'name' => 'Auto Laptop',
        'asset_tag' => 'IT-AUTO',
        'status' => 'active',
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/onboarding/tasks/{$task->id}/provision-asset", [])
        ->assertRedirect();

    expect($task->fresh()->status)->toBe('completed');
    expect(AssetAssignment::query()->where('asset_id', $free->id)->whereNull('released_at')->count())->toBe(1);
});

test('evidence storage is cleaned up when task completion rolls back', function () {
    Storage::fake('private');

    $profile = crossLoopProfile();
    $checklist = crossLoopChecklist($profile);
    $task = HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id,
        'category' => 'induction',
        'title' => 'Upload evidence before rollback',
        'is_required' => true,
        'sort_order' => 1,
        'status' => 'pending',
    ]);
    $service = new class(app(HrCurrentStaffService::class), app(UserSiteAccessService::class)) extends OnboardingService
    {
        protected function checkChecklistCompletion(HrOnboardingChecklist $checklist): void
        {
            throw new RuntimeException('Force transaction rollback after evidence storage.');
        }
    };

    expect(fn () => $service->completeTask($task, $this->hr->id, [
        'evidence_file' => UploadedFile::fake()->create('induction.pdf', 32, 'application/pdf'),
    ]))->toThrow(RuntimeException::class, 'Force transaction rollback after evidence storage.');

    expect($task->fresh()->status)->toBe('pending')
        ->and($task->fresh()->hr_document_id)->toBeNull()
        ->and(HrDocument::query()->where('employee_profile_id', $profile->id)->exists())->toBeFalse()
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});
