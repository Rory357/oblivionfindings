<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr job postings index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/job-postings')
            ->waitForText('Job Posting', 10)
            ->assertPathIs('/hr/job-postings');
    });
});

test('hr job postings create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/job-postings/create')
            ->waitForText('Job Posting', 10)
            ->assertPathIs('/hr/job-postings/create');
    });
});

test('hr job postings edit page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $posting = \App\Domain\Hr\Models\HrJobPosting::factory()->create([
            'tenant_id' => 1,
            'title' => 'Test Posting',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);
        $browser->loginAs($user)
            ->visit('/hr/job-postings/' . $posting->id . '/edit')
            ->waitForText('Job Posting', 10)
            ->assertPathBeginsWith('/hr/job-postings');
    });
});

test('hr job postings preview page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $posting = \App\Domain\Hr\Models\HrJobPosting::factory()->create([
            'tenant_id' => 1,
            'title' => 'Test Preview Posting',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);
        $browser->loginAs($user)
            ->visit('/hr/job-postings/' . $posting->id . '/preview')
            ->waitForText('Test Preview Posting', 10)
            ->assertPathBeginsWith('/hr/job-postings');
    });
});
