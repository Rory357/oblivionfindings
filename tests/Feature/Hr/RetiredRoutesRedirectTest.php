<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    // Sync hr + provider_manager so the user holds the union of permissions the
    // various retired routes are gated by (employees.viewAny, recruitment.view,
    // surveys.view, announcements.manage, ...).
    $this->user = User::factory()->create([
        'organization_id' => 1,
        'role' => 'provider_manager',
        'approved_at' => now(),
    ]);
    foreach (['hr', 'provider_manager'] as $roleName) {
        if ($role = Role::query()->where('name', $roleName)->first()) {
            $this->user->roles()->syncWithoutDetaching([$role->id]);
        }
    }
});

/**
 * Every concept page retired during the rebuild must keep its old route alive as
 * a redirect (never 404) so bookmarks + route() helpers still resolve.
 */
test('retired HR routes redirect to their canonical replacements', function () {
    $cases = [
        ['/hr/directory', '/hr/people'],            // M1: directory folded into People
        ['/hr/positions', '/hr/people'],            // M1: positions folded into People
        ['/hr/orgchart', '/hr/people'],             // M1: org chart folded into People
        ['/hr/departments', '/hr/people'],          // M1: departments folded into People
        ['/hr/job-postings', '/hr/recruitment/jobs'], // M2: HrJobPosting → requisitions (hr.jobs.index)
        ['/hr/surveys', '/hr/wellbeing'],           // S11: HrSurvey retired → wellbeing
        ['/hr/surveys/create', '/hr/wellbeing'],    // S11
        ['/hr/announcements/create', '/hr/announcements'], // S12: create page → modal
        ['/hr/reports/automations', '/hr/settings/automations'], // M10-B: moved to Settings hub
        ['/hr/reports/webhooks', '/hr/settings/webhooks'],       // M10-B: moved to Settings hub
    ];

    foreach ($cases as [$from, $to]) {
        $response = $this->actingAs($this->user)->get($from);
        $response->assertRedirect();
        // Note: expect()->toContain() is variadic — pass only the needle.
        expect($response->headers->get('Location'))->toContain($to);
    }
});
