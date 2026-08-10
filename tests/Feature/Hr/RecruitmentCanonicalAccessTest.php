<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrCandidateEmailTemplate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrInterviewKit;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->allowedSite = Site::factory()->create([
        'name' => 'Allowed Recruitment Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $this->hiddenSite = Site::factory()->create([
        'name' => 'Hidden Recruitment Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $this->viewer = User::factory()->create([
        'name' => 'Site Recruitment HR',
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->viewer->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->viewer->id,
        'primary_site_id' => $this->allowedSite->id,
        'secondary_site_ids' => [],
        'position_role' => 'hr',
        'is_active' => true,
        'start_date' => today()->subYear(),
        'end_date' => null,
    ]);
});

function canonicalRecruitmentRecord(Site $site, User $creator, string $label): array
{
    $requisition = HrJobRequisition::query()->create([
        'title' => "{$label} Support Worker",
        'slug' => str($label)->slug().'-support-worker',
        'position_role' => 'support_worker',
        'site_id' => $site->id,
        'employment_type' => 'full_time',
        'openings' => 1,
        'status' => 'published',
        'created_by' => $creator->id,
    ]);
    $candidate = HrCandidate::query()->create([
        'first_name' => $label,
        'last_name' => 'Candidate',
        'personal_email' => str($label)->slug().'@example.test',
        'source' => 'direct',
        'status' => 'offer_sent',
        'current_stage_entered_at' => now()->subDay(),
        'created_by' => $creator->id,
    ]);
    $application = HrApplication::query()->create([
        'candidate_id' => $candidate->id,
        'requisition_id' => $requisition->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'target_site_id' => $site->id,
        'status' => 'offered',
    ]);
    $offer = HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'proposed_start_date' => today()->addMonth(),
        'employment_type' => 'full_time',
        'primary_site_id' => $site->id,
        'approval_status' => 'approved',
        'created_by' => $creator->id,
    ]);

    return compact('requisition', 'candidate', 'application', 'offer');
}

test('recruitment hub and analytics share complete canonical Site provenance', function () {
    $allowed = canonicalRecruitmentRecord($this->allowedSite, $this->viewer, 'Allowed');
    $hidden = canonicalRecruitmentRecord($this->hiddenSite, $this->viewer, 'Hidden');
    $mixed = canonicalRecruitmentRecord($this->allowedSite, $this->viewer, 'Mixed');
    HrApplication::query()->create([
        'candidate_id' => $mixed['candidate']->id,
        'position_title' => 'Hidden Site Role',
        'position_role' => 'support_worker',
        'target_site_id' => $this->hiddenSite->id,
        'status' => 'active',
    ]);

    HrInterviewKit::query()->create([
        'name' => 'Application interview kit',
        'role' => 'support_worker',
        'criteria' => [],
        'is_active' => true,
        'created_by' => $this->viewer->id,
    ]);
    HrCandidateEmailTemplate::query()->create([
        'name' => 'Application welcome email',
        'subject' => 'Welcome',
        'body' => 'Welcome to recruitment.',
        'created_by' => $this->viewer->id,
    ]);

    $response = $this->actingAs($this->viewer)->get('/hr/recruitment');
    $response->assertOk();

    expect(collect($response->inertiaProps('candidates'))->pluck('id')->all())
        ->toBe([$allowed['candidate']->id])
        ->and(collect($response->inertiaProps('requisitions'))->pluck('id'))
        ->toContain($allowed['requisition']->id, $mixed['requisition']->id)
        ->not->toContain($hidden['requisition']->id)
        ->and(collect($response->inertiaProps('offers.list'))->pluck('id')->all())
        ->toBe([$allowed['offer']->id])
        ->and($response->inertiaProps('hero.active_candidates'))->toBe(1)
        ->and($response->inertiaProps('hero.open_requisitions'))->toBe(2)
        ->and(collect($response->inertiaProps('support.sites'))->pluck('id')->all())
        ->toBe([$this->allowedSite->id])
        ->and(collect($response->inertiaProps('kits'))->pluck('name'))
        ->toContain('Application interview kit')
        ->and(collect($response->inertiaProps('email_templates'))->pluck('name'))
        ->toContain('Application welcome email');

    $payload = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
    expect($payload)
        ->not->toContain($hidden['candidate']->personal_email)
        ->not->toContain($mixed['candidate']->personal_email)
        ->not->toContain('Hidden Recruitment Site');
    foreach ($allowed['candidate']->getHidden() as $hiddenField) {
        expect($payload)->not->toContain('"'.$hiddenField.'"');
    }
});

test('recruitment pipeline export excludes hidden and mixed-Site candidate data', function () {
    $allowed = canonicalRecruitmentRecord($this->allowedSite, $this->viewer, 'Export Allowed');
    $hidden = canonicalRecruitmentRecord($this->hiddenSite, $this->viewer, 'Export Hidden');
    $mixed = canonicalRecruitmentRecord($this->allowedSite, $this->viewer, 'Export Mixed');
    HrApplication::query()->create([
        'candidate_id' => $mixed['candidate']->id,
        'position_title' => 'Hidden Site Role',
        'position_role' => 'support_worker',
        'target_site_id' => $this->hiddenSite->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->viewer)
        ->get('/hr/recruitment/export?dataset=pipeline&format=csv');
    $response->assertOk();
    $csv = $response->streamedContent();

    expect($csv)
        ->toContain($allowed['candidate']->personal_email)
        ->not->toContain($hidden['candidate']->personal_email)
        ->not->toContain($mixed['candidate']->personal_email);
});

test('recruitment mutations conceal hidden and mixed-Site direct objects atomically', function () {
    $allowed = canonicalRecruitmentRecord($this->allowedSite, $this->viewer, 'Mutation Allowed');
    $hidden = canonicalRecruitmentRecord($this->hiddenSite, $this->viewer, 'Mutation Hidden');
    $mixed = canonicalRecruitmentRecord($this->allowedSite, $this->viewer, 'Mutation Mixed');
    HrApplication::query()->create([
        'candidate_id' => $mixed['candidate']->id,
        'position_title' => 'Hidden Site Role',
        'position_role' => 'support_worker',
        'target_site_id' => $this->hiddenSite->id,
        'status' => 'active',
    ]);

    $this->actingAs($this->viewer)
        ->put(route('hr.candidates.update', $allowed['candidate']), ['preferred_name' => 'Visible update'])
        ->assertRedirect();
    expect($allowed['candidate']->fresh()->preferred_name)->toBe('Visible update');

    $this->actingAs($this->viewer)
        ->put(route('hr.candidates.update', $hidden['candidate']), ['preferred_name' => 'Leaked update'])
        ->assertNotFound();
    $this->actingAs($this->viewer)
        ->put(route('hr.candidates.update', $mixed['candidate']), ['preferred_name' => 'Mixed update'])
        ->assertNotFound();
    expect($hidden['candidate']->fresh()->preferred_name)->toBeNull()
        ->and($mixed['candidate']->fresh()->preferred_name)->toBeNull();

    $this->actingAs($this->viewer)
        ->post(route('hr.applications.bulk'), [
            'action' => 'tag',
            'candidate_ids' => [$allowed['candidate']->id, $hidden['candidate']->id],
            'tag' => 'Must not apply',
        ])
        ->assertNotFound();
    expect($allowed['candidate']->fresh()->tags ?? [])->not->toContain('Must not apply')
        ->and($hidden['candidate']->fresh()->tags ?? [])->not->toContain('Must not apply');

    $this->actingAs($this->viewer)
        ->post(route('hr.applications.reject', $hidden['application']), ['rejection_reason' => 'Hidden'])
        ->assertNotFound();
    $this->actingAs($this->viewer)
        ->post(route('hr.offers.approve', $hidden['offer']))
        ->assertNotFound();
    expect($hidden['application']->fresh()->status)->toBe('offered');
});

test('candidate requisition and offer creation require accessible matching Site provenance', function () {
    $this->actingAs($this->viewer)
        ->post(route('hr.candidates.store'), [
            'first_name' => 'No',
            'last_name' => 'Site',
            'personal_email' => 'no-site@example.test',
            'position_title' => 'Support Worker',
        ])
        ->assertSessionHasErrors('target_site_id');

    $this->actingAs($this->viewer)
        ->post(route('hr.candidates.store'), [
            'first_name' => 'Hidden',
            'last_name' => 'Site',
            'personal_email' => 'hidden-site-create@example.test',
            'position_title' => 'Support Worker',
            'target_site_id' => $this->hiddenSite->id,
        ])
        ->assertNotFound();

    $this->actingAs($this->viewer)
        ->post(route('hr.candidates.store'), [
            'first_name' => 'Canonical',
            'last_name' => 'Site',
            'personal_email' => 'canonical-site-create@example.test',
            'position_title' => 'Support Worker',
            'target_site_id' => $this->allowedSite->id,
        ])
        ->assertRedirect();
    $created = HrCandidate::query()->where('personal_email', 'canonical-site-create@example.test')->firstOrFail();
    expect($created->applications()->sole()->target_site_id)->toBe($this->allowedSite->id);

    $this->actingAs($this->viewer)
        ->post(route('hr.jobs.store'), [
            'title' => 'Hidden Site Role',
            'site_id' => $this->hiddenSite->id,
            'employment_type' => 'full_time',
            'openings' => 1,
            'description' => 'Must remain concealed.',
        ])
        ->assertNotFound();

    $secondAllowedSite = Site::factory()->create([
        'name' => 'Second Allowed Recruitment Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $this->viewer->hrEmployeeProfile()->update([
        'secondary_site_ids' => [$secondAllowedSite->id],
    ]);
    // Resolve the next request with a fresh actor instance. UserSiteAccessService
    // is intentionally request-scoped and caches access for the actor object.
    $this->viewer = $this->viewer->fresh();
    $record = canonicalRecruitmentRecord($this->allowedSite, $this->viewer, 'Offer Site Match');
    $record['offer']->delete();

    $this->actingAs($this->viewer)
        ->post(route('hr.offers.store'), [
            'application_id' => $record['application']->id,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'proposed_start_date' => today()->addMonth()->toDateString(),
            'employment_type' => 'full_time',
            'hours_per_week' => 40,
            'primary_site_id' => $secondAllowedSite->id,
        ])
        ->assertSessionHasErrors('primary_site_id');
    expect($record['application']->offer()->exists())->toBeFalse();
});
