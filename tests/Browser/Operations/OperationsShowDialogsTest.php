<?php

use App\Models\Client;
use App\Models\ClientAssessment;
use App\Models\Shift;
use App\Models\User;
use Laravel\Dusk\Browser;

function installDialogWarningCapture(Browser $browser): void
{
    $browser->script(<<<'JS'
        window.__codexDialogConsole = [];

        for (const level of ['warn', 'error']) {
            const originalKey = '__codexOriginal' + level;

            if (!console[originalKey]) {
                console[originalKey] = console[level];
            }

            console[level] = function (...args) {
                const message = args
                    .map((arg) => {
                        if (typeof arg === 'string') {
                            return arg;
                        }

                        try {
                            return JSON.stringify(arg);
                        } catch (error) {
                            return String(arg);
                        }
                    })
                    .join(' ');

                window.__codexDialogConsole.push({ level, message });

                return console[originalKey].apply(this, args);
            };
        }
    JS);

}

function readDialogWarnings(Browser $browser): array
{
    return $browser->script(<<<'JS'
        return (window.__codexDialogConsole || [])
            .filter((entry) => entry.message.includes('DialogContent') || entry.message.includes('aria-describedby'))
            .map((entry) => entry.message);
    JS)[0] ?? [];
}

test('operations shift show dialogs do not emit radix dialog accessibility warnings', function () {
    $user = User::where('email', 'admin@test.com')->firstOrFail();

    $shift = Shift::factory()
        ->inProgress()
        ->create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'started_by' => $user->id,
        ]);

    $this->browse(function (Browser $browser) use ($user, $shift) {
        $browser->loginAs($user)
            ->visit("/operations/shifts/{$shift->id}")
            ->waitForText('Complete shift', 10);

        installDialogWarningCapture($browser);

        $browser->press('Complete shift')
            ->waitForText('Checklist', 10)
            ->press('Cancel')
            ->waitUntilMissingText('Checklist', 10)
            ->press('Report incident')
            ->waitForText('Template (optional)', 10);

        $warnings = readDialogWarnings($browser);

        expect($warnings)->toBe([]);
    });
});

test('operations client show assessment delete dialog does not emit radix dialog accessibility warnings', function () {
    $user = User::where('email', 'admin@test.com')->firstOrFail();
    $client = Client::factory()->create();
    ClientAssessment::create([
        'client_id' => $client->id,
        'created_by_user_id' => $user->id,
        'type' => 'risk',
        'score' => '8',
        'notes' => 'Assessment note for dialog coverage.',
        'assessed_at' => now()->subDay(),
        'next_review_at' => now()->addWeek(),
    ]);

    $this->browse(function (Browser $browser) use ($user, $client) {
        $browser->loginAs($user)
            ->visit("/operations/clients/{$client->id}?tab=assessments")
            ->waitForText("{$client->first_name} {$client->last_name}", 10);

        installDialogWarningCapture($browser);

        $browser->script(<<<'JS'
            const button = Array.from(document.querySelectorAll('button')).find((element) =>
                element.className.includes('text-red-500')
            );

            if (!button) {
                throw new Error('Assessment delete button not found.');
            }

            button.scrollIntoView({ block: 'center' });
            button.click();
        JS);

        $browser->waitForText('Delete Assessment', 10);

        $warnings = readDialogWarnings($browser);

        expect($warnings)->toBe([]);
    });
});
