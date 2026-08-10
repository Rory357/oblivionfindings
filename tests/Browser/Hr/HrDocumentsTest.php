<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr documents index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/documents')
            ->waitForText('Document', 10)
            ->assertPathIs('/hr/documents');
    });
});

test('hr documents templates page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/documents/templates')
            ->waitForText('Template', 10)
            ->assertPathIs('/hr/documents/templates');
    });
});

test('hr documents upload page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/documents/upload')
            ->waitForText('Upload', 10)
            ->assertPathIs('/hr/documents/upload');
    });
});

test('hr documents templates create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/documents/templates/create')
            ->waitForText('Template', 10)
            ->assertPathBeginsWith('/hr/documents/templates');
    });
});

test('hr import-export template page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/import-export')
            ->waitForText('Import / Export', 10)
            ->assertSee('Download blank template')
            ->assertPathIs('/hr/import-export');
    });
});
