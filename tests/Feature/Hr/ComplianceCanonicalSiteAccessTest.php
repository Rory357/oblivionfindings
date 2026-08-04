<?php

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->complianceAllowedSite = Site::factory()->create(['name' => 'Compliance Allowed Site']);
    $this->complianceHiddenSite = Site::factory()->create(['name' => 'Compliance Hidden Site']);
    $this->complianceViewer = complianceCanonicalStaff(
        'Compliance Site Manager',
        $this->complianceAllowedSite,
        ['role' => 'hr'],
        ['position_role' => 'hr'],
    );
    $this->complianceViewer->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);

    $this->complianceRequirement = HrComplianceRequirement::query()->create([
        'code' => 'CANONICAL-COMPLIANCE',
        'name' => 'Canonical compliance requirement',
        'category' => 'training',
        'check_type' => 'manual',
        'hard_stop' => false,
        'is_active' => true,
        'created_by' => $this->complianceViewer->id,
    ]);
    HrComplianceMatrix::query()->create([
        'requirement_id' => $this->complianceRequirement->id,
        'role' => 'support_worker',
        'site_type' => 'all',
        'is_mandatory' => false,
    ]);
});

function complianceCanonicalStaff(
    string $name,
    ?Site $site,
    array $userOverrides = [],
    array $profileOverrides = [],
): User {
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'-private@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    $role = Role::query()->where('name', $user->role)->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }
    $profile = HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-COMP-'.$user->id,
        'work_email' => str($name)->slug().'@work.example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
        'is_active' => true,
        'primary_site_id' => $site?->id,
        'secondary_site_ids' => [],
        ...$profileOverrides,
    ]);
    $user->setRelation('hrEmployeeProfile', $profile);

    return $user;
}

function complianceCanonicalStatus(
    User $staff,
    HrComplianceRequirement $requirement,
    string $status = 'compliant',
): HrStaffComplianceStatus {
    return HrStaffComplianceStatus::query()->create([
        'user_id' => $staff->id,
        'requirement_id' => $requirement->id,
        'status' => $status,
        'evidence_type' => 'manual',
        'notes' => 'Canonical evidence for '.$staff->name,
    ]);
}

test('compliance overview rows counts search and contact fields share the canonical Site boundary', function () {
    $allowed = complianceCanonicalStaff('Allowed Compliance Worker', $this->complianceAllowedSite);
    $hidden = complianceCanonicalStaff('Hidden Compliance Worker', $this->complianceHiddenSite);
    $ended = complianceCanonicalStaff('Ended Compliance Worker', $this->complianceAllowedSite, [], [
        'end_date' => now()->subDay()->toDateString(),
    ]);
    $future = complianceCanonicalStaff('Future Compliance Worker', $this->complianceAllowedSite, [], [
        'start_date' => now()->addDay()->toDateString(),
    ]);
    $inactive = complianceCanonicalStaff('Inactive Compliance Worker', $this->complianceAllowedSite, [], [
        'is_active' => false,
    ]);
    $unapproved = complianceCanonicalStaff('Unapproved Compliance Worker', $this->complianceAllowedSite, [
        'approved_at' => null,
    ]);
    foreach ([$allowed, $hidden, $ended, $future, $inactive, $unapproved] as $staff) {
        complianceCanonicalStatus($staff, $this->complianceRequirement);
    }

    $response = $this->actingAs($this->complianceViewer)
        ->get('/hr/compliance')
        ->assertOk();

    $rows = collect($response->inertiaProps('staffStatuses.data'));
    expect($rows->pluck('user_name')->all())
        ->toContain('Compliance Site Manager', 'Allowed Compliance Worker')
        ->not->toContain(
            'Hidden Compliance Worker',
            'Ended Compliance Worker',
            'Future Compliance Worker',
            'Inactive Compliance Worker',
            'Unapproved Compliance Worker',
        );
    expect($response->inertiaProps('staffStatuses.total'))->toBe(2)
        ->and($response->inertiaProps('hero.summary.total_staff'))->toBe(2)
        ->and($response->inertiaProps('hero.site'))->toBe('Compliance Allowed Site')
        ->and($rows->firstWhere('user_id', $allowed->id)['user_email'])
        ->toBe('allowed-compliance-worker@work.example.test');
    expect(collect($response->inertiaProps('wizard.people'))->pluck('label')->all())
        ->toContain('Compliance Site Manager', 'Allowed Compliance Worker')
        ->not->toContain(
            'Hidden Compliance Worker',
            'Ended Compliance Worker',
            'Future Compliance Worker',
            'Inactive Compliance Worker',
            'Unapproved Compliance Worker',
        );
    expect($response->getContent())
        ->not->toContain($allowed->email, $hidden->email, 'Canonical evidence for Hidden Compliance Worker');

    $this->actingAs($this->complianceViewer)
        ->get('/hr/compliance?q=Hidden+Compliance+Worker')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staffStatuses.total', 0)
            ->where('hero.summary.total_staff', 2));
});

test('compliance staff detail exposes only current Site-visible staff and work contact', function () {
    $allowed = complianceCanonicalStaff('Allowed Compliance Detail', $this->complianceAllowedSite);
    $hidden = complianceCanonicalStaff('Hidden Compliance Detail', $this->complianceHiddenSite);
    $ended = complianceCanonicalStaff('Ended Compliance Detail', $this->complianceAllowedSite, [], [
        'end_date' => now()->subDay()->toDateString(),
    ]);
    foreach ([$allowed, $hidden, $ended] as $staff) {
        complianceCanonicalStatus($staff, $this->complianceRequirement);
    }

    $allowedResponse = $this->actingAs($this->complianceViewer)
        ->get('/hr/compliance/staff/'.$allowed->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staff.id', $allowed->id)
            ->where('staff.email', 'allowed-compliance-detail@work.example.test'));
    expect($allowedResponse->getContent())->not->toContain($allowed->email);

    foreach ([$hidden, $ended] as $concealed) {
        $this->actingAs($this->complianceViewer)
            ->get('/hr/compliance/staff/'.$concealed->id)
            ->assertNotFound();
    }
});

test('compliance staff detail has uniform numeric direct object concealment', function () {
    config(['app.debug' => false]);
    $hidden = complianceCanonicalStaff('Hidden Compliance Parity', $this->complianceHiddenSite);
    $ended = complianceCanonicalStaff('Ended Compliance Parity', $this->complianceAllowedSite, [], [
        'end_date' => now()->subDay()->toDateString(),
    ]);

    $responses = [
        $this->actingAs($this->complianceViewer)->getJson('/hr/compliance/staff/'.$hidden->id),
        $this->actingAs($this->complianceViewer)->getJson('/hr/compliance/staff/'.$ended->id),
        $this->actingAs($this->complianceViewer)->getJson('/hr/compliance/staff/99999999'),
        $this->actingAs($this->complianceViewer)->getJson('/hr/compliance/staff/not-a-number'),
        $this->actingAs($this->complianceViewer)->getJson('/hr/compliance/staff/'.str_repeat('9', 80)),
    ];

    expect(collect($responses)->map(fn ($response) => [
        'status' => $response->status(),
        'body' => $response->json(),
    ])->unique()->values()->all())->toBe([
        ['status' => 404, 'body' => ['message' => '']],
    ]);
});

test('staff compliance export uses the same current Site-visible population and work contact', function () {
    $allowed = complianceCanonicalStaff('Allowed Compliance Export', $this->complianceAllowedSite);
    $hidden = complianceCanonicalStaff('Hidden Compliance Export', $this->complianceHiddenSite);
    $ended = complianceCanonicalStaff('Ended Compliance Export', $this->complianceAllowedSite, [], [
        'end_date' => now()->subDay()->toDateString(),
    ]);
    foreach ([$allowed, $hidden, $ended] as $staff) {
        complianceCanonicalStatus($staff, $this->complianceRequirement);
    }

    $response = $this->actingAs($this->complianceViewer)
        ->get('/hr/compliance/export?dataset=staff')
        ->assertOk();
    $csv = $response->streamedContent();

    expect($csv)
        ->toContain('Allowed Compliance Export', 'allowed-compliance-export@work.example.test')
        ->not->toContain(
            'Hidden Compliance Export',
            'Ended Compliance Export',
            $allowed->email,
            $hidden->email,
        );
});
