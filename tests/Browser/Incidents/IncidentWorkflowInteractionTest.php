<?php

use App\Models\ClientIncident;
use App\Models\User;
use Laravel\Dusk\Browser;

// NOTE: corrective actions are no longer edited on the incident — Option B moved
// them to the Health & Safety register (raised via the detail dialog). The old
// "manage corrective actions from the show page" Dusk test was retired with the
// inline UI. The remaining browser tests below drive flows that still exist; they
// need re-pointing at the modal-era UI when the Control Room/incident pages settle.

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
