<?php

use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Models\User;
use Laravel\Dusk\Browser;

/** Click the first visible element (button/tile/row) containing the text. */
function clickFeedbackElementWithText(Browser $browser, string $text): void
{
    $escaped = json_encode($text, JSON_THROW_ON_ERROR);
    $script = str_replace('__TEXT__', $escaped, <<<'JS'
        const wanted = __TEXT__;
        const candidates = Array.from(document.querySelectorAll('button'))
            .filter((element) => element.textContent?.includes(wanted));

        if (candidates.length === 0) {
            throw new Error('Clickable element not found: ' + wanted);
        }

        candidates[0].scrollIntoView({ block: 'center' });
        candidates[0].click();
    JS);

    $browser->script($script);
    $browser->pause(250);
}

test('hr feedback request wizard submits through the live browser flow', function () {
    $admin = User::where('email', 'admin@test.com')->firstOrFail();
    $subject = User::where('email', 'staff@test.com')->firstOrFail();
    $reviewer = User::where('email', 'manager@test.com')->firstOrFail();

    $initialCount = HrFeedbackRequest::count();

    $this->browse(function (Browser $browser) use ($admin, $subject, $reviewer, $initialCount) {
        // The old full-page form redirects to the index with the wizard open.
        $browser->loginAs($admin)
            ->visit('/hr/feedback/request')
            ->waitForText('Who is the feedback about?', 10);

        // Step 1 — subject employee.
        clickFeedbackElementWithText($browser, $subject->name);
        $browser->press('Continue')->pause(300);

        // Step 2 — review type.
        clickFeedbackElementWithText($browser, 'Manager review');
        $browser->press('Continue')->pause(300);

        // Step 3 — reviewer multi-select.
        clickFeedbackElementWithText($browser, $reviewer->name);
        $browser->press('Continue')->pause(300);

        // Step 4 — questions/template (defaults are fine).
        $browser->press('Continue')->pause(300);

        // Step 5 — review & send.
        $browser->press('Send requests')
            ->waitForText('Feedback requests sent', 10);

        expect(HrFeedbackRequest::count())->toBeGreaterThan($initialCount);
    });
});
