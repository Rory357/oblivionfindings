<?php

use App\Models\DataSubjectRequest;
use App\Models\User;
use Laravel\Dusk\Browser;

test('privacy request show supports identity verification and completion through the browser', function () {
    $user = User::where('email', 'admin@test.com')->firstOrFail();

    $request = DataSubjectRequest::factory()->create([
        'subject_name' => 'QA Requester',
        'subject_email' => 'qa-requester@example.test',
        'status' => 'identity_verification',
        'identity_verified' => 'pending',
        'assigned_to_user_id' => $user->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $this->browse(function (Browser $browser) use ($user, $request) {
        $browser->loginAs($user)
            ->visit("/privacy/requests/{$request->id}")
            ->waitForText($request->reference_number, 10)
            ->press('Verify Identity')
            ->waitFor('[role="dialog"]', 10)
            ->within('[role="dialog"]', function (Browser $dialog) {
                $dialog->assertSee("Verify QA Requester's identity?")
                    ->type('verification_method', 'Passport verification')
                    ->press('Verify identity');
            })
            ->waitUntilMissing('[role="dialog"]', 10)
            ->waitForText('Identity Verified', 10)
            ->press('Mark Complete')
            ->waitFor('[role="dialog"]', 10)
            ->within('[role="dialog"]', function (Browser $dialog) {
                $dialog->assertSee('Mark this request as complete?')
                    ->type('completion_notes', 'Completed during browser QA coverage.')
                    ->press('Mark complete');
            })
            ->waitUntilMissing('[role="dialog"]', 10)
            ->waitForText('completed', 10)
            ->waitForText('Completed during browser QA coverage.', 10);
    });

    $request->refresh();

    expect($request->identity_verified)->toBe('verified');
    expect($request->verification_method)->toBe('Passport verification');
    expect($request->status)->toBe('completed');
    expect($request->completion_notes)->toBe('Completed during browser QA coverage.');
});
