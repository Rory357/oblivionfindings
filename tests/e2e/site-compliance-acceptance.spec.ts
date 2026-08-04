import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    runLaravelPhp,
} from './helpers';

function seedSiteComplianceFixture(): { siteId: number; siteName: string } {
    const output = runLaravelPhp(`
\\Illuminate\\Support\\Facades\\Artisan::call('db:seed', [
    '--class' => 'RbacSeeder',
    '--force' => true,
]);

$admin = \\App\\Models\\User::query()->updateOrCreate(
    ['email' => 'admin@demo.test'],
    [
        'name' => 'Playwright Site Compliance Admin',
        'password' => \\Illuminate\\Support\\Facades\\Hash::make('password'),
        'email_verified_at' => now(),
        'approved_at' => now(),
    ],
);
$admin->roles()->sync([
    \\App\\Models\\Role::query()->where('name', 'admin')->firstOrFail()->id,
]);

$site = \\App\\Models\\Site::query()->updateOrCreate(
    ['name' => 'Playwright Site Compliance House'],
    [
        'type' => 'house',
        'address_line_1' => '13 Assurance Lane',
        'city' => 'Auckland',
        'country' => 'New Zealand',
        'is_active' => true,
    ],
);

\\App\\Models\\SiteCertification::query()->where('site_id', $site->id)->delete();
\\App\\Models\\SiteComplianceCheck::query()->where('site_id', $site->id)->delete();
\\App\\Models\\SiteFeedback::query()->where('site_id', $site->id)->delete();

echo json_encode(['siteId' => $site->id, 'siteName' => $site->name]);
`);

    return JSON.parse(output) as { siteId: number; siteName: string };
}

test('Site Compliance and feedback expose usable desktop management dialogs', async ({
    page,
}) => {
    const { siteId, siteName } = seedSiteComplianceFixture();
    const consoleErrors = collectConsoleErrors(page);

    await loginAsStaff(page);
    await page.goto(`/sites/${siteId}/compliance`, {
        waitUntil: 'domcontentloaded',
    });

    await expect(
        page.getByRole('heading', { name: `${siteName} — Compliance` }),
    ).toBeVisible();
    await expect(page.getByText('No certifications found.')).toBeVisible();
    await expect(page.getByText('No compliance checks found.')).toBeVisible();

    const addCertification = page.getByRole('button', {
        name: 'Add Certification',
        exact: true,
    });
    await expect(addCertification).toHaveCount(1);
    await addCertification.click();

    const certificationDialog = page.getByRole('dialog');
    await expect(certificationDialog).toContainText('Add Certification');
    await expect(certificationDialog).toContainText('Status');
    await expect(certificationDialog).toContainText('Next Review');
    await certificationDialog
        .getByRole('button', { name: 'Cancel', exact: true })
        .click();
    await expect(certificationDialog).toHaveCount(0);

    const scheduleCheck = page.getByRole('button', {
        name: 'Schedule Check',
        exact: true,
    });
    await expect(scheduleCheck).toHaveCount(1);
    await scheduleCheck.click();

    const checkDialog = page.getByRole('dialog');
    await expect(checkDialog).toContainText('Schedule Compliance Check');
    await expect(checkDialog).toContainText('Check Type');
    await expect(checkDialog).toContainText('Scheduled Date');
    await checkDialog
        .getByRole('button', { name: 'Cancel', exact: true })
        .click();
    await expect(checkDialog).toHaveCount(0);

    await expect(
        page.evaluate(
            () =>
                document.documentElement.scrollWidth <=
                document.documentElement.clientWidth,
        ),
    ).resolves.toBe(true);

    await page.goto(`/sites/${siteId}/feedback`, {
        waitUntil: 'domcontentloaded',
    });
    await expect(
        page.getByRole('heading', {
            name: `${siteName} — Quality & Feedback`,
        }),
    ).toBeVisible();

    const submitFeedback = page.getByRole('button', {
        name: 'Submit Feedback',
        exact: true,
    });
    await expect(submitFeedback).toHaveCount(1);
    await submitFeedback.click();

    const feedbackDialog = page.getByRole('dialog');
    await expect(feedbackDialog).toContainText('Submit Feedback');
    await expect(feedbackDialog).toContainText('Submit Anonymously');
    await expect(feedbackDialog).toContainText('Category');

    await expect(
        page.evaluate(
            () =>
                document.documentElement.scrollWidth <=
                document.documentElement.clientWidth,
        ),
    ).resolves.toBe(true);
    expectNoConsoleErrors(consoleErrors);
});
