<?php

use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceAgreement;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('submitter cannot self-approve a service agreement and draft cannot bypass approval', function () {
    $site = Site::factory()->create([
        'name' => 'Support Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);

    $submitter = User::factory()->create([]);
    \App\Domain\Hr\Models\HrEmployeeProfile::factory()->create(['user_id' => $submitter->id, 'primary_site_id' => $site->id, 'is_active' => true]);

    $manager = User::factory()->create([]);
    \App\Domain\Hr\Models\HrEmployeeProfile::factory()->create(['user_id' => $manager->id, 'primary_site_id' => $site->id, 'is_active' => true]);

    $perm = Permission::query()->firstOrCreate(
        ['key' => 'service_agreements.update'],
        ['description' => 'service_agreements.update', 'group' => 'ops', 'module' => 'Operations']
    );
    $submitter->permissionOverrides()->attach($perm, ['allowed' => true]);
    $manager->permissionOverrides()->attach($perm, ['allowed' => true]);

    $client = Client::factory()->create(['site_id' => $site->id]);

    $agreement = ServiceAgreement::create([
        'client_id' => $client->id,
        'status' => 'draft',
        'agreement_type' => 'ndis',
        'title' => 'Community Care Agreement',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addYear()->toDateString(),
    ]);

    // 1. Direct transition from draft to active without approval is rejected (422)
    $this->actingAs($submitter)
        ->post(route('operations.service_agreements.transition', $agreement), [
            'status' => 'active',
        ])
        ->assertStatus(422);

    // 2. Submitter submits agreement for approval
    $this->actingAs($submitter)
        ->post(route('operations.service_agreements.submit_for_approval', $agreement))
        ->assertRedirect();

    $agreement->refresh();
    expect($agreement->status)->toBe('pending_approval')
        ->and((int) $agreement->submitted_for_approval_by)->toBe($submitter->id);

    // 3. Submitter attempts self-approval -> 403 Forbidden
    $this->actingAs($submitter)
        ->post(route('operations.service_agreements.approve', $agreement))
        ->assertForbidden();

    // 4. Independent manager approves -> Success (active)
    $this->actingAs($manager)
        ->post(route('operations.service_agreements.approve', $agreement))
        ->assertRedirect();

    $agreement->refresh();
    expect($agreement->status)->toBe('active')
        ->and((int) $agreement->approved_by)->toBe($manager->id);
});
