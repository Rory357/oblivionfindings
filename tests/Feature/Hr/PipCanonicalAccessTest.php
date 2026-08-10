<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Notification::fake();

    $this->site = Site::factory()->create(['name' => 'PIP Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden PIP Site']);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->employee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->stranger = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->hiddenEmployee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);

    pipCanonicalProfile($this->hr, $this->site);
    pipCanonicalProfile($this->employee, $this->site);
    pipCanonicalProfile($this->stranger, $this->site);
    pipCanonicalProfile($this->hiddenEmployee, $this->hiddenSite);
});

function pipCanonicalProfile(User $user, Site $site, array $overrides = []): HrEmployeeProfile
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

function pipCanonicalPlan(User $employee, User $manager, array $overrides = []): HrPerformanceImprovementPlan
{
    return HrPerformanceImprovementPlan::query()->create([
        'employee_user_id' => $employee->id,
        'manager_user_id' => $manager->id,
        'title' => 'Documentation support plan',
        'reason' => 'Progress notes need more detail.',
        'expectations' => 'Complete notes before the end of each shift.',
        'support_offered' => 'Weekly coaching.',
        'status' => 'active',
        'start_date' => '2026-07-01',
        'end_date' => '2026-08-31',
        'created_by' => $manager->id,
        ...$overrides,
    ]);
}

function pipCanonicalPayload(User $employee, array $overrides = []): array
{
    return [
        'employee_user_id' => $employee->id,
        'title' => 'Documentation support plan',
        'reason' => 'Progress notes need more detail.',
        'expectations' => 'Complete notes before the end of each shift.',
        'support_offered' => 'Weekly coaching.',
        'start_date' => '2026-07-01',
        'end_date' => '2026-08-31',
        'milestones' => [
            ['title' => 'First review', 'due_date' => '2026-07-15'],
        ],
        ...$overrides,
    ];
}

test('PIP register stats and staff picker honour the canonical Site boundary', function () {
    $visible = pipCanonicalPlan($this->employee, $this->hr);
    pipCanonicalPlan($this->hiddenEmployee, $this->hr);

    $this->actingAs($this->hr)
        ->get('/hr/performance/pips')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/performance/pips/index')
            ->has('pips.data', 1)
            ->where('pips.data.0.id', $visible->id)
            ->where('stats.active', 1)
            ->where('stats.total', 1));

    $response = $this->actingAs($this->hr)->get('/hr/performance/pips/create')->assertOk();
    $staffIds = collect($response->inertiaProps('staff'))->pluck('id');
    expect($staffIds)->toContain($this->employee->id)
        ->not->toContain($this->hiddenEmployee->id);

    $this->actingAs($this->hr)
        ->post('/hr/performance/pips', pipCanonicalPayload($this->hiddenEmployee))
        ->assertNotFound();
});

test('hidden Site PIPs and milestones are concealed across every direct route', function () {
    Storage::fake('private');
    $pip = pipCanonicalPlan($this->hiddenEmployee, $this->hr);
    $milestone = $pip->milestones()->create([
        'title' => 'Hidden milestone',
        'due_date' => '2026-07-15',
        'status' => 'pending',
    ]);

    $this->actingAs($this->hr)->get("/hr/performance/pips/{$pip->id}")->assertNotFound();
    $this->actingAs($this->hr)->put("/hr/performance/pips/{$pip->id}", ['title' => 'Leaked'])->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/performance/pips/{$pip->id}/cancel")->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/performance/pips/{$pip->id}/complete", ['outcome' => 'successful'])->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/performance/pips/{$pip->id}/milestones", [
        'title' => 'Leaked',
        'due_date' => '2026-07-20',
    ])->assertNotFound();
    $this->actingAs($this->hr)->put("/hr/performance/pips/milestones/{$milestone->id}", ['status' => 'met'])->assertNotFound();
    $this->actingAs($this->hr)->delete("/hr/performance/pips/milestones/{$milestone->id}")->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/performance/pips/milestones/{$milestone->id}/evidence", [
        'file' => UploadedFile::fake()->create('hidden.pdf', 20, 'application/pdf'),
    ])->assertNotFound();
    $this->actingAs($this->hr)->get("/hr/performance/pips/milestones/{$milestone->id}/evidence")->assertNotFound();

    expect($pip->fresh()->title)->toBe('Documentation support plan')
        ->and($milestone->fresh()->status)->toBe('pending')
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

test('only the exact current employee can read and acknowledge their open PIP', function () {
    $pip = pipCanonicalPlan($this->employee, $this->hr);

    $this->actingAs($this->employee)->get("/hr/performance/pips/{$pip->id}")->assertOk();
    $this->actingAs($this->stranger)->get("/hr/performance/pips/{$pip->id}")->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/performance/pips/{$pip->id}/acknowledge")->assertNotFound();

    $this->actingAs($this->employee)
        ->post("/hr/performance/pips/{$pip->id}/acknowledge")
        ->assertSessionHas('success');
    $pip->refresh();
    $acknowledgedAt = $pip->employee_acknowledged_at;
    expect($pip->employee_acknowledged)->toBeTrue();

    $this->actingAs($this->employee)
        ->post("/hr/performance/pips/{$pip->id}/acknowledge")
        ->assertSessionHas('success');
    expect($pip->fresh()->employee_acknowledged_at->equalTo($acknowledgedAt))->toBeTrue();

    $former = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    pipCanonicalProfile($former, $this->site, [
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);
    $formerPip = pipCanonicalPlan($former, $this->hr);
    $this->actingAs($former)->get("/hr/performance/pips/{$formerPip->id}")->assertNotFound();
    $this->actingAs($former)->post("/hr/performance/pips/{$formerPip->id}/acknowledge")->assertNotFound();
});

test('source reviews must be visible signed off and belong to the PIP subject', function () {
    $otherEmployee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    pipCanonicalProfile($otherEmployee, $this->site);

    $signed = HrPerformanceReview::query()->create([
        'employee_user_id' => $this->employee->id,
        'reviewer_user_id' => $this->hr->id,
        'review_type' => 'annual',
        'review_period_start' => '2026-01-01',
        'review_period_end' => '2026-06-30',
        'status' => 'signed_off',
        'created_by' => $this->hr->id,
    ]);
    $mismatch = HrPerformanceReview::query()->create([
        'employee_user_id' => $otherEmployee->id,
        'reviewer_user_id' => $this->hr->id,
        'review_type' => 'annual',
        'review_period_start' => '2026-01-01',
        'review_period_end' => '2026-06-30',
        'status' => 'signed_off',
        'created_by' => $this->hr->id,
    ]);
    $draft = HrPerformanceReview::query()->create([
        'employee_user_id' => $this->employee->id,
        'reviewer_user_id' => $this->hr->id,
        'review_type' => 'quarterly',
        'review_period_start' => '2026-04-01',
        'review_period_end' => '2026-06-30',
        'status' => 'draft',
        'created_by' => $this->hr->id,
    ]);
    $hidden = HrPerformanceReview::query()->create([
        'employee_user_id' => $this->hiddenEmployee->id,
        'reviewer_user_id' => $this->hr->id,
        'review_type' => 'annual',
        'review_period_start' => '2026-01-01',
        'review_period_end' => '2026-06-30',
        'status' => 'signed_off',
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/performance/pips', pipCanonicalPayload($this->employee, ['source_review_id' => $signed->id]))
        ->assertRedirect();
    expect(HrPerformanceImprovementPlan::query()->latest('id')->firstOrFail()->reason)
        ->toContain("performance review #{$signed->id}");

    $this->actingAs($this->hr)
        ->post('/hr/performance/pips', pipCanonicalPayload($this->employee, ['source_review_id' => $mismatch->id]))
        ->assertUnprocessable();
    $this->actingAs($this->hr)
        ->post('/hr/performance/pips', pipCanonicalPayload($this->employee, ['source_review_id' => $draft->id]))
        ->assertUnprocessable();
    $this->actingAs($this->hr)
        ->post('/hr/performance/pips', pipCanonicalPayload($this->employee, ['source_review_id' => $hidden->id]))
        ->assertNotFound();
});

test('milestones are reviewed before final completion while extensions keep the plan open', function () {
    $pip = pipCanonicalPlan($this->employee, $this->hr);
    $milestone = $pip->milestones()->create([
        'title' => 'First review',
        'due_date' => '2026-07-15',
        'status' => 'pending',
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/performance/pips/{$pip->id}/complete", ['outcome' => 'successful'])
        ->assertUnprocessable();
    $this->actingAs($this->hr)
        ->post("/hr/performance/pips/{$pip->id}/complete", ['outcome' => 'extended'])
        ->assertSessionHasErrors('new_end_date');
    $this->actingAs($this->hr)
        ->post("/hr/performance/pips/{$pip->id}/complete", [
            'outcome' => 'extended',
            'new_end_date' => '2026-08-15',
        ])
        ->assertSessionHasErrors('new_end_date');

    $this->actingAs($this->hr)
        ->post("/hr/performance/pips/{$pip->id}/complete", [
            'outcome' => 'extended',
            'new_end_date' => '2026-09-30',
            'outcome_notes' => 'Allow four more weeks of coached practice.',
        ])
        ->assertSessionHas('success');
    $pip->refresh();
    expect($pip->status)->toBe('active')
        ->and($pip->outcome)->toBe('extended')
        ->and($pip->end_date->toDateString())->toBe('2026-09-30')
        ->and($pip->completed_at)->toBeNull();

    $this->actingAs($this->hr)
        ->put("/hr/performance/pips/milestones/{$milestone->id}", ['status' => 'met'])
        ->assertSessionHas('success');
    $this->actingAs($this->hr)
        ->delete("/hr/performance/pips/milestones/{$milestone->id}")
        ->assertUnprocessable();
    $this->actingAs($this->hr)
        ->post("/hr/performance/pips/{$pip->id}/complete", [
            'outcome' => 'successful',
            'outcome_notes' => 'All agreed improvements were sustained.',
        ])
        ->assertSessionHas('success');

    expect($pip->fresh()->status)->toBe('completed');
    $this->actingAs($this->hr)
        ->put("/hr/performance/pips/{$pip->id}", ['title' => 'Changed after closure'])
        ->assertUnprocessable();
    expect($pip->fresh()->title)->toBe('Documentation support plan');
});

test('milestone evidence is private to participants and replacement is commit safe', function () {
    Storage::fake('private');
    $pip = pipCanonicalPlan($this->employee, $this->hr);
    $oldPath = 'hr/pip-milestones/old/evidence.pdf';
    Storage::disk('private')->put($oldPath, 'old evidence');
    $milestone = $pip->milestones()->create([
        'title' => 'Evidence review',
        'due_date' => '2026-07-15',
        'status' => 'pending',
        'evidence_path' => $oldPath,
    ]);

    $this->actingAs($this->employee)
        ->get("/hr/performance/pips/milestones/{$milestone->id}/evidence")
        ->assertOk();
    $this->actingAs($this->stranger)
        ->get("/hr/performance/pips/milestones/{$milestone->id}/evidence")
        ->assertNotFound();
    $this->actingAs($this->hr)
        ->get("/hr/performance/pips/milestones/{$milestone->id}/evidence")
        ->assertOk();

    $this->actingAs($this->hr)
        ->post("/hr/performance/pips/milestones/{$milestone->id}/evidence", [
            'file' => UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf'),
        ])
        ->assertSessionHas('success');

    $newPath = $milestone->fresh()->evidence_path;
    expect($newPath)->not->toBe($oldPath);
    Storage::disk('private')->assertMissing($oldPath);
    Storage::disk('private')->assertExists($newPath);

    $pip->update(['status' => 'completed', 'completed_at' => now()]);
    $filesBefore = Storage::disk('private')->allFiles();
    $this->actingAs($this->hr)
        ->post("/hr/performance/pips/milestones/{$milestone->id}/evidence", [
            'file' => UploadedFile::fake()->create('after-close.pdf', 20, 'application/pdf'),
        ])
        ->assertUnprocessable();
    expect(Storage::disk('private')->allFiles())->toBe($filesBefore);
});
