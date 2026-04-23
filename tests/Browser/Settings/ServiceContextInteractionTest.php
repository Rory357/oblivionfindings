<?php

use App\Models\ServiceContext;
use App\Models\User;
use Laravel\Dusk\Browser;

test('service contexts can be created and set as default through the browser', function () {
    $user = User::where('email', 'admin@test.com')->firstOrFail();
    $name = 'QA Context ' . now()->format('His');

    $this->browse(function (Browser $browser) use ($user, $name) {
        $browser->loginAs($user)
            ->visit('/settings/service-contexts')
            ->waitForText('Service contexts', 10)
            ->press('New Context')
            ->waitForText('Create service context', 10)
            ->type('input[placeholder="e.g. North Shore Residential"]', $name)
            ->type('textarea[placeholder="What kind of service is delivered in this context?"]', 'Browser-created service context for QA coverage.')
            ->press('Create Context')
            ->waitForText($name, 10);

        $context = ServiceContext::query()->where('name', $name)->latest('id')->firstOrFail();

        $browser->select('select', (string) $context->id)
            ->press('Save')
            ->waitForText($name, 10);

        expect(ServiceContext::defaultId())->toBe($context->id);
    });
});
