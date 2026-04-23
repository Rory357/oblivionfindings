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
            ->script(<<<'JS'
                window.__codexPromptValues = [
                    'Passport verification',
                    'Completed during browser QA coverage.',
                ];

                window.prompt = () => window.__codexPromptValues.shift() ?? '';
            JS);

        $browser->press('Verify Identity')
            ->waitForText('Identity Verified', 10)
            ->press('Mark Complete')
            ->waitForText('completed', 10)
            ->waitForText('Completed during browser QA coverage.', 10);
    });

    $request->refresh();

    expect($request->identity_verified)->toBe('verified');
    expect($request->status)->toBe('completed');
    expect($request->completion_notes)->toBe('Completed during browser QA coverage.');
});
