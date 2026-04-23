<?php

use App\Models\SafeguardingConcern;
use App\Models\User;
use Laravel\Dusk\Browser;

test('assigned safeguarding owner can edit and close a concern from the browser', function () {
    $reporter = User::where('email', 'admin@test.com')->firstOrFail();
    $assignee = User::factory()->withoutTwoFactor()->create([
        'name' => 'Assigned Safeguarding QA',
        'email' => 'assigned-safeguarding@example.test',
        'approved_at' => now(),
    ]);

    $concern = SafeguardingConcern::factory()
        ->open()
        ->assignedTo($assignee)
        ->create([
            'reported_by_user_id' => $reporter->id,
            'description' => 'Browser coverage concern for assigned-user workflows.',
            'subject_name' => 'Assigned Workflow Subject',
        ]);

    $this->browse(function (Browser $browser) use ($assignee, $concern) {
        $browser->loginAs($assignee)
            ->visit("/safeguarding/{$concern->id}")
            ->waitForText($concern->reference_number, 10)
            ->clickLink('Edit')
            ->waitForText('Edit safeguarding concern', 10)
            ->assertPathIs("/safeguarding/{$concern->id}/edit")
            ->visit("/safeguarding/{$concern->id}")
            ->waitForText($concern->reference_number, 10)
            ->press('Close')
            ->waitForText('Close Concern', 10)
            ->type(
                'textarea[placeholder="Summarise the outcome and rationale for closure..."]',
                'Concern resolved after assigned-user follow-up.'
            )
            ->type(
                'textarea[placeholder="Any lessons learned or recommendations for future prevention..."]',
                'Keep assignee access aligned with the safeguarding policy.'
            )
            ->press('Close Concern')
            ->waitForText('Closed on', 10)
            ->waitForText('Concern resolved after assigned-user follow-up.', 10);
    });
});
