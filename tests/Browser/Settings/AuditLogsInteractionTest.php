<?php

use App\Models\AuditLog;
use App\Models\User;
use Laravel\Dusk\Browser;

function chooseAuditLogSelectOption(Browser $browser, int $index, string $optionText): void
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

test('settings audit logs filters use the live backend contract', function () {
    $admin = User::where('email', 'admin@test.com')->firstOrFail();
    $staff = User::where('email', 'staff@test.com')->firstOrFail();

    AuditLog::query()->delete();

    AuditLog::create([
        'user_id' => $admin->id,
        'action' => 'settings.security.updated',
        'auditable_type' => User::class,
        'auditable_id' => $staff->id,
        'meta' => [
            'old' => ['session_timeout_minutes' => 15],
            'attributes' => ['session_timeout_minutes' => 30],
        ],
        'ip_address' => '127.0.0.10',
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    AuditLog::create([
        'user_id' => $staff->id,
        'action' => 'finance.invoice.created',
        'auditable_type' => User::class,
        'auditable_id' => $admin->id,
        'meta' => [
            'attributes' => ['status' => 'draft'],
        ],
        'ip_address' => '127.0.0.11',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    $this->browse(function (Browser $browser) use ($admin, $staff) {
        $browser->loginAs($admin)
            ->visit('/settings/audit-logs')
            ->waitForText('Audit Logs', 10)
            ->assertSee('Settings Security Updated')
            ->assertSee('Finance Invoice Created')
            ->visit('/settings/audit-logs?search=finance')
            ->waitForText('Finance Invoice Created', 10)
            ->assertDontSee('Settings Security Updated')
            ->visit('/settings/audit-logs')
            ->waitForText('Audit Logs', 10);

        chooseAuditLogSelectOption($browser, 0, $staff->name);

        $browser->waitUsing(10, 250, function () use ($browser, $staff) {
            return str_contains($browser->driver->getCurrentURL(), 'user=' . $staff->id);
        })
            ->waitForText('Finance Invoice Created', 10)
            ->assertDontSee('Settings Security Updated')
            ->visit('/settings/audit-logs')
            ->waitForText('Audit Logs', 10);

        chooseAuditLogSelectOption($browser, 2, 'Updated');

        $browser->waitUsing(10, 250, function () use ($browser) {
            return str_contains($browser->driver->getCurrentURL(), 'action=updated');
        })
            ->waitForText('Settings Security Updated', 10)
            ->assertDontSee('Finance Invoice Created');

        $exportHref = $browser->script(
            "return document.querySelector('[dusk=\"audit-export-link\"]')?.getAttribute('href') ?? '';"
        )[0] ?? '';

        expect($exportHref)->toContain('/settings/audit-logs/export');
        expect($exportHref)->toContain('action=updated');
    });
});
