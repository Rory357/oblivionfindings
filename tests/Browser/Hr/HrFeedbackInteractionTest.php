<?php

use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Models\User;
use Laravel\Dusk\Browser;

function chooseFeedbackSelectOption(Browser $browser, int $index, string $optionText): void
{
    $encodedIndex = json_encode($index, JSON_THROW_ON_ERROR);
    $openComboboxScript = str_replace('__INDEX__', $encodedIndex, <<<'JS'
        const triggerIndex = __INDEX__;
        const triggers = Array.from(document.querySelectorAll('[role="combobox"]'));
        const trigger = triggers[triggerIndex] ?? null;

        if (!trigger) {
            throw new Error('Combobox trigger not found at index ' + triggerIndex + '.');
        }

        trigger.click();
    JS);

    $browser->script($openComboboxScript);

    $browser->pause(250);

    $escapedOption = json_encode($optionText, JSON_THROW_ON_ERROR);
    $selectOptionScript = str_replace('__OPTION_TEXT__', $escapedOption, <<<'JS'
        const optionText = __OPTION_TEXT__;
        const option = Array.from(document.querySelectorAll('[role="option"]'))
            .find((element) => element.textContent?.trim().includes(optionText));

        if (!option) {
            throw new Error('Option not found: ' + optionText);
        }

        option.click();
    JS);

    $browser->script($selectOptionScript);

    $browser->pause(250);
}

test('hr feedback request form submits through the live browser flow', function () {
    $admin = User::where('email', 'admin@test.com')->firstOrFail();
    $subject = User::where('email', 'staff@test.com')->firstOrFail();
    $reviewer = User::where('email', 'manager@test.com')->firstOrFail();

    $initialCount = HrFeedbackRequest::count();

    $this->browse(function (Browser $browser) use ($admin, $subject, $reviewer, $initialCount) {
        $browser->loginAs($admin)
            ->visit('/hr/feedback/request')
            ->waitForText('Request 360-Degree Feedback', 10);

        chooseFeedbackSelectOption($browser, 0, $subject->name);
        chooseFeedbackSelectOption($browser, 1, 'Manager Review');

        $escapedReviewer = json_encode($reviewer->name, JSON_THROW_ON_ERROR);
        $selectReviewerScript = str_replace('__REVIEWER_NAME__', $escapedReviewer, <<<'JS'
            const reviewerName = __REVIEWER_NAME__;
            const label = Array.from(document.querySelectorAll('label'))
                .find((element) => element.textContent?.includes(reviewerName));

            if (!label) {
                throw new Error('Reviewer label not found: ' + reviewerName);
            }

            label.scrollIntoView({ block: 'center' });
            label.click();
        JS);

        $browser->script($selectReviewerScript);

        $browser->press('Send Requests')
            ->waitForLocation('/hr/feedback', 10)
            ->waitForText('360-degree feedback requests sent.', 10);

        expect(HrFeedbackRequest::count())->toBeGreaterThan($initialCount);
    });
});
