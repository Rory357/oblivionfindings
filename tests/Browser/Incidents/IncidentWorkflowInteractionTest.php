<?php

use App\Models\ClientIncident;
use App\Models\User;
use Laravel\Dusk\Browser;

test('incident corrective actions can be managed from the show page', function () {
    $user = User::where('email', 'admin@test.com')->firstOrFail();
    $incident = ClientIncident::factory()->create([
        'reported_by' => $user->id,
        'status' => 'draft',
        'type' => 'injury',
        'severity' => 'medium',
        'description' => 'QA incident for corrective action coverage.',
        'corrective_actions' => [],
    ]);

        $this->browse(function (Browser $browser) use ($user, $incident) {
            $browser->loginAs($user)
                ->visit("/incidents/{$incident->id}")
                ->waitForText("Incident #{$incident->id}", 10)
                ->waitForText('Corrective actions', 10)
                ->assertSee('No corrective actions.');

            $browser->script(<<<'JS'
                const input = document.querySelector('input[placeholder="Action required..."]');

                if (!input) {
                    throw new Error('Corrective action input not found.');
                }

                input.scrollIntoView({ block: 'center' });
            JS);

            $browser->pause(250)
                ->type('input[placeholder="Action required..."]', 'Confirm equipment checks completed')
                ->pause(250)
                ->press('Add action')
                ->waitForText('Confirm equipment checks completed', 10)
                ->assertDontSee('No corrective actions.')
                ->press('Complete')
                ->waitForText('Completed:', 10);
        });
});

test('incident draft can be submitted and reviewed from the show page', function () {
    $user = User::where('email', 'admin@test.com')->firstOrFail();
    $incident = ClientIncident::factory()->create([
        'reported_by' => $user->id,
        'status' => 'draft',
        'type' => 'injury',
        'severity' => 'medium',
        'description' => 'QA incident for workflow coverage.',
    ]);

        $this->browse(function (Browser $browser) use ($user, $incident) {
            $browser->loginAs($user)
                ->visit("/incidents/{$incident->id}")
                ->waitForText("Incident #{$incident->id}", 10)
                ->press('Submit')
                ->waitForText('Mark reviewed', 10)
                ->press('Mark reviewed')
                ->waitForText('Close incident', 10);
        });
});

test('incident templates can be created from the browser', function () {
    $user = User::where('email', 'admin@test.com')->firstOrFail();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/incidents/templates/create')
            ->waitForText('New template', 10)
            ->type('input', 'QA browser template')
            ->press('Save')
            ->waitForText('Edit template', 10)
            ->waitForText('QA browser template', 10);
    });
});

test('incident report page loads in the browser', function () {
    $user = User::where('email', 'admin@test.com')->firstOrFail();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/reports/incidents')
            ->waitForText('Incident reports', 10)
            ->waitForText('Download CSV', 10);
    });
});
