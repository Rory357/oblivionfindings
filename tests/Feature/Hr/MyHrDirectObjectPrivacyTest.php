<?php

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Services\FeedService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function createMyHrCurrentStaffProfile(User $user, Site $site): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
}

function myHrSiteSurveyPayload(string $title, Site $site): array
{
    return [
        'title' => $title,
        'description' => 'A Site-specific staff pulse survey.',
        'survey_type' => 'pulse',
        'is_anonymous' => false,
        'audience_type' => 'site',
        'audience_site_ids' => [$site->id],
        'publish' => true,
        'questions' => [[
            'question_type' => 'scale',
            'question_text' => 'How supported did you feel this week?',
            'is_required' => true,
            'sort_order' => 1,
        ]],
    ];
}

function createMyHrSiteKudos(User $author, User $recipient, Site $site): HrKudos
{
    $kudos = app(FeedService::class)->sendKudos(
        $author,
        $recipient->id,
        'teamwork',
        'Thank you for supporting the team.',
    );

    $kudos->feedPost()->update([
        'target_audience' => 'site',
        'target_value' => (string) $site->id,
    ]);

    return $kudos->refresh();
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    Notification::fake();

    $this->staffSite = Site::factory()->create(['name' => 'My HR Staff Site']);
    $this->otherSite = Site::factory()->create(['name' => 'My HR Other Site']);

    $supportWorkerRole = Role::query()->where('name', 'support_worker')->firstOrFail();
    $hrRole = Role::query()->where('name', 'hr')->firstOrFail();

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->staff->roles()->syncWithoutDetaching([$supportWorkerRole->id]);
    $this->staffProfile = createMyHrCurrentStaffProfile($this->staff, $this->staffSite);

    $this->manager = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([$hrRole->id]);
    $this->managerProfile = createMyHrCurrentStaffProfile($this->manager, $this->staffSite);
    $this->managerProfile->update(['secondary_site_ids' => [$this->otherSite->id]]);

    $this->otherStaff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->otherStaff->roles()->syncWithoutDetaching([$supportWorkerRole->id]);
    $this->otherStaffProfile = createMyHrCurrentStaffProfile($this->otherStaff, $this->otherSite);
});

test('My HR directory shows only current colleagues on Sites visible to the employee', function () {
    $response = $this->actingAs($this->staff)->get('/hr/my/directory')->assertOk();
    $profileIds = collect($response->inertiaProps('people'))->pluck('id');

    expect($profileIds)
        ->toContain($this->staffProfile->id, $this->managerProfile->id)
        ->not->toContain($this->otherStaffProfile->id);
});

test('current staff can open My HR goals through the application-wide cycle path', function () {
    $this->actingAs($this->staff)
        ->get('/hr/my/goals')
        ->assertOk();
});

test('former staff cannot retain access to My HR read surfaces', function () {
    $this->staffProfile->update([
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);

    foreach ([
        '/hr/my/profile',
        '/hr/my/directory',
        '/hr/my/goals',
        '/hr/my/time',
        '/hr/my/documents',
        '/hr/my/calendar?month='.today()->format('Y-m'),
    ] as $path) {
        $this->actingAs($this->staff)->get($path)->assertNotFound();
    }
});

test('My HR surveys list hides a published survey for another Site', function () {
    $this->actingAs($this->manager)
        ->post('/hr/wellbeing/surveys', myHrSiteSurveyPayload('Survey for my Site', $this->staffSite))
        ->assertRedirect();
    $this->actingAs($this->manager)
        ->post('/hr/wellbeing/surveys', myHrSiteSurveyPayload('Survey for another Site', $this->otherSite))
        ->assertRedirect();

    $visibleSurvey = HrEngagementSurvey::query()->where('title', 'Survey for my Site')->firstOrFail();
    $hiddenSurvey = HrEngagementSurvey::query()->where('title', 'Survey for another Site')->firstOrFail();

    $response = $this->actingAs($this->staff)->get('/hr/my/surveys')->assertOk();
    $surveyIds = collect($response->inertiaProps('surveys'))->pluck('id');

    expect($surveyIds)
        ->toContain($visibleSurvey->id)
        ->not->toContain($hiddenSurvey->id);
});

test('My HR conceals direct submission to a survey for another Site', function () {
    $this->actingAs($this->manager)
        ->post('/hr/wellbeing/surveys', myHrSiteSurveyPayload('Hidden direct survey', $this->otherSite))
        ->assertRedirect();

    $survey = HrEngagementSurvey::query()
        ->where('title', 'Hidden direct survey')
        ->with('questions')
        ->firstOrFail();

    $this->actingAs($this->staff)
        ->post("/hr/my/surveys/{$survey->id}", [
            'answers' => [(string) $survey->questions->firstOrFail()->id => 4],
        ])
        ->assertNotFound();

    $this->assertDatabaseMissing('hr_engagement_survey_responses', [
        'survey_id' => $survey->id,
        'user_id' => $this->staff->id,
    ]);
});

test('My HR conceals reactions to Site-hidden kudos without creating a reaction', function () {
    $kudos = createMyHrSiteKudos($this->manager, $this->otherStaff, $this->otherSite);

    $this->actingAs($this->staff)
        ->post("/hr/my/kudos/{$kudos->id}/react", ['emoji' => 'heart'])
        ->assertNotFound();

    $this->assertDatabaseMissing('hr_kudos_reactions', [
        'kudos_id' => $kudos->id,
        'user_id' => $this->staff->id,
        'emoji' => 'heart',
    ]);
});

test('My HR requires recognition permission before reacting to visible kudos', function () {
    $roleWithoutRecognition = Role::query()->create([
        'name' => 'my_hr_without_recognition',
        'label' => 'My HR without recognition',
        'level' => 10,
        'type' => 'custom',
    ]);
    $staffWithoutRecognition = User::factory()->create([
        'role' => 'my_hr_without_recognition',
        'approved_at' => now(),
    ]);
    $staffWithoutRecognition->roles()->sync([$roleWithoutRecognition->id]);
    createMyHrCurrentStaffProfile($staffWithoutRecognition, $this->staffSite);

    $kudos = createMyHrSiteKudos($this->manager, $this->otherStaff, $this->staffSite);

    $this->actingAs($staffWithoutRecognition)
        ->post("/hr/my/kudos/{$kudos->id}/react", ['emoji' => 'heart'])
        ->assertForbidden();

    $this->assertDatabaseMissing('hr_kudos_reactions', [
        'kudos_id' => $kudos->id,
        'user_id' => $staffWithoutRecognition->id,
        'emoji' => 'heart',
    ]);
});

test('My HR recognition mutations require permission and current staff status', function () {
    $authenticatedPortalUser = User::factory()->create([
        'role' => 'client_family',
        'approved_at' => now(),
    ]);
    $this->actingAs($authenticatedPortalUser)
        ->post('/hr/my/kudos', [
            'to_user_id' => $this->staff->id,
            'category' => 'teamwork',
            'message' => 'Forged portal recognition.',
        ])
        ->assertForbidden();

    $kudos = createMyHrSiteKudos($this->manager, $this->staff, $this->staffSite);
    $this->staffProfile->update([
        'end_date' => today()->subDay(),
        'is_active' => false,
    ]);

    $this->actingAs($this->staff)
        ->post('/hr/my/kudos', [
            'to_user_id' => $this->manager->id,
            'category' => 'teamwork',
            'message' => 'Former staff recognition.',
        ])
        ->assertForbidden();
    $this->actingAs($this->staff)
        ->post("/hr/my/kudos/{$kudos->id}/reply", ['body' => 'Former staff reply.'])
        ->assertNotFound();
    $this->actingAs($this->staff)
        ->post("/hr/my/kudos/{$kudos->id}/react", ['emoji' => 'heart'])
        ->assertNotFound();

    expect(HrKudos::query()->where('message', 'Forged portal recognition.')->exists())->toBeFalse()
        ->and(HrKudos::query()->where('message', 'Former staff recognition.')->exists())->toBeFalse();
    $this->assertDatabaseMissing('hr_kudos_replies', [
        'kudos_id' => $kudos->id,
        'user_id' => $this->staff->id,
    ]);
    $this->assertDatabaseMissing('hr_kudos_reactions', [
        'kudos_id' => $kudos->id,
        'user_id' => $this->staff->id,
    ]);
});

test('My HR overview excludes announcement title and content for another Site', function () {
    $visibleAnnouncement = HrAnnouncement::factory()->create([
        'title' => 'Visible Site update',
        'content' => 'Visible Site announcement content.',
        'target_audience' => 'site',
        'target_value' => (string) $this->staffSite->id,
        'created_by' => $this->manager->id,
    ]);
    $visibleAnnouncement->targets()->create([
        'type' => 'site',
        'value' => (string) $this->staffSite->id,
    ]);

    $hiddenAnnouncement = HrAnnouncement::factory()->create([
        'title' => 'Hidden Site update',
        'content' => 'Hidden Site announcement content.',
        'target_audience' => 'site',
        'target_value' => (string) $this->otherSite->id,
        'created_by' => $this->manager->id,
    ]);
    $hiddenAnnouncement->targets()->create([
        'type' => 'site',
        'value' => (string) $this->otherSite->id,
    ]);

    $announcements = collect(
        $this->actingAs($this->staff)
            ->get('/hr/my')
            ->assertOk()
            ->inertiaProps('announcements'),
    );

    expect($announcements->pluck('title'))
        ->toContain($visibleAnnouncement->title)
        ->not->toContain($hiddenAnnouncement->title);
    expect($announcements->pluck('content'))
        ->toContain($visibleAnnouncement->content)
        ->not->toContain($hiddenAnnouncement->content);
});

test('My HR freshly conceals a document owned by another staff member', function () {
    Storage::fake('private');
    $ownDocument = HrDocument::factory()->create([
        'employee_profile_id' => $this->staffProfile->id,
        'storage_path' => 'hr/my/own-document.pdf',
        'original_name' => 'own-document.pdf',
        'is_restricted' => false,
    ]);
    $otherDocument = HrDocument::factory()->create([
        'employee_profile_id' => $this->otherStaffProfile->id,
        'storage_path' => 'hr/my/other-document.pdf',
        'original_name' => 'other-document.pdf',
        'is_restricted' => false,
    ]);
    Storage::disk('private')->put($ownDocument->storage_path, 'own document');
    Storage::disk('private')->put($otherDocument->storage_path, 'other document');

    $this->actingAs($this->staff)
        ->get("/hr/my/documents/{$ownDocument->id}/download")
        ->assertOk();
    $this->actingAs($this->staff)
        ->get("/hr/my/documents/{$otherDocument->id}/download")
        ->assertNotFound();
});

test('My HR conceals a restricted document from its owner without document management permission', function () {
    Storage::fake('private');
    $document = HrDocument::factory()->create([
        'employee_profile_id' => $this->staffProfile->id,
        'storage_path' => 'hr/my/restricted-staff-document.pdf',
        'original_name' => 'restricted-staff-document.pdf',
        'is_restricted' => true,
    ]);
    Storage::disk('private')->put($document->storage_path, 'restricted staff document');

    $this->actingAs($this->staff)
        ->get("/hr/my/documents/{$document->id}/download")
        ->assertNotFound();
});

test('My HR permits a document manager to download only their own restricted document', function () {
    Storage::fake('private');
    $ownRestrictedDocument = HrDocument::factory()->create([
        'employee_profile_id' => $this->managerProfile->id,
        'storage_path' => 'hr/my/restricted-manager-document.pdf',
        'original_name' => 'restricted-manager-document.pdf',
        'is_restricted' => true,
    ]);
    $otherRestrictedDocument = HrDocument::factory()->create([
        'employee_profile_id' => $this->otherStaffProfile->id,
        'storage_path' => 'hr/my/restricted-other-document.pdf',
        'original_name' => 'restricted-other-document.pdf',
        'is_restricted' => true,
    ]);
    Storage::disk('private')->put($ownRestrictedDocument->storage_path, 'restricted manager document');
    Storage::disk('private')->put($otherRestrictedDocument->storage_path, 'restricted other document');

    $this->actingAs($this->manager)
        ->get("/hr/my/documents/{$ownRestrictedDocument->id}/download")
        ->assertOk();
    $this->actingAs($this->manager)
        ->get("/hr/my/documents/{$otherRestrictedDocument->id}/download")
        ->assertNotFound();
});

test('My HR signs only the current employees own Site-visible signature request', function () {
    Storage::fake('private');
    $ownDocument = HrDocument::factory()->create([
        'employee_profile_id' => $this->staffProfile->id,
        'storage_disk' => 'private',
        'storage_path' => 'hr/my/sign-own-document.pdf',
        'original_name' => 'sign-own-document.pdf',
        'created_by' => $this->manager->id,
        'uploaded_by' => $this->manager->id,
    ]);
    Storage::disk('private')->put($ownDocument->storage_path, '%PDF-1.4 own');
    $ownSignature = HrDocumentSignature::query()->create([
        'document_id' => $ownDocument->id,
        'signer_user_id' => $this->staff->id,
        'status' => 'pending',
        'requested_by' => $this->manager->id,
        'requested_at' => now(),
    ]);

    $hiddenDocument = HrDocument::factory()->create([
        'employee_profile_id' => $this->otherStaffProfile->id,
        'storage_disk' => 'private',
        'storage_path' => 'hr/my/sign-hidden-document.pdf',
        'original_name' => 'sign-hidden-document.pdf',
        'created_by' => $this->manager->id,
        'uploaded_by' => $this->manager->id,
    ]);
    $hiddenSignature = HrDocumentSignature::query()->create([
        'document_id' => $hiddenDocument->id,
        'signer_user_id' => $this->staff->id,
        'status' => 'pending',
        'requested_by' => $this->manager->id,
        'requested_at' => now(),
    ]);

    $notMine = HrDocumentSignature::query()->create([
        'document_id' => $ownDocument->id,
        'signer_user_id' => $this->manager->id,
        'status' => 'pending',
        'requested_by' => $this->manager->id,
        'requested_at' => now(),
    ]);

    $this->actingAs($this->staff)
        ->post("/hr/my/documents/sign/{$hiddenSignature->id}", ['signature_data' => 'Stephan Worker'])
        ->assertNotFound();
    $this->actingAs($this->staff)
        ->post("/hr/my/documents/sign/{$notMine->id}", ['signature_data' => 'Stephan Worker'])
        ->assertNotFound();
    $this->actingAs($this->staff)
        ->post("/hr/my/documents/sign/{$ownSignature->id}", ['signature_data' => 'Stephan Worker'])
        ->assertSessionHas('success');

    expect($ownSignature->fresh()->status)->toBe('signed')
        ->and($hiddenSignature->fresh()->status)->toBe('pending')
        ->and($notMine->fresh()->status)->toBe('pending');
});

test('My HR prevents former staff from signing a retained request', function () {
    $document = HrDocument::factory()->create([
        'employee_profile_id' => $this->staffProfile->id,
        'created_by' => $this->manager->id,
        'uploaded_by' => $this->manager->id,
    ]);
    $signature = HrDocumentSignature::query()->create([
        'document_id' => $document->id,
        'signer_user_id' => $this->staff->id,
        'status' => 'pending',
        'requested_by' => $this->manager->id,
        'requested_at' => now(),
    ]);
    $this->staffProfile->update([
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);

    $this->actingAs($this->staff)
        ->post("/hr/my/documents/sign/{$signature->id}", ['signature_data' => 'Former Worker'])
        ->assertNotFound();

    expect($signature->fresh()->status)->toBe('pending');
});
