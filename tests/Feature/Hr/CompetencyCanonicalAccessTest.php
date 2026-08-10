<?php

use App\Domain\Hr\Models\HrCompetency;
use App\Domain\Hr\Models\HrCompetencyAssessment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    Storage::fake('private');

    $this->site = Site::factory()->create(['name' => 'Competency visible Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Competency hidden Site']);

    $this->hr = User::factory()->create([
        'name' => 'Competency HR manager',
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->employee = User::factory()->create([
        'name' => 'Visible competency employee',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->otherEmployee = User::factory()->create([
        'name' => 'Other visible competency employee',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->hiddenEmployee = User::factory()->create([
        'name' => 'Hidden competency employee',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->formerEmployee = User::factory()->create([
        'name' => 'Former competency employee',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->hrProfile = competencyCanonicalProfile($this->hr, $this->site);
    $this->profile = competencyCanonicalProfile($this->employee, $this->site);
    $this->otherProfile = competencyCanonicalProfile($this->otherEmployee, $this->site);
    $this->hiddenProfile = competencyCanonicalProfile($this->hiddenEmployee, $this->hiddenSite);
    $this->formerProfile = competencyCanonicalProfile($this->formerEmployee, $this->site, [
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);
});

function competencyCanonicalProfile(User $user, Site $site, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        ...$overrides,
    ]);
}

function competencyCanonicalFramework(string $name, array $overrides = []): HrCompetency
{
    return HrCompetency::query()->create([
        'name' => $name,
        'description' => "{$name} description",
        'category' => 'Clinical',
        'proficiency_levels' => ['Aware', 'Developing', 'Competent', 'Advanced', 'Expert'],
        'is_active' => true,
        'sort_order' => 1,
        ...$overrides,
    ]);
}

function competencyCanonicalReview(User $employee, User $reviewer): HrPerformanceReview
{
    return HrPerformanceReview::query()->create([
        'employee_user_id' => $employee->id,
        'reviewer_user_id' => $reviewer->id,
        'review_type' => 'annual',
        'review_period_start' => '2026-01-01',
        'review_period_end' => '2026-06-30',
        'status' => 'draft',
        'created_by' => $reviewer->id,
    ]);
}

test('competency catalogue is application global and its profile links use canonical profile ids', function (): void {
    $first = competencyCanonicalFramework('Safe medication support');
    $second = competencyCanonicalFramework('Incident documentation', ['sort_order' => 2]);

    $response = $this->actingAs($this->hr)
        ->get('/hr/performance/competencies')
        ->assertOk();

    $competencies = collect($response->inertiaProps('competencies'));
    $staff = collect($response->inertiaProps('staff'));
    expect($competencies->pluck('id'))->toContain($first->id, $second->id)
        ->and($staff->pluck('id'))->toContain($this->profile->id, $this->otherProfile->id)
        ->not->toContain($this->hiddenProfile->id, $this->formerProfile->id)
        ->and($staff->firstWhere('id', $this->profile->id)['name'])->toBe($this->employee->name);
    foreach ($first->getHidden() as $hiddenField) {
        expect($competencies->firstWhere('id', $first->id))->not->toHaveKey($hiddenField);
    }

    $this->actingAs($this->hr)
        ->post('/hr/performance/competencies', [
            'name' => 'Application framework',
            'category' => 'Behavioural',
            'proficiency_levels' => ['Aware', 'Competent'],
        ])
        ->assertRedirect();

    $created = HrCompetency::query()->where('name', 'Application framework')->firstOrFail();
    foreach ($created->getHidden() as $hiddenField) {
        expect($created->toArray())->not->toHaveKey($hiddenField);
    }
});

test('assessment picker and subject resolution require current visible staff', function (): void {
    competencyCanonicalFramework('Safe transfers');

    $response = $this->actingAs($this->hr)
        ->get('/hr/performance/competencies/assess')
        ->assertOk();
    $staffIds = collect($response->inertiaProps('staff'))->pluck('id');
    expect($staffIds)->toContain($this->employee->id, $this->otherEmployee->id)
        ->not->toContain($this->hiddenEmployee->id, $this->formerEmployee->id);

    $payload = [
        'assessments' => [[
            'competency_id' => HrCompetency::query()->firstOrFail()->id,
            'proficiency_level' => 3,
        ]],
    ];

    $this->actingAs($this->hr)
        ->post('/hr/performance/competencies/assess', [
            'employee_user_id' => $this->hiddenEmployee->id,
            ...$payload,
        ])
        ->assertNotFound();
    $this->actingAs($this->hr)
        ->post('/hr/performance/competencies/assess', [
            'employee_user_id' => $this->formerEmployee->id,
            ...$payload,
        ])
        ->assertNotFound();

    expect(HrCompetencyAssessment::query()->count())->toBe(0);
});

test('assessment writes use active application competencies and a matching visible review', function (): void {
    $active = competencyCanonicalFramework('Positive behaviour support');
    $inactive = competencyCanonicalFramework('Retired framework', ['is_active' => false]);
    $matchingReview = competencyCanonicalReview($this->employee, $this->hr);
    $mismatchedReview = competencyCanonicalReview($this->otherEmployee, $this->hr);
    $hiddenReview = competencyCanonicalReview($this->hiddenEmployee, $this->hr);

    $base = [
        'employee_user_id' => $this->employee->id,
        'assessments' => [[
            'competency_id' => $active->id,
            'proficiency_level' => 3,
            'target_level' => 4,
            'notes' => 'Observed in practice.',
        ]],
    ];

    $this->actingAs($this->hr)
        ->post('/hr/performance/competencies/assess', [
            ...$base,
            'assessments' => [[
                'competency_id' => $inactive->id,
                'proficiency_level' => 2,
            ]],
        ])
        ->assertSessionHasErrors('assessments');
    $this->actingAs($this->hr)
        ->post('/hr/performance/competencies/assess', [
            ...$base,
            'performance_review_id' => $mismatchedReview->id,
        ])
        ->assertSessionHasErrors('performance_review_id');
    $this->actingAs($this->hr)
        ->post('/hr/performance/competencies/assess', [
            ...$base,
            'performance_review_id' => $hiddenReview->id,
        ])
        ->assertNotFound();

    $this->actingAs($this->hr)
        ->post('/hr/performance/competencies/assess', [
            ...$base,
            'performance_review_id' => $matchingReview->id,
        ])
        ->assertSessionHas('success');

    $assessment = HrCompetencyAssessment::query()->sole();
    expect($assessment->employee_profile_id)->toBe($this->profile->id)
        ->and($assessment->competency_id)->toBe($active->id)
        ->and($assessment->performance_review_id)->toBe($matchingReview->id);
    foreach ($assessment->getHidden() as $hiddenField) {
        expect($assessment->toArray())->not->toHaveKey($hiddenField);
    }
});

test('hidden assessment histories and evidence routes are concealed before mutation', function (): void {
    $framework = competencyCanonicalFramework('Hidden framework');
    $assessment = HrCompetencyAssessment::query()->create([
        'employee_profile_id' => $this->hiddenProfile->id,
        'competency_id' => $framework->id,
        'assessed_by' => $this->hr->id,
        'assessed_level' => 4,
        'assessment_date' => today(),
        'evidence_path' => 'hr/competency-assessments/hidden/evidence.pdf',
    ]);
    Storage::disk('private')->put($assessment->evidence_path, 'hidden evidence');

    $this->actingAs($this->hr)
        ->get("/hr/performance/competencies/{$this->hiddenProfile->id}")
        ->assertNotFound();
    $this->actingAs($this->hr)
        ->post("/hr/performance/competencies/assessments/{$assessment->id}/sign-off")
        ->assertNotFound();
    $this->actingAs($this->hr)
        ->post("/hr/performance/competencies/assessments/{$assessment->id}/evidence", [
            'file' => UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf'),
        ])
        ->assertNotFound();
    $this->actingAs($this->hr)
        ->get("/hr/performance/competencies/assessments/{$assessment->id}/evidence")
        ->assertNotFound();

    expect($assessment->fresh()->assessor_declared_at)->toBeNull()
        ->and($assessment->fresh()->evidence_path)->toBe('hr/competency-assessments/hidden/evidence.pdf')
        ->and(Storage::disk('private')->allFiles())->toBe(['hr/competency-assessments/hidden/evidence.pdf']);
});

test('visible evidence replacement is commit safe and assessment sign off is idempotent', function (): void {
    $framework = competencyCanonicalFramework('Visible framework');
    $oldPath = 'hr/competency-assessments/visible/old.pdf';
    Storage::disk('private')->put($oldPath, 'old evidence');
    $assessment = HrCompetencyAssessment::query()->create([
        'employee_profile_id' => $this->profile->id,
        'competency_id' => $framework->id,
        'assessed_by' => $this->hr->id,
        'assessed_level' => 3,
        'assessment_date' => today(),
        'evidence_path' => $oldPath,
    ]);

    $this->actingAs($this->hr)
        ->get("/hr/performance/competencies/{$this->profile->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('profile.id', $this->profile->id)
            ->where('latestAssessments.0.id', $assessment->id));
    $this->actingAs($this->hr)
        ->get("/hr/performance/competencies/assessments/{$assessment->id}/evidence")
        ->assertOk();
    $this->actingAs($this->hr)
        ->post("/hr/performance/competencies/assessments/{$assessment->id}/evidence", [
            'file' => UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf'),
        ])
        ->assertSessionHas('success');

    $newPath = $assessment->fresh()->evidence_path;
    expect($newPath)->not->toBe($oldPath);
    Storage::disk('private')->assertMissing($oldPath);
    Storage::disk('private')->assertExists($newPath);

    $this->actingAs($this->hr)
        ->post("/hr/performance/competencies/assessments/{$assessment->id}/sign-off")
        ->assertSessionHas('success');
    $signedAt = $assessment->fresh()->assessor_declared_at;
    $this->actingAs($this->hr)
        ->post("/hr/performance/competencies/assessments/{$assessment->id}/sign-off")
        ->assertSessionHas('success');

    expect($signedAt)->not->toBeNull()
        ->and($assessment->fresh()->assessor_declared_at->equalTo($signedAt))->toBeTrue();
});
