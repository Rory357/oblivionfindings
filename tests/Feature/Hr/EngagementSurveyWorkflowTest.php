<?php

use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrEngagementSurveyResponse;
use App\Domain\Hr\Services\EngagementService;
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

    $hrRole = Role::where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $supportRole = Role::where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }
});

test('hr can create publish and collect engagement survey responses', function () {
    $this->actingAs($this->hr)
        ->post('/hr/wellbeing/surveys', [
            'title' => 'Quarterly eNPS Pulse',
            'description' => 'Q1 pulse for employee engagement.',
            'survey_type' => 'enps',
            'is_anonymous' => true,
            'questions' => [
                [
                    'question_type' => 'enps',
                    'question_text' => 'How likely are you to recommend us as a workplace? (0-10)',
                    'is_required' => true,
                    'sort_order' => 1,
                ],
                [
                    'question_type' => 'text',
                    'question_text' => 'What one thing should leadership improve next?',
                    'is_required' => false,
                    'sort_order' => 2,
                ],
            ],
        ])
        ->assertSessionHas('success');

    $survey = HrEngagementSurvey::query()->where('title', 'Quarterly eNPS Pulse')->first();
    expect($survey)->not->toBeNull();
    expect($survey?->status)->toBe('draft');
    expect($survey?->questions()->count())->toBe(2);

    $this->actingAs($this->hr)
        ->post("/hr/wellbeing/surveys/{$survey->id}/publish")
        ->assertSessionHas('success');

    $survey->refresh();
    expect($survey->status)->toBe('published');
    expect($survey->published_at)->not->toBeNull();

    $enpsQuestion = $survey->questions()->where('question_type', 'enps')->first();
    expect($enpsQuestion)->not->toBeNull();

    $this->actingAs($this->staff)
        ->post("/hr/wellbeing/surveys/{$survey->id}/responses", [
            'answers' => [
                (string) $enpsQuestion->id => 9,
            ],
        ])
        ->assertSessionHas('success');

    $response = HrEngagementSurveyResponse::query()->where('survey_id', $survey->id)->first();
    expect($response)->not->toBeNull();
    expect($response?->user_id)->toBeNull(); // anonymous response mode
    expect($response?->overall_score)->toBe('9.00');

    $summary = app(EngagementService::class)->summary($survey->fresh());
    expect($summary['response_count'])->toBe(1);
    expect($summary['enps'])->toBe(100.0);
});
