<?php

use App\Domain\Hr\Models\HrEmployeeSkill;
use App\Domain\Hr\Models\HrSkill;
use App\Models\User;
use Laravel\Dusk\Browser;

test('skills catalogue and matrix provide a clear desktop management workflow', function (): void {
    $admin = User::query()->where('email', 'admin@test.com')->firstOrFail();
    $staff = User::query()->where('email', 'staff@test.com')->firstOrFail();
    $staff->loadMissing('hrEmployeeProfile');
    $profile = $staff->hrEmployeeProfile;
    expect($profile)->not->toBeNull();

    $skill = HrSkill::query()->firstOrCreate(
        ['category' => 'Technology', 'name' => 'Device incident triage'],
        [
            'description' => 'Recognise, record and escalate an IT device incident.',
            'is_active' => true,
        ],
    );
    HrEmployeeSkill::query()->updateOrCreate(
        [
            'employee_profile_id' => $profile->id,
            'skill_id' => $skill->id,
        ],
        [
            'proficiency_level' => 'advanced',
            'self_assessed' => false,
            'assessed_by' => $admin->id,
            'assessed_at' => now(),
        ],
    );

    $this->browse(function (Browser $browser) use ($admin): void {
        $browser->loginAs($admin)
            ->resize(1440, 900)
            ->visit('/hr/performance/skills')
            ->waitForText('Device incident triage', 25)
            ->assertSee('Skill Gaps Detected')
            ->assertSee('Skills matrix')
            ->assertScript(
                'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                true,
            )
            ->press('New skill')
            ->waitForText('New Skill', 10)
            ->assertInputPresent('name')
            ->assertInputPresent('category')
            ->type('name', 'QA escalation evidence')
            ->type('category', 'Technology')
            ->press('Create')
            ->waitForText('QA escalation evidence', 20)
            ->assertScript(
                'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                true,
            )
            ->visit('/hr/performance/skills/matrix')
            ->waitForText('Skills Matrix', 25)
            ->assertSee('Device incident triage')
            ->assertSee('Test Staff')
            ->assertSee('Advanced')
            ->assertSee('Not assessed')
            ->assertScript(
                'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                true,
            );

        $severeLogs = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry): bool => strtoupper((string) ($entry['level'] ?? '')) === 'SEVERE')
            ->values()
            ->all();

        $this->assertSame([], $severeLogs, json_encode($severeLogs));
    });
});
