import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAs,
    loginAsStaff,
    runLaravelPhp,
    seedGovernancePrivacyConsentsReadinessFixtures,
} from './helpers';

function idFromPath(pathname: string, resource: string): number {
    const match = pathname.match(new RegExp(`/${resource}/(\\d+)`));
    expect(match).not.toBeNull();

    return Number(match?.[1]);
}

function auditLogCount(model: string, id: number): number {
    const output = runLaravelPhp(
        `echo \\App\\Models\\AuditLog::where('auditable_type', '${model}')->where('auditable_id', ${id})->count();`,
    );

    return Number(output.trim());
}

function dsrExportPath(id: number): string {
    return runLaravelPhp(
        `echo \\App\\Models\\DataSubjectRequest::findOrFail(${id})->export_path ?? '';`,
    ).trim();
}

test.describe('privacy DSR and breach readiness', () => {
    test.setTimeout(90_000);

    test('DSR and breach lifecycles work and privacy dashboard denies coordinators', async ({
        page,
    }) => {
        seedGovernancePrivacyConsentsReadinessFixtures();
        const consoleErrors = collectConsoleErrors(page);
        const unique = Date.now();

        await loginAsStaff(page);

        await page.goto('/privacy/requests/create');
        await expect(page.getByTestId('privacy-dsr-create-form')).toBeVisible();
        await page.getByTestId('privacy-dsr-request-type-select').click();
        await page.getByRole('option', { name: /Right to Access/i }).click();
        await page
            .getByTestId('privacy-dsr-subject-name')
            .fill('Playwright Privacy Subject');
        await page
            .getByTestId('privacy-dsr-subject-email')
            .fill(`privacy-readiness+${unique}@example.test`);
        await page
            .getByTestId('privacy-dsr-details')
            .fill(
                'Please provide all personal data held for readiness testing.',
            );
        await page.getByTestId('privacy-dsr-submit').click();

        await expect(page).toHaveURL(/\/privacy\/requests\/\d+$/);
        await expect(page.getByTestId('privacy-dsr-show')).toBeVisible();
        await expect(page.getByTestId('privacy-dsr-status')).toContainText(
            'pending verification',
        );

        const dsrId = idFromPath(new URL(page.url()).pathname, 'requests');
        page.once('dialog', (dialog) =>
            dialog.accept('Photo ID checked by privacy lead.'),
        );
        await page.getByTestId('privacy-dsr-verify').click();
        await expect(page.getByTestId('privacy-dsr-status')).toContainText(
            'in progress',
        );

        const exportResponse = page.waitForResponse(
            (response) =>
                response.url().includes(`/privacy/requests/${dsrId}/export`) &&
                response.status() < 400,
        );
        await page.getByTestId('privacy-dsr-export').click();
        await exportResponse;
        await expect(page).toHaveURL(new RegExp(`/privacy/requests/${dsrId}$`));
        await expect
            .poll(() => dsrExportPath(dsrId))
            .toContain('private/dsr-exports/');

        page.once('dialog', (dialog) =>
            dialog.accept('Export generated and response sent.'),
        );
        await page.getByTestId('privacy-dsr-complete').click();
        await expect(page.getByTestId('privacy-dsr-status')).toContainText(
            'completed',
        );
        expect(
            auditLogCount('App\\\\Models\\\\DataSubjectRequest', dsrId),
        ).toBeGreaterThanOrEqual(3);

        await page.goto('/privacy/breaches/create');
        await expect(
            page.getByTestId('privacy-breach-create-form'),
        ).toBeVisible();
        await page
            .getByTestId('privacy-breach-nature')
            .fill(
                `Playwright privacy lifecycle breach ${unique}: exposed report link.`,
            );
        await page.getByTestId('privacy-breach-affected-count').fill('3');
        await page
            .getByTestId('privacy-breach-consequences')
            .fill('Possible access to contact details.');
        await page
            .getByTestId('privacy-breach-measures')
            .fill('Link revoked and access logs reviewed.');
        await page.getByTestId('privacy-breach-requires-authority').click();
        await page.getByTestId('privacy-breach-requires-subjects').click();
        await page.getByTestId('privacy-breach-submit').click();

        await expect(page).toHaveURL(/\/privacy\/breaches\/\d+$/);
        await expect(page.getByTestId('privacy-breach-show')).toBeVisible();
        await expect(page.getByTestId('privacy-breach-status')).toContainText(
            'discovered',
        );
        await expect(
            page.getByTestId('privacy-breach-ico-countdown'),
        ).toBeVisible();

        const breachId = idFromPath(new URL(page.url()).pathname, 'breaches');
        page.once('dialog', (dialog) => dialog.accept('ICO-PW-001'));
        await page.getByTestId('privacy-breach-notify-ico').click();
        await expect(page.getByTestId('privacy-breach-status')).toContainText(
            'notified',
        );
        await expect(page.getByText('ICO Notified').first()).toBeVisible();

        page.once('dialog', (dialog) => dialog.accept('Email and phone'));
        await page.getByTestId('privacy-breach-notify-subjects').click();
        await expect(page.getByText('Subjects Notified').first()).toBeVisible();

        page.once('dialog', (dialog) =>
            dialog.accept('Root cause fixed and monitoring added.'),
        );
        await page.getByTestId('privacy-breach-resolve').click();
        await expect(page.getByTestId('privacy-breach-status')).toContainText(
            'resolved',
        );
        expect(
            auditLogCount('App\\\\Models\\\\DataBreachLog', breachId),
        ).toBeGreaterThanOrEqual(4);

        expectNoConsoleErrors(consoleErrors);

        await page.context().clearCookies();
        await loginAs(page, 'coord@demo.test', 'password');
        const response = await page.goto('/privacy/dashboard');
        expect(response?.status()).toBe(403);
    });
});
