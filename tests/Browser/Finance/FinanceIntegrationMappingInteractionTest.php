<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinAccountingIntegration;
use App\Models\User;
use Laravel\Dusk\Browser;

test('finance integration mapping can be updated through the browser', function () {
    $user = User::where('email', 'admin@test.com')->firstOrFail();

    $account = FinAccount::factory()->create([
        'organization_id' => $user->organization_id,
        'type' => 'revenue',
        'sub_type' => 'service_income',
        'code' => 'QAMAP' . now()->format('is'),
        'name' => 'QA Mapping Revenue',
        'created_by' => $user->id,
    ]);

    $integration = FinAccountingIntegration::query()->create([
        'organization_id' => $user->organization_id,
        'provider' => 'xero',
        'tenant_id' => 'tenant-' . now()->format('His'),
        'sync_direction' => 'bidirectional',
        'account_mapping' => [],
        'tax_mapping' => [],
        'settings' => [],
        'is_active' => true,
        'created_by' => $user->id,
    ]);

    $externalId = 'xero-' . now()->format('His');

    $this->browse(function (Browser $browser) use ($user, $integration, $account, $externalId) {
        $encodedAccountCode = json_encode($account->code, JSON_THROW_ON_ERROR);
        $encodedExternalId = json_encode($externalId, JSON_THROW_ON_ERROR);

        $browser->loginAs($user)
            ->visit("/finance/integrations/{$integration->id}/mapping")
            ->waitForText('Account Mapping', 10);

        $browser->script(<<<JS
            const accountCode = {$encodedAccountCode};
            const externalId = {$encodedExternalId};
            const row = Array.from(document.querySelectorAll('tbody tr')).find((element) =>
                element.textContent?.includes(accountCode)
            );

            if (!row) {
                throw new Error('Could not find mapping row for account ' + accountCode);
            }

            const input = row.querySelector('input');

            if (!input) {
                throw new Error('Could not find mapping input for account ' + accountCode);
            }

            input.scrollIntoView({ block: 'center' });
            input.value = externalId;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        JS);

        $browser->press('Save Mapping')
            ->pause(500);
    });

    $integration->refresh();

    expect($integration->account_mapping[(string) $account->id] ?? null)->toBe($externalId);
});
