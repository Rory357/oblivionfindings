<?php

use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrCaseEvent;
use App\Domain\Hr\Models\HrDisciplinaryAction;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $staffRole = Role::query()->where('name', 'support_worker')->first();
    if ($staffRole) {
        $this->staff->roles()->syncWithoutDetaching([$staffRole->id]);
    }

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->hr->id,
        'employee_number' => 'EMP-HR-1001',
        'work_email' => "hr-{$this->hr->id}@example.test",
        'position_title' => 'HR Advisor',
        'position_role' => 'hr',
        'employment_type' => 'full_time',
        'start_date' => now()->subYears(2)->toDateString(),
        'is_active' => true,
    ]);

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->staff->id,
        'employee_number' => 'EMP-STF-1002',
        'work_email' => "staff-{$this->staff->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYears(1)->toDateString(),
        'is_active' => true,
    ]);

    $this->case = HrCase::query()->create([
        'tenant_id' => 1,
        'case_number' => 'HR-90001',
        'user_id' => $this->staff->id,
        'case_type' => 'disciplinary',
        'severity' => 'medium',
        'status' => 'open',
        'title' => 'Conduct concern',
        'description' => 'Late shift handover concern.',
        'reported_by' => $this->hr->id,
        'opened_at' => now()->subDays(2),
        'created_by' => $this->hr->id,
    ]);
});

function fullGoodFaithChecklist(): array
{
    return [
        'allegation_communicated' => true,
        'opportunity_to_respond' => true,
        'response_genuinely_considered' => true,
        'support_person_offered' => true,
    ];
}

test('disciplinary outcome update is blocked without required good faith checklist', function () {
    $action = HrDisciplinaryAction::query()->create([
        'tenant_id' => 1,
        'case_id' => $this->case->id,
        'employee_user_id' => $this->staff->id,
        'stage' => 'response_period',
        'action_type' => 'written_warning',
        'allegation_summary' => 'Repeated late medication chart updates.',
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->put("/hr/cases/disciplinary/{$action->id}", [
            'outcome' => 'Written warning issued.',
            'outcome_rationale' => 'Pattern of repeated behavior confirmed.',
        ])
        ->assertSessionHasErrors('good_faith');

    $action->refresh();
    expect($action->outcome)->toBeNull();
    expect($action->outcome_decided_at)->toBeNull();
});

test('disciplinary outcome update succeeds when good faith checklist is complete', function () {
    $action = HrDisciplinaryAction::query()->create([
        'tenant_id' => 1,
        'case_id' => $this->case->id,
        'employee_user_id' => $this->staff->id,
        'stage' => 'response_period',
        'action_type' => 'written_warning',
        'allegation_summary' => 'Repeated late medication chart updates.',
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->put("/hr/cases/disciplinary/{$action->id}", [
            'outcome' => 'Written warning issued.',
            'outcome_rationale' => 'Pattern of repeated behavior confirmed.',
            'good_faith_checklist' => fullGoodFaithChecklist(),
        ])
        ->assertSessionHas('success');

    $action->refresh();
    expect($action->outcome)->toBe('Written warning issued.');
    expect($action->outcome_decided_at)->not->toBeNull();
    expect($action->outcome_decided_by)->toBe($this->hr->id);

    $event = HrCaseEvent::query()
        ->where('case_id', $this->case->id)
        ->where('title', 'Disciplinary outcome recorded')
        ->latest('id')
        ->first();

    expect($event)->not->toBeNull();
    expect($event?->event_type)->toBe('investigation_update');
    expect((string) ($event?->description ?? ''))->toContain('Outcome summary');
});

test('disciplinary stage cannot advance to outcome without required good faith checklist', function () {
    $action = HrDisciplinaryAction::query()->create([
        'tenant_id' => 1,
        'case_id' => $this->case->id,
        'employee_user_id' => $this->staff->id,
        'stage' => 'response_period',
        'action_type' => 'written_warning',
        'allegation_summary' => 'Repeated late medication chart updates.',
        'good_faith_checklist' => [
            'allegation_communicated' => true,
        ],
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/cases/disciplinary/{$action->id}/advance")
        ->assertSessionHasErrors('good_faith');

    $action->refresh();
    expect($action->stage)->toBe('response_period');

    $action->update([
        'good_faith_checklist' => fullGoodFaithChecklist(),
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/cases/disciplinary/{$action->id}/advance")
        ->assertSessionHas('success');

    $action->refresh();
    expect($action->stage)->toBe('outcome_decided');

    $stageEvent = HrCaseEvent::query()
        ->where('case_id', $this->case->id)
        ->where('title', 'Disciplinary stage advanced')
        ->latest('id')
        ->first();

    expect($stageEvent)->not->toBeNull();
    expect((string) ($stageEvent?->description ?? ''))->toContain('response period');
    expect((string) ($stageEvent?->description ?? ''))->toContain('outcome decided');
});

test('disciplinary edit page exposes checklist and stage contract for ui', function () {
    $action = HrDisciplinaryAction::query()->create([
        'tenant_id' => 1,
        'case_id' => $this->case->id,
        'employee_user_id' => $this->staff->id,
        'stage' => 'response_period',
        'action_type' => 'written_warning',
        'allegation_summary' => 'Repeated late medication chart updates.',
        'good_faith_checklist' => [
            'allegation_communicated' => true,
            'opportunity_to_respond' => false,
        ],
        'created_by' => $this->hr->id,
    ]);

    $response = $this->actingAs($this->hr)->get("/hr/cases/disciplinary/{$action->id}/edit");
    $response->assertOk();

    expect($response->inertiaProps('hrCase.case_number'))->toBe('HR-90001');
    expect($response->inertiaProps('action.id'))->toBe($action->id);
    expect($response->inertiaProps('action.stage'))->toBe('response_period');
    expect($response->inertiaProps('action.good_faith_checklist.allegation_communicated'))->toBeTrue();
    expect($response->inertiaProps('goodFaithRequiredChecks'))->toHaveCount(4);
    expect(collect($response->inertiaProps('stageOptions'))->pluck('value')->all())->toContain('outcome_decided');
});
