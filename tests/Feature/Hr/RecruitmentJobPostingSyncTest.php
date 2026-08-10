<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $this->site = Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'position_role' => 'hr',
        'is_active' => true,
        'start_date' => today()->subYear(),
        'end_date' => null,
    ]);
});

test('hr can sync and unpublish external posting channels for published jobs', function () {
    $this->actingAs($this->hr)
        ->post('/hr/recruitment/jobs', [
            'title' => 'Weekend Support Worker',
            'position_role' => 'support_worker',
            'site_id' => $this->site->id,
            'employment_type' => 'casual',
            'openings' => 2,
            'summary' => 'Weekend roster role.',
            'description' => 'Support weekend shifts.',
            'requirements' => 'NZ residency',
            'responsibilities' => 'Client support',
            'posting_channels' => ['linkedin', 'seek'],
            'closing_at' => now()->addWeeks(2)->toDateString(),
        ])
        ->assertSessionHas('success');

    $job = HrJobRequisition::query()->where('title', 'Weekend Support Worker')->first();
    expect($job)->not->toBeNull();

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/jobs/{$job->id}/publish")
        ->assertSessionHas('success');

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/jobs/{$job->id}/sync-posting")
        ->assertSessionHas('success');

    $job->refresh();
    expect($job->external_posting_status)->toBe('posted');
    expect($job->external_posted_at)->not->toBeNull();
    expect($job->external_sync_at)->not->toBeNull();
    expect($job->external_reference)->toBeArray();

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/jobs/{$job->id}/unpublish-posting")
        ->assertSessionHas('success');

    $job->refresh();
    expect($job->external_posting_status)->toBe('not_posted');
});

test('sync posting requires job to be published', function () {
    $job = HrJobRequisition::query()->create([
        'title' => 'Draft Job',
        'slug' => 'draft-job',
        'position_role' => 'support_worker',
        'site_id' => $this->site->id,
        'employment_type' => 'full_time',
        'openings' => 1,
        'status' => 'draft',
        'description' => 'Draft description',
        'posting_channels' => ['linkedin'],
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/recruitment/jobs/{$job->id}/sync-posting")
        ->assertSessionHasErrors(['job']);
});
