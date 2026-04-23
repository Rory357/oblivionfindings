<?php

use App\Models\AppSetting;
use App\Models\Identity;
use App\Models\User;
use Laravel\Dusk\Browser;

test('email settings persist provider selection and real connection state', function () {
    $admin = User::where('email', 'admin@test.com')->firstOrFail();
    $fromAddress = 'qa+' . now()->format('His') . '@example.com';
    $fromName = 'QA Sweep ' . now()->format('His');

    AppSetting::query()->whereIn('key', [
        'settings.email.configuration',
        'settings.email.smtp_password',
    ])->delete();

    Identity::query()
        ->where('user_id', $admin->id)
        ->whereIn('provider', ['microsoft', 'google'])
        ->delete();

    Identity::create([
        'user_id' => $admin->id,
        'provider' => 'microsoft',
        'provider_user_id' => 'ms-' . now()->timestamp,
        'email' => 'microsoft-linked@example.com',
    ]);

    Identity::create([
        'user_id' => $admin->id,
        'provider' => 'google',
        'provider_user_id' => 'google-' . now()->timestamp,
        'email' => 'google-linked@example.com',
    ]);

    $this->browse(function (Browser $browser) use ($admin, $fromAddress, $fromName) {
        $browser->loginAs($admin)
            ->visit('/settings/email')
            ->waitForText('Email Configuration', 10)
            ->assertSee('microsoft-linked@example.com')
            ->assertSee('google-linked@example.com')
            ->type('[dusk="email-smtp-host"]', 'smtp.mail.test')
            ->type('[dusk="email-smtp-port"]', '2525')
            ->type('[dusk="email-smtp-username"]', 'qa-user')
            ->type('[dusk="email-from-address"]', $fromAddress)
            ->type('[dusk="email-from-name"]', $fromName)
            ->click('[dusk="email-provider-microsoft"]')
            ->click('[dusk="email-save"]')
            ->waitForText('Email settings updated.', 10)
            ->waitForText('Microsoft 365 Connection', 10)
            ->refresh()
            ->waitForText('Email Configuration', 10)
            ->waitForText('Microsoft 365 Connection', 10);

        $values = $browser->script(<<<'JS'
            return {
                fromAddress: document.querySelector('[dusk="email-from-address"]')?.value ?? '',
                fromName: document.querySelector('[dusk="email-from-name"]')?.value ?? '',
                selectedMicrosoft: document.querySelector('[dusk="email-provider-microsoft"]')?.className.includes('border-violet-600') ?? false,
            };
        JS);

        expect($values[0]['fromAddress'])->toBe($fromAddress);
        expect($values[0]['fromName'])->toBe($fromName);
        expect($values[0]['selectedMicrosoft'])->toBeTrue();
    });

    $settings = AppSetting::query()->where('key', 'settings.email.configuration')->value('value');

    expect($settings['provider'] ?? null)->toBe('microsoft');
    expect($settings['smtp_host'] ?? null)->toBe('smtp.mail.test');
    expect((int) ($settings['smtp_port'] ?? 0))->toBe(2525);
    expect($settings['smtp_username'] ?? null)->toBe('qa-user');
    expect($settings['from_address'] ?? null)->toBe($fromAddress);
    expect($settings['from_name'] ?? null)->toBe($fromName);
});

test('email settings test action uses a real backend request', function () {
    $admin = User::where('email', 'admin@test.com')->firstOrFail();

    AppSetting::query()->whereIn('key', [
        'settings.email.configuration',
        'settings.email.smtp_password',
    ])->delete();

    $this->browse(function (Browser $browser) use ($admin) {
        $browser->loginAs($admin)
            ->visit('/settings/email')
            ->waitForText('Email Configuration', 10)
            ->type('[dusk="email-from-address"]', 'noreply@example.com')
            ->type('[dusk="email-from-name"]', 'Oblivion Findings QA')
            ->click('[dusk="email-test"]')
            ->waitForText('Test email sent to ' . $admin->email . '.', 10);
    });
});

test('email settings page is forbidden without access-management permission', function () {
    $staff = User::where('email', 'staff@test.com')->firstOrFail();

    $this->browse(function (Browser $browser) use ($staff) {
        $browser->loginAs($staff)
            ->visit('/settings/email')
            ->waitForText('403', 10)
            ->assertDontSee('Email Configuration');
    });
});
