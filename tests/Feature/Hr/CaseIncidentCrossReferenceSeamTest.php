<?php

use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrDisciplinaryAction;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

/**
 * Seam S5 — H&S/client incidents ↔ HR cases. `HrCase.linked_incident_ids` is a
 * JSON array of `ClientIncident` ids (docs/hr-module-design.md:900). The seam's
 * integrity guarantee: the link is a *reference*, not a duplicate — creating an
 * HR case that links an incident must NOT copy or mutate the H&S/client-owned
 * `ClientIncident`. These tests prove that guarantee end-to-end through the
 * Site-scoped picker, stored reference and read-only case-detail payload.
 */
beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->manager = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->manager->roles()->syncWithoutDetaching([$hrRole->id]);
    }
    foreach (['hr.cases.manage', 'hr.cases.view', 'hr.disciplinary.manage', 'incidents.viewAny'] as $key) {
        $perm = Permission::query()->where('key', $key)->first();
        if ($perm) {
            $this->manager->permissionOverrides()->syncWithoutDetaching([
                $perm->id => ['allowed' => true],
            ]);
        }
    }

    $this->subject = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->allowedSite = Site::factory()->create([
        'name' => 'Allowed Site',
    ]);
    $this->hiddenSite = Site::factory()->create([
        'name' => 'Hidden Site',
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->manager->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->subject->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
    ]);
    $this->hiddenSubject = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hiddenSubject->id,
        'primary_site_id' => $this->hiddenSite->id,
        'is_active' => true,
    ]);
});

test('case lists summaries and staff pickers use approved Sites', function () {
    $allowed = HrCase::factory()->create([
        'user_id' => $this->subject->id,
        'status' => 'open',
    ]);
    $hidden = HrCase::factory()->create([
        'user_id' => $this->hiddenSubject->id,
        'status' => 'open',
    ]);

    $response = $this->actingAs($this->manager)->get('/hr/cases')->assertOk();

    expect(collect($response->inertiaProps('cases.data'))->pluck('id')->all())
        ->toContain($allowed->id)
        ->not->toContain($hidden->id)
        ->and($response->inertiaProps('summary.open_cases'))->toBe(1)
        ->and(collect($response->inertiaProps('staff'))->pluck('id')->all())
        ->toContain($this->subject->id)
        ->not->toContain($this->hiddenSubject->id);
});

test('case show conceals a case whose subject is at a hidden Site', function () {
    $hidden = HrCase::factory()->create([
        'user_id' => $this->hiddenSubject->id,
        'status' => 'open',
    ]);

    $this->actingAs($this->manager)
        ->get("/hr/cases/{$hidden->id}")
        ->assertNotFound();
});

test('historical cases retain Site provenance without making former staff eligible for new work', function () {
    $historicalStaff = function (Site $site): User {
        $staff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => null,
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $staff->id,
            'primary_site_id' => $site->id,
            'is_active' => false,
            'end_date' => now()->subDay()->toDateString(),
        ]);

        return $staff;
    };

    $allowedFormerStaff = $historicalStaff($this->allowedSite);
    $hiddenFormerStaff = $historicalStaff($this->hiddenSite);
    $allowedCase = HrCase::factory()->create([
        'user_id' => $allowedFormerStaff->id,
        'is_confidential' => false,
        'status' => 'open',
    ]);
    $hiddenCase = HrCase::factory()->create([
        'user_id' => $hiddenFormerStaff->id,
        'is_confidential' => false,
        'status' => 'open',
    ]);

    $index = $this->actingAs($this->manager)->get('/hr/cases')->assertOk();
    expect(collect($index->inertiaProps('cases.data'))->pluck('id')->all())
        ->toContain($allowedCase->id)
        ->not->toContain($hiddenCase->id)
        ->and(collect($index->inertiaProps('staff'))->pluck('id')->all())
        ->not->toContain($allowedFormerStaff->id, $hiddenFormerStaff->id);

    $this->actingAs($this->manager)
        ->get("/hr/cases/{$allowedCase->id}")
        ->assertOk();
    $this->actingAs($this->manager)
        ->get("/hr/cases/{$hiddenCase->id}")
        ->assertNotFound();

    $this->actingAs($this->manager)->post('/hr/cases', [
        'user_id' => $allowedFormerStaff->id,
        'case_type' => 'welfare',
        'severity' => 'medium',
        'title' => 'Former staff must not become a new subject',
    ])->assertSessionHasErrors(['user_id']);
});

test('case update conceals a case whose subject is at a hidden Site', function () {
    $hidden = HrCase::factory()->create([
        'user_id' => $this->hiddenSubject->id,
        'title' => 'Hidden original title',
        'status' => 'open',
    ]);

    $this->actingAs($this->manager)
        ->put("/hr/cases/{$hidden->id}", ['title' => 'Leaked update'])
        ->assertNotFound();

    expect($hidden->fresh()->title)->toBe('Hidden original title');
});

test('case event creation conceals a case whose subject is at a hidden Site', function () {
    $hidden = HrCase::factory()->create([
        'user_id' => $this->hiddenSubject->id,
        'status' => 'open',
    ]);

    $this->actingAs($this->manager)
        ->post("/hr/cases/{$hidden->id}/events", [
            'event_type' => 'note',
            'title' => 'Hidden case event',
            'occurred_at' => now()->toIso8601String(),
        ])
        ->assertNotFound();

    expect($hidden->events()->exists())->toBeFalse();
});

test('disciplinary create and store conceal a parent case whose subject is at a hidden Site', function () {
    $hidden = HrCase::factory()->create([
        'user_id' => $this->hiddenSubject->id,
        'case_type' => 'disciplinary',
        'status' => 'open',
    ]);

    $this->actingAs($this->manager)
        ->get("/hr/cases/{$hidden->id}/disciplinary/create")
        ->assertNotFound();

    $this->actingAs($this->manager)
        ->post("/hr/cases/{$hidden->id}/disciplinary", [
            'employee_user_id' => $this->hiddenSubject->id,
            'action_type' => 'written_warning',
            'allegation_summary' => 'This hidden case must not be writable.',
        ])
        ->assertNotFound();

    expect($hidden->disciplinaryActions()->exists())->toBeFalse();
});

test('disciplinary direct action routes conceal inaccessible or subject-mismatched parent records', function () {
    $hiddenCase = HrCase::factory()->create([
        'user_id' => $this->hiddenSubject->id,
        'case_type' => 'disciplinary',
        'status' => 'open',
    ]);
    $hiddenAction = HrDisciplinaryAction::query()->create([
        'case_id' => $hiddenCase->id,
        'employee_user_id' => $this->hiddenSubject->id,
        'stage' => 'allegation_raised',
        'action_type' => 'written_warning',
        'allegation_summary' => 'Hidden Site action',
        'created_by' => $this->manager->id,
    ]);

    $allowedCase = HrCase::factory()->create([
        'user_id' => $this->subject->id,
        'case_type' => 'disciplinary',
        'status' => 'open',
    ]);
    $mismatchedAction = HrDisciplinaryAction::query()->create([
        'case_id' => $allowedCase->id,
        'employee_user_id' => $this->hiddenSubject->id,
        'stage' => 'allegation_raised',
        'action_type' => 'written_warning',
        'allegation_summary' => 'Mismatched subject action',
        'created_by' => $this->manager->id,
    ]);

    foreach ([$hiddenAction, $mismatchedAction] as $action) {
        $this->actingAs($this->manager)
            ->get("/hr/cases/disciplinary/{$action->id}/edit")
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->put("/hr/cases/disciplinary/{$action->id}", [
                'allegation_summary' => 'Forged update',
            ])
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->post("/hr/cases/disciplinary/{$action->id}/advance")
            ->assertNotFound();

        expect($action->fresh()->allegation_summary)->not->toBe('Forged update')
            ->and($action->fresh()->stage)->toBe('allegation_raised');
    }
});

test('disciplinary creation binds the action to its exact case subject and rejects hidden investigators', function () {
    $case = HrCase::factory()->create([
        'user_id' => $this->subject->id,
        'case_type' => 'disciplinary',
        'status' => 'open',
    ]);

    $this->actingAs($this->manager)
        ->post("/hr/cases/{$case->id}/disciplinary", [
            'employee_user_id' => $this->manager->id,
            'action_type' => 'written_warning',
            'allegation_summary' => 'Wrong subject',
        ])
        ->assertSessionHasErrors(['employee_user_id']);

    $this->actingAs($this->manager)
        ->post("/hr/cases/{$case->id}/disciplinary", [
            'employee_user_id' => $this->subject->id,
            'investigator_user_id' => $this->hiddenSubject->id,
            'action_type' => 'written_warning',
            'allegation_summary' => 'Hidden investigator',
        ])
        ->assertSessionHasErrors(['investigator_user_id']);

    expect($case->disciplinaryActions()->exists())->toBeFalse();
});

test('case close conceals a case whose subject is at a hidden Site', function () {
    $hidden = HrCase::factory()->create([
        'user_id' => $this->hiddenSubject->id,
        'status' => 'open',
    ]);

    $this->actingAs($this->manager)
        ->post("/hr/cases/{$hidden->id}/close", [
            'outcome' => 'Must not be written',
            'outcome_type' => 'resolved',
        ])
        ->assertNotFound();

    expect($hidden->fresh()->status)->toBe('open');
});

test('case creation rejects a current subject at a hidden Site', function () {
    $this->actingAs($this->manager)->post('/hr/cases', [
        'user_id' => $this->hiddenSubject->id,
        'case_type' => 'welfare',
        'severity' => 'medium',
        'title' => 'Hidden Site subject case',
    ])->assertSessionHasErrors(['user_id']);

    expect(HrCase::query()->where('title', 'Hidden Site subject case')->exists())->toBeFalse();
});

test('confidential cases are concealed from viewers outside the access list', function () {
    $viewer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $viewer->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
    ]);
    $permission = Permission::query()->where('key', 'hr.cases.view')->firstOrFail();
    $viewer->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    $case = HrCase::factory()->create([
        'user_id' => $this->subject->id,
        'reported_by' => $this->manager->id,
        'created_by' => $this->manager->id,
        'is_confidential' => true,
        'access_list' => [],
        'status' => 'open',
    ]);

    $this->actingAs($viewer)
        ->get("/hr/cases/{$case->id}")
        ->assertNotFound();
});

test('the case wizard offers only incidents from approved Sites', function () {
    $allowed = ClientIncident::factory()->create([
        'title' => 'Allowed Site fall',
        'client_id' => Client::factory()->create([
            'site_id' => $this->allowedSite->id,
        ])->id,
    ]);
    $hidden = ClientIncident::factory()->create([
        'title' => 'Hidden Site incident',
        'client_id' => Client::factory()->create([
            'site_id' => $this->hiddenSite->id,
        ])->id,
    ]);

    $response = $this->actingAs($this->manager)->get('/hr/cases');

    $response->assertOk();
    expect(collect($response->inertiaProps('incidents'))->pluck('id')->all())
        ->toContain($allowed->id)
        ->not->toContain($hidden->id);
});

test('S5 seam: an HR case references and renders a client incident without mutating the H&S-owned record', function () {
    $incident = ClientIncident::factory()->create([
        'title' => 'Fall during supported outing',
        'client_id' => Client::factory()->create([
            'site_id' => $this->allowedSite->id,
        ])->id,
    ]);
    $originalUpdatedAt = $incident->updated_at;

    $this->actingAs($this->manager)->post('/hr/cases', [
        'user_id' => $this->subject->id,
        'case_type' => 'investigation',
        'severity' => 'medium',
        'title' => 'Investigation linked to incident',
        'linked_incident_ids' => [$incident->id],
    ])->assertSessionHas('success');

    $case = HrCase::query()->where('title', 'Investigation linked to incident')->first();
    expect($case)->not->toBeNull();

    // The cross-reference is stored on the HR-owned case as plain ids.
    expect($case->linked_incident_ids)->toContain($incident->id);

    $response = $this->actingAs($this->manager)->get("/hr/cases/{$case->id}");
    $response->assertOk();

    expect($response->inertiaProps('linkedIncidents.0.id'))->toBe($incident->id)
        ->and($response->inertiaProps('linkedIncidents.0.title'))->toBe('Fall during supported outing');

    // One owner per fact: the H&S/client-owned incident is untouched — HR
    // referenced it, did not copy or mutate it, and created no duplicate.
    expect(ClientIncident::query()->count())->toBe(1);
    expect($incident->fresh()->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});

test('a case cannot reference an incident at an inaccessible Site', function () {
    $hidden = ClientIncident::factory()->create([
        'client_id' => Client::factory()->create([
            'site_id' => $this->hiddenSite->id,
        ])->id,
    ]);

    $this->actingAs($this->manager)->post('/hr/cases', [
        'user_id' => $this->subject->id,
        'case_type' => 'investigation',
        'severity' => 'medium',
        'title' => 'Hidden Site link attempt',
        'linked_incident_ids' => [$hidden->id],
    ])->assertSessionHasErrors(['linked_incident_ids.0']);

    expect(HrCase::query()->where('title', 'Hidden Site link attempt')->exists())
        ->toBeFalse();
});

test('assigned-only incident readers see only incidents for their assigned clients', function () {
    $reader = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $reader->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
    ]);
    foreach (['hr.cases.view', 'hr.cases.manage', 'incidents.viewAssigned'] as $key) {
        $permission = Permission::query()->where('key', $key)->firstOrFail();
        $reader->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }
    $viewAny = Permission::query()->where('key', 'incidents.viewAny')->firstOrFail();
    $reader->permissionOverrides()->syncWithoutDetaching([
        $viewAny->id => ['allowed' => false],
    ]);

    $assignedClient = Client::factory()->create([
        'site_id' => $this->allowedSite->id,
    ]);
    $assignedClient->supportWorkers()->attach($reader->id);
    $otherClient = Client::factory()->create([
        'site_id' => $this->allowedSite->id,
    ]);
    $assignedIncident = ClientIncident::factory()->create([
        'client_id' => $assignedClient->id,
        'title' => 'Assigned client incident',
    ]);
    $otherIncident = ClientIncident::factory()->create([
        'client_id' => $otherClient->id,
        'title' => 'Other client incident',
    ]);

    $response = $this->actingAs($reader)->get('/hr/cases')->assertOk();

    expect(collect($response->inertiaProps('incidents'))->pluck('id')->all())
        ->toContain($assignedIncident->id)
        ->not->toContain($otherIncident->id);
});

test('case subjects assignees and access-list recipients must be current approved staff', function () {
    $validStaff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $validStaff->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
    ]);
    $portalUser = User::factory()->create([
        'role' => 'client',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $portalUser->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
    ]);
    $endedStaff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $endedStaff->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
        'end_date' => now()->subDay()->toDateString(),
    ]);

    $picker = $this->actingAs($this->manager)->get('/hr/cases')->assertOk();
    expect(collect($picker->inertiaProps('staff'))->pluck('id')->all())
        ->toContain($validStaff->id)
        ->not->toContain($portalUser->id, $endedStaff->id);

    $this->actingAs($this->manager)->post('/hr/cases', [
        'user_id' => $portalUser->id,
        'case_type' => 'welfare',
        'severity' => 'medium',
        'title' => 'Invalid portal subject',
    ])->assertSessionHasErrors(['user_id']);

    $this->actingAs($this->manager)->post('/hr/cases', [
        'user_id' => $validStaff->id,
        'assigned_to' => $portalUser->id,
        'access_list' => [$endedStaff->id],
        'case_type' => 'welfare',
        'severity' => 'medium',
        'title' => 'Invalid case recipients',
    ])->assertSessionHasErrors(['assigned_to', 'access_list.0']);

    expect(HrCase::query()->whereIn('title', [
        'Invalid portal subject',
        'Invalid case recipients',
    ])->exists())->toBeFalse();
});

test('case staff validation does not reveal missing non-current or hidden staff identifiers', function (string $field, string $errorKey) {
    $endedStaff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $endedStaff->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => false,
        'end_date' => now()->subDay()->toDateString(),
    ]);
    $missingId = User::query()->max('id') + 10000;
    $errors = [];

    foreach ([
        'missing' => $missingId,
        'non_current' => $endedStaff->id,
        'hidden' => $this->hiddenSubject->id,
    ] as $kind => $candidateId) {
        $payload = [
            'user_id' => $this->subject->id,
            'case_type' => 'welfare',
            'severity' => 'medium',
            'title' => "Enumeration-safe {$field} {$kind}",
        ];
        if ($field === 'user_id') {
            $payload['user_id'] = $candidateId;
        } elseif ($field === 'access_list') {
            $payload['access_list'] = [$candidateId];
        } else {
            $payload[$field] = $candidateId;
        }

        $response = $this->actingAs($this->manager)
            ->post('/hr/cases', $payload)
            ->assertSessionHasErrors([$errorKey]);
        $errors[$kind] = $response->getSession()
            ->get('errors')
            ->getBag('default')
            ->messages();
    }

    expect($errors['missing'])
        ->toBe($errors['non_current'])
        ->toBe($errors['hidden'])
        ->toBe([
            $errorKey => ['The selected staff member is not available.'],
        ]);
})->with([
    'subject' => ['user_id', 'user_id'],
    'assignee' => ['assigned_to', 'assigned_to'],
    'access list' => ['access_list', 'access_list.0'],
]);
