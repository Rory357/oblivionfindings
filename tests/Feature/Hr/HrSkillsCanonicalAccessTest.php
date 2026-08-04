<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEmployeeSkill;
use App\Domain\Hr\Models\HrSkill;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->visibleSite = Site::factory()->create(['name' => 'Skills visible Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Skills hidden Site']);
    $this->manager = skillsCanonicalStaff('Skills manager', $this->visibleSite, 'hr');
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->visibleStaff = skillsCanonicalStaff('Visible skilled worker', $this->visibleSite);
    $this->secondVisibleStaff = skillsCanonicalStaff('Second visible worker', $this->visibleSite);
    $this->hiddenStaff = skillsCanonicalStaff('Hidden skilled worker', $this->hiddenSite);
    $this->formerStaff = skillsCanonicalStaff('Former skilled worker', $this->visibleSite, 'support_worker', [
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);
    $this->unapprovedStaff = skillsCanonicalStaff(
        'Unapproved skilled worker',
        $this->visibleSite,
        'support_worker',
        [],
        ['approved_at' => null],
    );
});

function skillsCanonicalStaff(
    string $name,
    Site $site,
    string $role = 'support_worker',
    array $profileOverrides = [],
    array $userOverrides = [],
): User {
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'-'.str()->random(6).'@example.test',
        'role' => $role,
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        ...$profileOverrides,
    ]);

    return $user->fresh('hrEmployeeProfile');
}

function skillsCanonicalDefinition(string $name, string $category = 'Clinical', array $overrides = []): HrSkill
{
    return HrSkill::query()->create([
        'name' => $name,
        'category' => $category,
        'description' => "{$name} description",
        'is_active' => true,
        ...$overrides,
    ]);
}

function skillsCanonicalAssessment(
    HrEmployeeProfile $profile,
    HrSkill $skill,
    User $assessor,
    string $level = 'advanced',
): HrEmployeeSkill {
    return HrEmployeeSkill::query()->create([
        'employee_profile_id' => $profile->id,
        'skill_id' => $skill->id,
        'proficiency_level' => $level,
        'self_assessed' => false,
        'assessed_by' => $assessor->id,
        'assessed_at' => now(),
    ]);
}

test('the catalogue is application wide while counts use only current staff at visible Sites', function (): void {
    $firstAid = skillsCanonicalDefinition('First aid');
    $networking = skillsCanonicalDefinition('Network troubleshooting', 'Technology');
    skillsCanonicalAssessment($this->visibleStaff->hrEmployeeProfile, $firstAid, $this->manager);
    skillsCanonicalAssessment($this->hiddenStaff->hrEmployeeProfile, $firstAid, $this->manager);
    skillsCanonicalAssessment($this->formerStaff->hrEmployeeProfile, $firstAid, $this->manager);

    $response = $this->actingAs($this->manager)
        ->get('/hr/performance/skills')
        ->assertOk();

    $skills = collect($response->inertiaProps('skills.data'));
    expect($skills->pluck('id'))->toContain($firstAid->id, $networking->id)
        ->and($skills->firstWhere('id', $firstAid->id)['employee_skills_count'])->toBe(1)
        ->and($response->inertiaProps('categories'))->toBe(['Clinical', 'Technology']);

    $gap = collect($response->inertiaProps('skillGaps'))->firstWhere('skill_id', $firstAid->id);
    expect($gap['employees_with_skill'])->toBe(1)
        ->and($gap['total_employees'])->toBe(3);

    $hub = $this->actingAs($this->manager)
        ->get('/hr/performance')
        ->assertOk();
    $hubSkill = collect($hub->inertiaProps('competencies.skills'))->firstWhere('id', $firstAid->id);
    expect($hubSkill['count'])->toBe(1);
});

test('the matrix includes only current approved staff from visible Sites', function (): void {
    $skill = skillsCanonicalDefinition('Medication support');
    skillsCanonicalAssessment($this->visibleStaff->hrEmployeeProfile, $skill, $this->manager, 'expert');
    skillsCanonicalAssessment($this->hiddenStaff->hrEmployeeProfile, $skill, $this->manager, 'advanced');

    $response = $this->actingAs($this->manager)
        ->get('/hr/performance/skills/matrix')
        ->assertOk();

    $employees = collect($response->inertiaProps('employees'));
    expect($employees->pluck('employee_id'))
        ->toContain(
            $this->manager->hrEmployeeProfile->id,
            $this->visibleStaff->hrEmployeeProfile->id,
            $this->secondVisibleStaff->hrEmployeeProfile->id,
        )
        ->not->toContain(
            $this->hiddenStaff->hrEmployeeProfile->id,
            $this->formerStaff->hrEmployeeProfile->id,
            $this->unapprovedStaff->hrEmployeeProfile->id,
        )
        ->and($employees->firstWhere('employee_id', $this->visibleStaff->hrEmployeeProfile->id)['skills'][$skill->id])
        ->toBe('expert');
});

test('assessment writes conceal inaccessible people and require an active application skill', function (): void {
    $active = skillsCanonicalDefinition('Positive behaviour support');
    $inactive = skillsCanonicalDefinition('Retired process', 'Operations', ['is_active' => false]);
    $payload = [
        'skill_id' => $active->id,
        'proficiency_level' => 'advanced',
        'notes' => 'Observed safely in practice.',
    ];

    foreach ([
        $this->hiddenStaff,
        $this->formerStaff,
        $this->unapprovedStaff,
    ] as $inaccessible) {
        $this->actingAs($this->manager)
            ->post('/hr/performance/skills/assess', [
                ...$payload,
                'employee_profile_id' => $inaccessible->hrEmployeeProfile->id,
            ])
            ->assertNotFound();
    }

    $this->actingAs($this->manager)
        ->post('/hr/performance/skills/assess', [
            ...$payload,
            'employee_profile_id' => $this->visibleStaff->hrEmployeeProfile->id,
            'skill_id' => $inactive->id,
        ])
        ->assertNotFound();
    expect(HrEmployeeSkill::query()->count())->toBe(0);

    $this->actingAs($this->manager)
        ->post('/hr/performance/skills/assess', [
            ...$payload,
            'employee_profile_id' => $this->visibleStaff->hrEmployeeProfile->id,
        ])
        ->assertSessionHas('success');

    $assessment = HrEmployeeSkill::query()->sole();
    expect($assessment->employee_profile_id)->toBe($this->visibleStaff->hrEmployeeProfile->id)
        ->and($assessment->skill_id)->toBe($active->id)
        ->and($assessment->assessed_by)->toBe($this->manager->id)
        ->and($assessment->self_assessed)->toBeFalse()
        ->and($assessment->assessed_at)->not->toBeNull();

    $this->actingAs($this->manager)
        ->get("/hr/people/{$this->visibleStaff->hrEmployeeProfile->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employeeSkills.0.skill_name', 'Positive behaviour support')
            ->where('employeeSkills.0.proficiency_level', 'advanced'));
});

test('skill creation normalises catalogue identity and rejects duplicates within a category', function (): void {
    skillsCanonicalDefinition('First aid');

    $this->actingAs($this->manager)
        ->post('/hr/performance/skills', [
            'name' => '  First aid  ',
            'category' => '  Clinical  ',
        ])
        ->assertSessionHasErrors('name');

    $this->actingAs($this->manager)
        ->post('/hr/performance/skills', [
            'name' => 'First aid',
            'category' => 'Leadership',
        ])
        ->assertSessionHas('success');

    expect(HrSkill::query()->where('name', 'First aid')->count())->toBe(2);
});

test('view-only staff can inspect their Site matrix but cannot manage the catalogue or assessments', function (): void {
    $viewer = skillsCanonicalStaff('Skills viewer', $this->visibleSite);
    $viewPermission = Permission::query()->where('key', 'hr.skills.view')->firstOrFail();
    $viewer->permissionOverrides()->syncWithoutDetaching([
        $viewPermission->id => ['allowed' => true],
    ]);
    $skill = skillsCanonicalDefinition('Safe documentation');

    $this->actingAs($viewer)
        ->get('/hr/performance/skills/matrix')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.assess', false));
    $this->actingAs($viewer)
        ->post('/hr/performance/skills', [
            'name' => 'Forbidden catalogue entry',
            'category' => 'Operations',
        ])
        ->assertForbidden();
    $this->actingAs($viewer)
        ->post('/hr/performance/skills/assess', [
            'employee_profile_id' => $this->visibleStaff->hrEmployeeProfile->id,
            'skill_id' => $skill->id,
            'proficiency_level' => 'expert',
        ])
        ->assertForbidden();

    expect(HrSkill::query()->where('name', 'Forbidden catalogue entry')->exists())->toBeFalse()
        ->and(HrEmployeeSkill::query()->exists())->toBeFalse();
});
