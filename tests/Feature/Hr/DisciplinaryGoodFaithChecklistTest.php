<?php

use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrCaseEvent;
use App\Domain\Hr\Models\HrDisciplinaryAction;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->site = Site::factory()->create([
        'name' => 'Disciplinary case Site',
    ]);

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
        'user_id' => $this->hr->id,
        'employee_number' => 'EMP-HR-1001',
        'work_email' => "hr-{$this->hr->id}@example.test",
        'position_title' => 'HR Advisor',
        'position_role' => 'hr',
        'employment_type' => 'full_time',
        'start_date' => now()->subYears(2)->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
    ]);

    HrEmployeeProfile::query()->create([
        'user_id' => $this->staff->id,
        'employee_number' => 'EMP-STF-1002',
        'work_email' => "staff-{$this->staff->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYears(1)->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
    ]);

    $this->case = HrCase::query()->create([
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
        'outcome' => 'Written warning issued.',
        'outcome_decided_at' => now(),
        'outcome_decided_by' => $this->hr->id,
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

test('recorded disciplinary outcome appeal and good faith evidence cannot be weakened', function () {
    $action = HrDisciplinaryAction::query()->create([
        'case_id' => $this->case->id,
        'employee_user_id' => $this->staff->id,
        'stage' => 'appeal',
        'action_type' => 'written_warning',
        'allegation_summary' => 'Repeated late medication chart updates.',
        'good_faith_checklist' => fullGoodFaithChecklist(),
        'outcome' => 'Written warning issued.',
        'outcome_decided_at' => now()->subDay(),
        'outcome_decided_by' => $this->hr->id,
        'appeal_received' => true,
        'appeal_received_at' => now(),
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->put("/hr/cases/disciplinary/{$action->id}", [
            'good_faith_checklist' => [
                ...fullGoodFaithChecklist(),
                'response_genuinely_considered' => false,
            ],
        ])
        ->assertSessionHasErrors('good_faith');
    $this->actingAs($this->hr)
        ->put("/hr/cases/disciplinary/{$action->id}", ['outcome' => null])
        ->assertSessionHasErrors('outcome');
    $this->actingAs($this->hr)
        ->put("/hr/cases/disciplinary/{$action->id}", ['appeal_received' => false])
        ->assertSessionHasErrors('appeal_received');

    expect($action->fresh())
        ->outcome->toBe('Written warning issued.')
        ->appeal_received->toBeTrue()
        ->and($action->fresh()->good_faith_checklist)
        ->toEqual(fullGoodFaithChecklist());
});

test('disciplinary evidence dates must be truthful and active actions block parent case closure', function () {
    $action = HrDisciplinaryAction::query()->create([
        'case_id' => $this->case->id,
        'employee_user_id' => $this->staff->id,
        'stage' => 'meeting_scheduled',
        'action_type' => 'written_warning',
        'allegation_summary' => 'Repeated late medication chart updates.',
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->put("/hr/cases/disciplinary/{$action->id}", [
            'notice_issued_at' => now()->addDay()->toIso8601String(),
            'meeting_scheduled_at' => now()->addDays(2)->toIso8601String(),
            'meeting_held_at' => now()->toIso8601String(),
        ])
        ->assertSessionHasErrors(['notice_issued_at', 'meeting_held_at']);

    $this->actingAs($this->hr)
        ->post("/hr/cases/{$this->case->id}/close", [
            'outcome' => 'Closed while action remains active.',
            'outcome_type' => 'disciplinary',
        ])
        ->assertSessionHasErrors('case');

    expect($action->fresh()->notice_issued_at)->toBeNull()
        ->and($action->fresh()->meeting_held_at)->toBeNull()
        ->and($this->case->fresh()->status)->toBe('open');
});

test('disciplinary edit GET redirects to the case show page which exposes the wizard contract', function () {
    $action = HrDisciplinaryAction::query()->create([
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

    // The full-page edit form was replaced by the Edit-disciplinary wizard on
    // the parent case show page; the old GET deep-links into it.
    $this->actingAs($this->hr)
        ->get("/hr/cases/disciplinary/{$action->id}/edit")
        ->assertRedirect("/hr/cases/{$this->case->id}?edit-disciplinary={$action->id}");

    $response = $this->actingAs($this->hr)->get("/hr/cases/{$this->case->id}?edit-disciplinary={$action->id}");
    $response->assertOk();

    expect($response->inertiaProps('case.case_number'))->toBe('HR-90001');
    expect($response->inertiaProps('case.disciplinary_actions.0.id'))->toBe($action->id);
    expect($response->inertiaProps('case.disciplinary_actions.0.stage'))->toBe('response_period');
    expect($response->inertiaProps('case.disciplinary_actions.0.good_faith_checklist.allegation_communicated'))->toBeTrue();
    expect($response->inertiaProps('case.disciplinary_actions.0.good_faith_checklist.opportunity_to_respond'))->toBeFalse();
    expect($response->inertiaProps('goodFaithRequiredChecks'))->toHaveCount(4);
    expect(collect($response->inertiaProps('stageOptions'))->pluck('value')->all())->toContain('outcome_decided');
    expect(collect($response->inertiaProps('actionTypes'))->pluck('value')->all())->toContain('written_warning');
});

test('case and disciplinary create GETs redirect into their wizard hosts', function () {
    $this->actingAs($this->hr)
        ->get('/hr/cases/create')
        ->assertRedirect('/hr/cases?new=1');

    $this->actingAs($this->hr)
        ->get("/hr/cases/{$this->case->id}/events/create")
        ->assertRedirect("/hr/cases/{$this->case->id}?new=event");

    $this->actingAs($this->hr)
        ->get("/hr/cases/{$this->case->id}/disciplinary/create")
        ->assertRedirect("/hr/cases/{$this->case->id}?new=disciplinary");
});
