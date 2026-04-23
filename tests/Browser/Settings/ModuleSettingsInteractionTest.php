<?php

use App\Models\AppSetting;
use App\Models\User;
use Laravel\Dusk\Browser;

test('settings modules page persists module and beta feature toggles', function () {
    $admin = User::where('email', 'admin@test.com')->firstOrFail();

    AppSetting::query()
        ->whereIn('key', [
            'settings.modules.module_states',
            'settings.modules.beta_feature_states',
        ])
        ->delete();

    $this->browse(function (Browser $browser) use ($admin) {
        $clickSelector = static function (string $selector): string {
            return str_replace('__SELECTOR__', json_encode($selector, JSON_THROW_ON_ERROR), <<<'JS'
                const selector = __SELECTOR__;
                const element = document.querySelector(selector);

                if (!element) {
                    throw new Error(`Element not found: ${selector}`);
                }

                element.scrollIntoView({ block: 'center' });
                element.click();
            JS);
        };

        $browser->loginAs($admin)
            ->visit('/settings/modules')
            ->waitForText('Active Modules', 10);

        $browser->script($clickSelector('[dusk="module-switch-emar"]'));
        $browser->script($clickSelector('[dusk="beta-switch-ai-docs"]'));
        $browser->script($clickSelector('[dusk="modules-save"]'));

        $browser->waitUsing(10, 250, function () {
            $moduleStates = AppSetting::query()->where('key', 'settings.modules.module_states')->value('value');
            $betaStates = AppSetting::query()->where('key', 'settings.modules.beta_feature_states')->value('value');

            return ($moduleStates['emar'] ?? false) && ($betaStates['ai-docs'] ?? false);
        });

        $browser->refresh()
            ->waitForText('Active Modules', 10);

        $emarEnabled = $browser->script(
            "return document.querySelector('[dusk=\"module-switch-emar\"]')?.getAttribute('data-state') === 'checked';"
        )[0] ?? false;
        $aiDocsEnabled = $browser->script(
            "return document.querySelector('[dusk=\"beta-switch-ai-docs\"]')?.getAttribute('data-state') === 'checked';"
        )[0] ?? false;

        expect($emarEnabled)->toBeTrue();
        expect($aiDocsEnabled)->toBeTrue();
    });

    $moduleStates = AppSetting::query()->where('key', 'settings.modules.module_states')->value('value');
    $betaStates = AppSetting::query()->where('key', 'settings.modules.beta_feature_states')->value('value');

    expect($moduleStates['emar'] ?? false)->toBeTrue();
    expect($betaStates['ai-docs'] ?? false)->toBeTrue();
});
