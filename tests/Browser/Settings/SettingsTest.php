<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings')
            ->waitForText('Settings', 10)
            ->assertSee('Settings');
    });
});

test('profile settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/profile')
            ->waitForText('Profile', 10)
            ->assertSee('Profile');
    });
});

test('password settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/password')
            ->waitForText('Password', 10)
            ->assertSee('Password');
    });
});

test('appearance settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/appearance')
            ->waitForText('Appearance', 10)
            ->assertSee('Appearance');
    });
});

test('notifications settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/notifications')
            ->waitForText('Notification', 10)
            ->assertSee('Notification');
    });
});

test('security settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/security')
            ->waitForText('Security', 10)
            ->assertSee('Security');
    });
});

test('two-factor settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/two-factor')
            ->waitForText('Two', 10)
            ->assertSee('Two');
    });
});

test('modules settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/modules')
            ->waitForText('Module', 10)
            ->assertSee('Module');
    });
});

test('branding settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/branding')
            ->waitForText('Branding', 10)
            ->assertSee('Branding');
    });
});

test('email settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/email')
            ->waitForText('Email', 10)
            ->assertSee('Email');
    });
});

test('data settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/data')
            ->waitForText('Data', 10)
            ->assertSee('Data');
    });
});

test('api settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/api')
            ->waitForText('API', 10)
            ->assertSee('API');
    });
});

test('access settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/access')
            ->waitForText('Access', 10)
            ->assertSee('Access');
    });
});

test('roles settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/roles')
            ->waitForText('Role', 10)
            ->assertSee('Role');
    });
});

test('users settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/users')
            ->waitForText('User', 10)
            ->assertSee('User');
    });
});

test('integrations settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/integrations')
            ->waitForText('Integration', 10)
            ->assertSee('Integration');
    });
});

test('sso settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/sso')
            ->waitForText('SSO', 10)
            ->assertSee('SSO');
    });
});

test('sso groups settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/sso-groups')
            ->waitForText('SSO', 10)
            ->assertSee('SSO');
    });
});

test('service contexts settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/service-contexts')
            ->waitForText('Service', 10)
            ->assertSee('Service');
    });
});

test('templates settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/templates')
            ->waitForText('Template', 10)
            ->assertSee('Template');
    });
});

test('terminology settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/terminology')
            ->waitForText('Terminology', 10)
            ->assertSee('Terminology');
    });
});

test('audit logs settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/audit-logs')
            ->waitForText('Audit', 10)
            ->assertSee('Audit');
    });
});

test('notification escalations settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/notification-escalations')
            ->waitForText('Escalation', 10)
            ->assertSee('Escalation');
    });
});

test('notification roles settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/notification-roles')
            ->waitForText('Notification', 10)
            ->assertSee('Notification');
    });
});

test('integrations unifi settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/security-devices/integrations/unifi')
            ->waitForText('UniFi', 10)
            ->assertPathBeginsWith('/security-devices');
    });
});

test('roles create settings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/settings/roles/create')
            ->waitForText('Role', 10)
            ->assertPathBeginsWith('/settings');
    });
});
