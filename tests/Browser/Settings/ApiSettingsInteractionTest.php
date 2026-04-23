<?php

use App\Models\AppSetting;
use App\Models\User;
use Laravel\Dusk\Browser;

function clickApiSelector(Browser $browser, string $selector): void
{
    $browser->script(str_replace('__SELECTOR__', json_encode($selector, JSON_THROW_ON_ERROR), <<<'JS'
        const selector = __SELECTOR__;
        const element = document.querySelector(selector);

        if (!element) {
            throw new Error(`Element not found: ${selector}`);
        }

        element.scrollIntoView({ block: 'center' });
        element.click();
    JS));
}

test('api settings persist generated keys and revoked status', function () {
    $admin = User::where('email', 'admin@test.com')->firstOrFail();
    $keyName = 'QA API ' . now()->format('His');
    $createdKeyId = null;

    AppSetting::query()->where('key', 'settings.api.keys')->delete();

    $this->browse(function (Browser $browser) use ($admin, $keyName, &$createdKeyId) {
        $browser->loginAs($admin)
            ->visit('/settings/api')
            ->waitForText('API Keys', 10)
            ->click('[dusk="api-generate-open"]')
            ->waitForText('Generate New API Key', 10)
            ->type('[dusk="api-key-name"]', $keyName);

        clickApiSelector($browser, '[dusk="api-scope-read-clients"]');
        clickApiSelector($browser, '[dusk="api-scope-reports"]');

        $browser->click('[dusk="api-key-generate"]')
            ->waitForText('Copy this key now', 10)
            ->click('[dusk="api-key-done"]')
            ->waitForText($keyName, 10)
            ->waitUsing(10, 250, function () use (&$createdKeyId) {
                $keys = AppSetting::query()->where('key', 'settings.api.keys')->value('value');
                $createdKeyId = is_array($keys) && isset($keys[0]['id']) ? $keys[0]['id'] : null;

                return $createdKeyId !== null;
            });

        $browser->click(sprintf('[dusk="api-key-revoke-%s"]', $createdKeyId))
            ->waitUsing(10, 250, function () use (&$createdKeyId) {
                $keys = AppSetting::query()->where('key', 'settings.api.keys')->value('value');
                $record = collect(is_array($keys) ? $keys : [])->firstWhere('id', $createdKeyId);

                return is_array($record) && ($record['status'] ?? null) === 'revoked';
            })
            ->refresh()
            ->waitForText('API Keys', 10)
            ->assertSeeIn(sprintf('[dusk="api-key-row-%s"]', $createdKeyId), $keyName)
            ->assertSeeIn(sprintf('[dusk="api-key-row-%s"]', $createdKeyId), 'revoked');
    });
});

test('api settings persist webhooks and record a real test delivery', function () {
    $admin = User::where('email', 'admin@test.com')->firstOrFail();
    $webhookUrl = rtrim((string) config('app.url'), '/') . '/';
    $createdWebhookId = null;

    AppSetting::query()->where('key', 'settings.api.webhooks')->delete();

    $this->browse(function (Browser $browser) use ($admin, $webhookUrl, &$createdWebhookId) {
        $browser->loginAs($admin)
            ->visit('/settings/api')
            ->waitForText('Webhooks', 10)
            ->click('[dusk="api-webhook-open"]')
            ->waitForText('Add Webhook', 10)
            ->type('[dusk="api-webhook-url"]', $webhookUrl);

        clickApiSelector($browser, '[dusk="api-event-shift-completed"]');

        $browser->click('[dusk="api-webhook-add"]')
            ->waitForText('Copy this signing secret now', 10)
            ->click('[dusk="api-webhook-done"]')
            ->waitForText($webhookUrl, 10)
            ->waitUsing(10, 250, function () use (&$createdWebhookId) {
                $webhooks = AppSetting::query()->where('key', 'settings.api.webhooks')->value('value');
                $createdWebhookId = is_array($webhooks) && isset($webhooks[0]['id']) ? $webhooks[0]['id'] : null;

                return $createdWebhookId !== null;
            });

        $browser->click(sprintf('[dusk="api-webhook-test-%s"]', $createdWebhookId))
            ->waitForText('Webhook test succeeded.', 10)
            ->waitUsing(10, 250, function () use (&$createdWebhookId) {
                $webhooks = AppSetting::query()->where('key', 'settings.api.webhooks')->value('value');
                $record = collect(is_array($webhooks) ? $webhooks : [])->firstWhere('id', $createdWebhookId);

                return is_array($record) && ! empty($record['last_delivery']);
            })
            ->refresh()
            ->waitForText('Webhooks', 10)
            ->assertSeeIn(sprintf('[dusk="api-webhook-row-%s"]', $createdWebhookId), $webhookUrl)
            ->assertDontSeeIn(sprintf('[dusk="api-webhook-row-%s"]', $createdWebhookId), 'Never');
    });
});

test('api settings page is forbidden without integrations view permission', function () {
    $staff = User::where('email', 'staff@test.com')->firstOrFail();

    $this->browse(function (Browser $browser) use ($staff) {
        $browser->loginAs($staff)
            ->visit('/settings/api')
            ->waitForText('403', 10)
            ->assertDontSee('API Keys');
    });
});
