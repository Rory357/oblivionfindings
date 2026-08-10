import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    runLaravelPhp,
} from './helpers';

function seedSiteOverviewContactsFixture(): {
    siteId: number;
    responsibleName: string;
} {
    const output = runLaravelPhp(`
$responsible = \\App\\Models\\User::query()->where('email', 'admin@demo.test')->firstOrFail();
$site = \\App\\Models\\Site::query()->updateOrCreate(
    ['name' => 'Playwright Overview Contacts House'],
    [
        'type' => 'house',
        'address_line_1' => '24 Contact Lane',
        'suburb' => 'Mount Eden',
        'city' => 'Auckland',
        'region' => 'Auckland',
        'postcode' => '1024',
        'country' => 'New Zealand',
        'phone' => '09 555 0240',
        'email' => 'overview-contacts@example.test',
        'primary_contact_user_id' => $responsible->id,
        'emergency_plan_location' => 'Kitchen folder',
        'medication_storage_location' => 'Medication cabinet',
        'is_active' => true,
    ],
);

\\App\\Models\\SiteContact::query()->where('site_id', $site->id)->delete();

\\App\\Models\\SiteContact::query()->create([
    'site_id' => $site->id,
    'type' => 'site_lead',
    'name' => 'Taylor Site Lead',
    'role' => 'Site Lead',
    'phone' => '021 555 0240',
    'is_primary' => true,
]);

\\App\\Models\\SiteContact::query()->create([
    'site_id' => $site->id,
    'type' => 'team_lead',
    'name' => 'Riley Team Lead',
    'role' => 'Team Lead',
    'phone' => '021 555 0241',
    'is_primary' => false,
]);

echo json_encode([
    'siteId' => $site->id,
    'responsibleName' => $responsible->name,
]);
`);

    return JSON.parse(output) as {
        siteId: number;
        responsibleName: string;
    };
}

test('site overview contact card uses site contacts and locked role creation', async ({
    page,
}) => {
    const { siteId, responsibleName } = seedSiteOverviewContactsFixture();
    const consoleErrors = collectConsoleErrors(page);

    await loginAsStaff(page);
    await page.goto(`/sites/${siteId}`, { waitUntil: 'domcontentloaded' });
    await page.getByRole('tab', { name: /^Overview/ }).click();

    const card = page.getByTestId('site-contact-information-card');
    await expect(card).toBeVisible();

    await expect(card.getByTestId('site-contact-row-phone')).toContainText(
        '09 555 0240',
    );
    await expect(card.getByTestId('site-contact-row-email')).toContainText(
        'overview-contacts@example.test',
    );
    await expect(
        card.getByTestId('site-contact-row-responsible-staff'),
    ).toContainText(responsibleName);
    await expect(card.getByTestId('site-contact-row-site-lead')).toContainText(
        'Taylor Site Lead',
    );
    await expect(
        card.getByTestId('site-contact-row-site-lead'),
    ).not.toContainText('Riley Team Lead');
    await expect(card.getByTestId('site-contact-row-manager')).toContainText(
        'Not set',
    );

    await card.getByRole('button', { name: /add manager/i }).click();
    const addDialog = page.getByRole('dialog');
    await expect(addDialog).toContainText('Manager');
    await expect(addDialog).toContainText('From Overview');
    await expect(addDialog.getByText('Team Lead')).toHaveCount(0);

    await addDialog.getByLabel('Name').fill('Morgan Manager');
    await addDialog.getByLabel('Phone').fill('021 555 0242');
    await addDialog.getByRole('button', { name: 'Save contact' }).click();

    await expect(addDialog).toHaveCount(0);
    await expect(card.getByTestId('site-contact-row-manager')).toContainText(
        'Morgan Manager',
    );
    await expect(card.getByTestId('site-contact-row-manager')).toContainText(
        '021 555 0242',
    );

    await card.getByRole('button', { name: /edit phone & email/i }).click();
    const siteLineDialog = page.getByRole('dialog');
    await expect(siteLineDialog).toContainText('Edit phone & email');
    await expect(siteLineDialog.getByLabel('Phone')).toBeVisible();
    await expect(siteLineDialog.getByLabel('Email')).toBeVisible();
    await expect(siteLineDialog.getByText('Manager phone')).toHaveCount(0);

    expectNoConsoleErrors(consoleErrors);
});
