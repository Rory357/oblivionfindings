<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Domain\Hr\Models\HrFeedbackResponse;
use App\Domain\Hr\Models\HrFeedbackTemplate;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Notifications\FeedbackReminderNotification;
use App\Domain\Hr\Notifications\FeedbackRequestedNotification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    Notification::fake();

    $this->site = Site::factory()->create(['name' => 'Feedback visible Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Feedback hidden Site']);
    $this->hr = User::factory()->create([
        'name' => 'Feedback HR manager',
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->subject = User::factory()->create([
        'name' => 'Visible feedback subject',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->otherSubject = User::factory()->create([
        'name' => 'Other feedback subject',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->hiddenSubject = User::factory()->create([
        'name' => 'Hidden feedback subject',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->reviewer = User::factory()->create([
        'name' => 'Feedback reviewer',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->stranger = User::factory()->create([
        'name' => 'Feedback stranger',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->hiddenReviewer = User::factory()->create([
        'name' => 'Hidden feedback reviewer',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->formerReviewer = User::factory()->create([
        'name' => 'Former feedback reviewer',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->hrProfile = feedbackCanonicalProfile($this->hr, $this->site);
    $this->subjectProfile = feedbackCanonicalProfile($this->subject, $this->site);
    $this->otherSubjectProfile = feedbackCanonicalProfile($this->otherSubject, $this->site);
    $this->hiddenSubjectProfile = feedbackCanonicalProfile($this->hiddenSubject, $this->hiddenSite);
    $this->reviewerProfile = feedbackCanonicalProfile($this->reviewer, $this->site);
    $this->strangerProfile = feedbackCanonicalProfile($this->stranger, $this->site);
    $this->hiddenReviewerProfile = feedbackCanonicalProfile($this->hiddenReviewer, $this->hiddenSite);
    $this->formerReviewerProfile = feedbackCanonicalProfile($this->formerReviewer, $this->site, [
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);

    foreach ([$this->reviewer, $this->stranger, $this->formerReviewer] as $viewer) {
        feedbackCanonicalGrantView($viewer);
    }
});

function feedbackCanonicalProfile(User $user, Site $site, array $overrides = []): HrEmployeeProfile
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

function feedbackCanonicalGrantView(User $user): void
{
    $permission = Permission::query()->where('key', 'hr.performance.view')->firstOrFail();
    $user->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
}

function feedbackCanonicalTemplate(string $name, array $overrides = []): HrFeedbackTemplate
{
    return HrFeedbackTemplate::query()->create([
        'name' => $name,
        'description' => "{$name} description",
        'questions' => [
            ['key' => 'teamwork', 'question' => 'How well do they collaborate?'],
            ['key' => 'communication', 'question' => 'How clearly do they communicate?'],
        ],
        'is_default' => false,
        'is_active' => true,
        ...$overrides,
    ]);
}

function feedbackCanonicalRequest(User $subject, User $reviewer, User $requester, array $overrides = []): HrFeedbackRequest
{
    return HrFeedbackRequest::query()->create([
        'subject_user_id' => $subject->id,
        'requester_user_id' => $requester->id,
        'reviewer_user_id' => $reviewer->id,
        'review_type' => 'peer',
        'questions_snapshot' => [
            ['key' => 'teamwork', 'question' => 'How well do they collaborate?'],
            ['key' => 'communication', 'question' => 'How clearly do they communicate?'],
        ],
        'status' => 'pending',
        'due_date' => today()->addWeek(),
        ...$overrides,
    ]);
}

function feedbackCanonicalReview(User $subject, User $reviewer): HrPerformanceReview
{
    return HrPerformanceReview::query()->create([
        'employee_user_id' => $subject->id,
        'reviewer_user_id' => $reviewer->id,
        'review_type' => 'annual',
        'review_period_start' => '2026-01-01',
        'review_period_end' => '2026-06-30',
        'status' => 'draft',
        'created_by' => $reviewer->id,
    ]);
}

function feedbackCanonicalResponses(array $overrides = []): array
{
    return [
        'responses' => [
            [
                'question_key' => 'teamwork',
                'rating' => 4,
                'comment' => 'Works constructively with the team.',
            ],
            [
                'question_key' => 'communication',
                'rating' => 5,
                'comment' => 'Communicates clearly and promptly.',
            ],
        ],
        ...$overrides,
    ];
}

test('manager register wizard and statistics use canonical Site access and application templates', function (): void {
    $visible = feedbackCanonicalRequest($this->subject, $this->reviewer, $this->hr);
    feedbackCanonicalRequest($this->hiddenSubject, $this->reviewer, $this->hr);
    $template = feedbackCanonicalTemplate('Application feedback template');

    $response = $this->actingAs($this->hr)
        ->get('/hr/feedback')
        ->assertOk();
    $rows = collect($response->inertiaProps('requests.data'));
    $employees = collect($response->inertiaProps('wizard.employees'));
    $templates = collect($response->inertiaProps('wizard.templates'));

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['id'])->toBe($visible->id)
        ->and($response->inertiaProps('stats.total'))->toBe(1)
        ->and($employees->pluck('id'))->toContain($this->subject->id, $this->reviewer->id)
        ->not->toContain($this->hiddenSubject->id, $this->hiddenReviewer->id, $this->formerReviewer->id)
        ->and($templates->pluck('id'))->toContain($template->id);
    foreach ($template->getHidden() as $hiddenField) {
        expect($templates->firstWhere('id', $template->id))->not->toHaveKey($hiddenField);
    }
});

test('exact current reviewer owns response routes even when the assigned subject is at another Site', function (): void {
    $assigned = feedbackCanonicalRequest($this->hiddenSubject, $this->reviewer, $this->hr);
    $other = feedbackCanonicalRequest($this->subject, $this->stranger, $this->hr);
    $former = feedbackCanonicalRequest($this->subject, $this->formerReviewer, $this->hr);

    $response = $this->actingAs($this->reviewer)->get('/hr/feedback')->assertOk();
    expect(collect($response->inertiaProps('requests.data'))->pluck('id')->all())
        ->toBe([$assigned->id]);

    $this->actingAs($this->reviewer)
        ->get("/hr/feedback/{$assigned->id}/respond")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('feedbackRequest.id', $assigned->id)
            ->where('feedbackRequest.subject.name', $this->hiddenSubject->name));
    $this->actingAs($this->reviewer)
        ->get("/hr/feedback/{$other->id}/respond")
        ->assertNotFound();
    $this->actingAs($this->formerReviewer)
        ->get("/hr/feedback/{$former->id}/respond")
        ->assertNotFound();
});

test('request fan out validates every canonical relationship and is idempotent while open', function (): void {
    $template = feedbackCanonicalTemplate('Peer feedback questions');
    $review = feedbackCanonicalReview($this->subject, $this->hr);
    $mismatch = feedbackCanonicalReview($this->otherSubject, $this->hr);
    $hiddenReview = feedbackCanonicalReview($this->hiddenSubject, $this->hr);
    $base = [
        'subject_user_id' => $this->subject->id,
        'reviewer_user_ids' => [$this->reviewer->id],
        'review_type' => 'peer',
        'performance_review_id' => $review->id,
        'template_id' => $template->id,
    ];

    $this->actingAs($this->hr)->post('/hr/feedback/request', $base)->assertSessionHas('success');
    $this->actingAs($this->hr)->post('/hr/feedback/request', $base)->assertSessionHas('success');

    expect(HrFeedbackRequest::query()->count())->toBe(1);
    $created = HrFeedbackRequest::query()->sole();
    expect($created->template_id)->toBe($template->id)
        ->and($created->performance_review_id)->toBe($review->id)
        ->and($created->questions_snapshot)->toBe($template->questions);
    foreach ($created->getHidden() as $hiddenField) {
        expect($created->toArray())->not->toHaveKey($hiddenField);
    }
    Notification::assertSentToTimes($this->reviewer, FeedbackRequestedNotification::class, 1);

    $this->actingAs($this->hr)
        ->post('/hr/feedback/request', [...$base, 'subject_user_id' => $this->hiddenSubject->id])
        ->assertNotFound();
    $this->actingAs($this->hr)
        ->post('/hr/feedback/request', [...$base, 'reviewer_user_ids' => [$this->hiddenReviewer->id]])
        ->assertNotFound();
    $this->actingAs($this->hr)
        ->post('/hr/feedback/request', [...$base, 'reviewer_user_ids' => [$this->formerReviewer->id]])
        ->assertNotFound();
    $this->actingAs($this->hr)
        ->post('/hr/feedback/request', [...$base, 'performance_review_id' => $mismatch->id])
        ->assertSessionHasErrors('performance_review_id');
    $this->actingAs($this->hr)
        ->post('/hr/feedback/request', [...$base, 'performance_review_id' => $hiddenReview->id])
        ->assertNotFound();
    $this->actingAs($this->hr)
        ->post('/hr/feedback/request', [...$base, 'review_type' => 'self'])
        ->assertSessionHasErrors('reviewer_user_ids');

    expect(HrFeedbackRequest::query()->count())->toBe(1);
});

test('response submission requires the exact reviewer and every snapshotted question once', function (): void {
    $feedbackRequest = feedbackCanonicalRequest($this->subject, $this->reviewer, $this->hr);

    $this->actingAs($this->stranger)
        ->post("/hr/feedback/{$feedbackRequest->id}/respond", feedbackCanonicalResponses())
        ->assertNotFound();
    $this->actingAs($this->reviewer)
        ->post("/hr/feedback/{$feedbackRequest->id}/respond", [
            'responses' => [feedbackCanonicalResponses()['responses'][0]],
        ])
        ->assertSessionHasErrors('responses');
    $this->actingAs($this->reviewer)
        ->post("/hr/feedback/{$feedbackRequest->id}/respond", [
            'responses' => [
                feedbackCanonicalResponses()['responses'][0],
                feedbackCanonicalResponses()['responses'][0],
            ],
        ])
        ->assertSessionHasErrors('responses.1.question_key');

    $this->actingAs($this->reviewer)
        ->post("/hr/feedback/{$feedbackRequest->id}/respond", feedbackCanonicalResponses())
        ->assertSessionHas('success');
    expect($feedbackRequest->fresh()->status)->toBe('completed')
        ->and($feedbackRequest->fresh()->completed_at)->not->toBeNull()
        ->and(HrFeedbackResponse::query()->where('feedback_request_id', $feedbackRequest->id)->count())->toBe(2);

    $this->actingAs($this->reviewer)
        ->post("/hr/feedback/{$feedbackRequest->id}/respond", feedbackCanonicalResponses())
        ->assertNotFound();
    expect(HrFeedbackResponse::query()->where('feedback_request_id', $feedbackRequest->id)->count())->toBe(2);
});

test('feedback summaries aggregate only a canonically visible subject without reviewer identity', function (): void {
    $visible = feedbackCanonicalRequest($this->subject, $this->reviewer, $this->hr, [
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    $hidden = feedbackCanonicalRequest($this->hiddenSubject, $this->reviewer, $this->hr, [
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    foreach ([$visible, $hidden] as $request) {
        HrFeedbackResponse::query()->create([
            'feedback_request_id' => $request->id,
            'question_key' => 'teamwork',
            'rating' => 4,
            'comment' => 'Helpful feedback comment.',
            'created_at' => now(),
        ]);
    }

    $response = $this->actingAs($this->hr)
        ->get("/hr/feedback/summary/{$this->subject->id}")
        ->assertOk();
    expect($response->inertiaProps('summary.total_reviews'))->toBe(1)
        ->and($response->inertiaProps('summary.questions.teamwork.average_rating'))->toBe(4)
        ->and($response->inertiaProps('summary'))->not->toHaveKey('reviewers');

    $this->actingAs($this->hr)
        ->get("/hr/feedback/summary/{$this->hiddenSubject->id}")
        ->assertNotFound();
    $this->actingAs($this->reviewer)
        ->get("/hr/feedback/summary/{$this->hiddenSubject->id}")
        ->assertNotFound();
});

test('manager lifecycle mutations conceal hidden subjects and serialize valid transitions', function (): void {
    $hidden = feedbackCanonicalRequest($this->hiddenSubject, $this->reviewer, $this->hr);
    $visible = feedbackCanonicalRequest($this->subject, $this->reviewer, $this->hr);
    $cancel = feedbackCanonicalRequest($this->otherSubject, $this->stranger, $this->hr);
    $former = feedbackCanonicalRequest($this->subject, $this->formerReviewer, $this->hr, [
        'review_type' => 'manager',
    ]);

    $this->actingAs($this->hr)->post("/hr/feedback/{$hidden->id}/decline")->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/feedback/{$hidden->id}/remind")->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/feedback/{$hidden->id}/cancel")->assertNotFound();
    expect($hidden->fresh()->status)->toBe('pending');

    $this->actingAs($this->hr)
        ->post("/hr/feedback/{$visible->id}/remind")
        ->assertSessionHas('success');
    Notification::assertSentTo($this->reviewer, FeedbackReminderNotification::class);
    $this->actingAs($this->hr)
        ->post("/hr/feedback/{$visible->id}/decline")
        ->assertSessionHas('success');
    expect($visible->fresh()->status)->toBe('declined');
    $this->actingAs($this->hr)
        ->post("/hr/feedback/{$visible->id}/cancel")
        ->assertSessionHasErrors('status');

    $this->actingAs($this->hr)
        ->post("/hr/feedback/{$cancel->id}/cancel")
        ->assertSessionHas('success');
    expect($cancel->fresh()->status)->toBe('expired');
    $this->actingAs($this->hr)
        ->post("/hr/feedback/{$former->id}/remind")
        ->assertSessionHasErrors('reviewer_user_id');
});

test('feedback templates use application identity validated question keys and protected defaults', function (): void {
    $payload = [
        'name' => 'Leadership feedback',
        'description' => 'Leadership question set.',
        'questions' => [
            ['key' => 'leadership', 'question' => 'How effectively do they lead?'],
            ['key' => 'coaching', 'question' => 'How well do they coach others?'],
        ],
    ];

    $this->actingAs($this->hr)
        ->post('/hr/feedback/templates', $payload)
        ->assertSessionHas('success');
    $template = HrFeedbackTemplate::query()->where('name', 'Leadership feedback')->firstOrFail();
    foreach ($template->getHidden() as $hiddenField) {
        expect($template->toArray())->not->toHaveKey($hiddenField);
    }

    $this->actingAs($this->hr)
        ->post('/hr/feedback/templates', $payload)
        ->assertSessionHasErrors('name');
    $this->actingAs($this->hr)
        ->post('/hr/feedback/templates', [
            ...$payload,
            'name' => 'Duplicate keys',
            'questions' => [
                ['key' => 'same', 'question' => 'First question?'],
                ['key' => 'same', 'question' => 'Second question?'],
            ],
        ])
        ->assertSessionHasErrors('questions.1.key');

    $this->actingAs($this->hr)
        ->put("/hr/feedback/templates/{$template->id}", [...$payload, 'name' => 'Leadership and coaching'])
        ->assertSessionHas('success');
    expect($template->fresh()->name)->toBe('Leadership and coaching');

    $default = feedbackCanonicalTemplate('Protected standard', ['is_default' => true]);
    $this->actingAs($this->hr)
        ->delete("/hr/feedback/templates/{$default->id}")
        ->assertStatus(422);
    $this->actingAs($this->hr)
        ->delete("/hr/feedback/templates/{$template->id}")
        ->assertSessionHas('success');
    expect(HrFeedbackTemplate::query()->find($template->id))->toBeNull();
});
