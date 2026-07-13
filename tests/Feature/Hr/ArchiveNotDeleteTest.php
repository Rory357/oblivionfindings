<?php

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyVersion;
use App\Domain\Hr\Models\HrSuccessionCandidate;
use App\Domain\Hr\Models\HrSuccessionPlan;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

function c4ArchiveDocument(HrEmployeeProfile $profile, User $actor, string $name): HrDocument
{
    $path = "hr-documents/{$profile->tenant_id}/{$profile->id}/{$name}.pdf";
    Storage::disk('private')->put($path, '%PDF-1.4 retained');

    return HrDocument::query()->create([
        'tenant_id' => $profile->tenant_id,
        'employee_profile_id' => $profile->id,
        'title' => $name,
        'category' => 'contract',
        'folder' => 'Contracts',
        'storage_disk' => 'private',
        'storage_path' => $path,
        'original_name' => "{$name}.pdf",
        'mime_type' => 'application/pdf',
        'size_bytes' => 17,
        'created_by' => $actor->id,
        'uploaded_by' => $actor->id,
    ]);
}

beforeEach(function () {
    Storage::fake('private');
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);

    $this->manager = User::factory()->create([
        'organization_id' => 1,
        'role' => 'provider_manager',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'provider_manager')->firstOrFail()->id,
    ]);

    $this->worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->profile = HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'employee_number' => 'C4-'.$this->worker->id,
        'work_email' => $this->worker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
});

test('C4: deleting an onboarding checklist archives it and retains its tasks', function () {
    $checklist = HrOnboardingChecklist::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $this->profile->id,
        'template_key' => 'c4-retention',
        'status' => 'in_progress',
        'started_at' => now(),
        'due_date' => now()->addWeek()->toDateString(),
        'created_by' => $this->hr->id,
    ]);
    $task = $checklist->tasks()->create([
        'category' => 'general',
        'title' => 'Retain this onboarding evidence',
        'is_required' => true,
        'sort_order' => 1,
        'status' => 'pending',
        'dependency_task_ids' => [],
        'sign_off_required' => false,
    ]);

    $this->actingAs($this->hr)
        ->delete("/hr/onboarding/{$checklist->id}")
        ->assertSessionHas('success');

    expect($checklist->fresh())->not->toBeNull();
    expect($checklist->fresh()->status)->toBe('archived');
    expect($task->fresh())->not->toBeNull();
});

test('C4: deleting a draft wellbeing survey archives it and retains its questions', function () {
    $survey = HrEngagementSurvey::query()->create([
        'tenant_id' => 1,
        'title' => 'Draft wellbeing pulse',
        'survey_type' => 'pulse',
        'status' => 'draft',
        'is_anonymous' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $question = $survey->questions()->create([
        'question_type' => 'scale',
        'question_text' => 'How supported do you feel?',
        'is_required' => true,
        'sort_order' => 1,
    ]);

    $this->actingAs($this->hr)
        ->delete("/hr/wellbeing/surveys/{$survey->id}")
        ->assertSessionHas('success');

    expect($survey->fresh())->not->toBeNull();
    expect($survey->fresh()->status)->toBe('archived');
    expect($question->fresh())->not->toBeNull();
});

test('C4: deleting a policy deactivates it and retains versions and stored files', function () {
    $path = 'policies/1/c4-retention.pdf';
    Storage::disk('private')->put($path, '%PDF-1.4 retained');
    $policy = HrPolicy::query()->create([
        'tenant_id' => 1,
        'title' => 'Retention policy',
        'slug' => 'c4-retention-policy',
        'category' => 'employment',
        'is_active' => true,
        'requires_attestation' => true,
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);
    $version = HrPolicyVersion::query()->create([
        'policy_id' => $policy->id,
        'version_number' => 1,
        'content_summary' => 'Retain this published history.',
        'document_path' => $path,
        'effective_from' => now()->subMonth()->toDateString(),
        'is_current' => true,
        'published_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->delete("/hr/documents/policies/{$policy->id}")
        ->assertSessionHas('success');

    expect($policy->fresh())->not->toBeNull();
    expect($policy->fresh()->is_active)->toBeFalse();
    expect($version->fresh())->not->toBeNull();
    Storage::disk('private')->assertExists($path);
});

test('C4: deleting a succession plan deactivates it and retains candidates', function () {
    $plan = HrSuccessionPlan::query()->create([
        'tenant_id' => 1,
        'role_title' => 'Service Manager',
        'risk_level' => 'high',
        'is_active' => true,
        'created_by' => $this->hr->id,
    ]);
    $candidate = HrSuccessionCandidate::query()->create([
        'succession_plan_id' => $plan->id,
        'employee_profile_id' => $this->profile->id,
        'readiness' => 'ready_1_year',
        'assessed_by' => $this->hr->id,
        'assessed_at' => now()->toDateString(),
    ]);

    $this->actingAs($this->hr)
        ->delete("/hr/succession/{$plan->id}")
        ->assertSessionHas('success');

    expect($plan->fresh())->not->toBeNull();
    expect($plan->fresh()->is_active)->toBeFalse();
    expect($candidate->fresh())->not->toBeNull();
});

test('C4: every HR document removal path archives the row and retains the file', function () {
    $single = c4ArchiveDocument($this->profile, $this->manager, 'single-delete');
    $bulk = c4ArchiveDocument($this->profile, $this->manager, 'bulk-delete');
    $profileDocument = c4ArchiveDocument($this->profile, $this->manager, 'profile-delete');

    $this->actingAs($this->manager)
        ->delete("/hr/documents/{$single->id}")
        ->assertSessionHas('success');
    $this->actingAs($this->manager)
        ->post('/hr/documents/bulk-delete', ['ids' => [$bulk->id]])
        ->assertSessionHas('success');
    $this->actingAs($this->manager)
        ->delete("/hr/people/{$this->profile->id}/documents/{$profileDocument->id}")
        ->assertSessionHas('success');

    foreach ([$single, $bulk, $profileDocument] as $document) {
        expect($document->fresh())->not->toBeNull();
        expect($document->fresh()->folder)->toBe('Archive');
        Storage::disk('private')->assertExists($document->storage_path);
    }
});
