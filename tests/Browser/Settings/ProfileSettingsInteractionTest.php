<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('profile settings persist the reachable personal details and preferences cards', function () {
    $user = User::where('email', 'admin@test.com')->firstOrFail();
    $phone = '+64 21 ' . random_int(1000000, 9999999);
    $jobTitle = 'QA Sweep ' . now()->format('His');

    $this->browse(function (Browser $browser) use ($user, $phone, $jobTitle) {
        $browser->loginAs($user)
            ->visit('/settings/profile')
            ->waitForText('Profile settings', 10)
            ->type('#phone', $phone)
            ->type('#job_title', $jobTitle)
            ->click('[data-test="update-profile-button"]')
            ->waitForText('Profile saved', 10)
            ->click('#time-12')
            ->click('[data-test="save-preferences-button"]')
            ->waitForText('Preferences saved', 10)
            ->refresh()
            ->waitForText('Profile settings', 10);

        $values = $browser->script(<<<'JS'
            return {
                phone: document.querySelector('#phone')?.value ?? null,
                jobTitle: document.querySelector('#job_title')?.value ?? null,
                time12State: document.querySelector('#time-12')?.getAttribute('data-state') ?? null,
            };
        JS);

        expect($values[0]['phone'])->toBe($phone);
        expect($values[0]['jobTitle'])->toBe($jobTitle);
        expect($values[0]['time12State'])->toBe('checked');
    });
});
