<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Models\HrInterviewKit;
use App\Domain\Hr\Models\HrInterviewScore;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', 'hr')->first();
    if ($role) {
        $this->hr->roles()->syncWithoutDetaching([$role->id]);
    }
});

test('interview scorecard requires full criteria and calculates weighted score', function () {
    $kit = HrInterviewKit::query()->create([
        'tenant_id' => 1,
        'name' => 'Support Worker Core Kit',
        'role' => 'support_worker',
        'criteria' => [
            ['label' => 'Communication', 'weight' => 60],
            ['label' => 'Safety', 'weight' => 40],
        ],
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $candidate = HrCandidate::query()->create([
        'tenant_id' => 1,
        'first_name' => 'Ari',
        'last_name' => 'Applicant',
        'personal_email' => 'ari.applicant@example.test',
        'source' => 'direct',
        'status' => 'interview_scheduled',
        'current_stage_entered_at' => now(),
        'created_by' => $this->hr->id,
    ]);

    $application = HrApplication::query()->create([
        'tenant_id' => 1,
        'candidate_id' => $candidate->id,
        'interview_kit_id' => $kit->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'status' => 'active',
    ]);

    $interview = HrInterview::query()->create([
        'application_id' => $application->id,
        'scheduled_at' => now()->addDay(),
        'duration_minutes' => 45,
        'interview_type' => 'video',
        'status' => 'scheduled',
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/interviews/{$interview->id}/score", [
            'criteria_scores' => [
                ['label' => 'Communication', 'score' => 90],
            ],
        ])
        ->assertSessionHasErrors(['criteria_scores']);

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/interviews/{$interview->id}/score", [
            'recommendation' => 'yes',
            'criteria_scores' => [
                ['label' => 'Communication', 'score' => 90],
                ['label' => 'Safety', 'score' => 75],
            ],
        ])
        ->assertSessionHas('success');

    $score = HrInterviewScore::query()
        ->where('interview_id', $interview->id)
        ->where('interviewer_user_id', $this->hr->id)
        ->first();

    expect($score)->not->toBeNull();
    expect((float) $score->overall_score)->toBe(84.00);
    expect($score->criteria_scores)->toHaveCount(2);

    $interview->refresh();
    expect($interview->status)->toBe('completed');
});
