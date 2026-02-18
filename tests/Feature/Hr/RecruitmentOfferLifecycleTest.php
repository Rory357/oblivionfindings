<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrOffer;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $role = Role::where('name', 'hr')->first();
    if ($role) {
        $this->hr->roles()->syncWithoutDetaching([$role->id]);
    }

    $this->site = Site::factory()->create([
        'tenant_id' => 1,
        'type' => 'house',
    ]);
});

test('hr user can approve send and accept an offer workflow', function () {
    $candidate = HrCandidate::query()->create([
        'tenant_id' => 1,
        'first_name' => 'Mia',
        'last_name' => 'Candidate',
        'personal_email' => 'mia.candidate@example.test',
        'source' => 'direct',
        'status' => 'new',
        'current_stage_entered_at' => now(),
        'created_by' => $this->hr->id,
    ]);

    $application = HrApplication::query()->create([
        'tenant_id' => 1,
        'candidate_id' => $candidate->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'target_site_id' => $this->site->id,
        'status' => 'active',
    ]);

    $offer = HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'proposed_start_date' => now()->addWeek()->toDateString(),
        'employment_type' => 'full_time',
        'hours_per_week' => 40,
        'hourly_rate' => 30,
        'primary_site_id' => $this->site->id,
        'approval_status' => 'draft',
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/approve")
        ->assertSessionHas('success');

    $offer->refresh();
    expect($offer->approval_status)->toBe('approved');
    expect($offer->approved_by)->toBe($this->hr->id);

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/send")
        ->assertSessionHas('success');

    $offer->refresh();
    expect($offer->sent_at)->not->toBeNull();
    expect($offer->candidate_portal_token)->not->toBeNull();

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/offers/{$offer->id}/respond", [
            'response' => 'accepted',
            'signature_name' => 'Mia Candidate',
            'terms_accepted' => '1',
        ])
        ->assertSessionHas('success');

    $offer->refresh();
    $candidate->refresh();

    expect($offer->response)->toBe('accepted');
    expect($offer->signed_full_name)->toBe('Mia Candidate');
    expect($offer->signed_at)->not->toBeNull();
    expect($candidate->status)->toBe('offer_accepted');
});
