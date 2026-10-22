import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAs,
    loginAsStaff,
    runLaravelPhp,
    seedGovernancePrivacyConsentsReadinessFixtures,
} from './helpers';

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
    test.setTimeout(180_000);

    test('DSR and breach lifecycles work and privacy dashboard denies coordinators', async ({
        page,
    }) => {
        seedGovernancePrivacyConsentsReadinessFixtures();
        const consoleErrors = collectConsoleErrors(page);
        const unique = Date.now();
        const dsrEmail = 'privacy-readiness+' + unique + '@example.test';
        const breachNature =
            'Playwright privacy lifecycle breach ' +
            unique +
            ': exposed report link.';

        await loginAsStaff(page);

        await page.goto('/privacy/requests/create');
        await expect(page).toHaveURL(/\/privacy\/dashboard(?:\?|$)/);
        const requestWizard = page.getByRole('dialog', {
            name: 'New privacy request',
        });
        await expect(requestWizard).toBeVisible();
        await requestWizard
            .getByRole('button', { name: /Access · IPP 6/i })
            .click();
        await requestWizard.getByRole('button', { name: 'Continue' }).click();
        await requestWizard
            .getByLabel('Subject name')
            .fill('Playwright Privacy Subject');
        await requestWizard.getByLabel('Subject email').fill(dsrEmail);
        await requestWizard.getByRole('button', { name: 'Continue' }).click();
        await requestWizard
            .getByLabel('Request details')
            .fill(
                'Please provide all personal data held for readiness testing.',
            );
        await requestWizard.getByRole('button', { name: 'Continue' }).click();
        await requestWizard
            .getByRole('button', { name: 'Create request' })
            .click();
        await expect(requestWizard.getByText('Request logged!')).toBeVisible();

        const dsrId = Number(
            runLaravelPhp(
                "echo \\App\\Models\\DataSubjectRequest::query()->where('subject_email', " +
                    JSON.stringify(dsrEmail) +
                    ")->value('id');",
            ).trim(),
        );
        expect(dsrId).toBeGreaterThan(0);
        await page.goto('/privacy/requests/' + dsrId);
        await expect(page.getByTestId('privacy-dsr-show')).toBeVisible();
        await expect(page.getByTestId('privacy-dsr-status')).toContainText(
            'pending verification',
        );

        await page.getByTestId('privacy-dsr-verify').click();
        const verifyDialog = page.getByRole('dialog', {
            name: "Verify Playwright Privacy Subject's identity?",
        });
        await verifyDialog
            .getByLabel('How you checked their identity')
            .fill('Photo ID checked by privacy lead.');
        await verifyDialog
            .getByRole('button', { name: 'Verify identity' })
            .click();
        await expect(verifyDialog).toBeHidden();
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
            .toContain('private/privacy-request-exports/');

        await page.getByTestId('privacy-dsr-complete').click();
        const completeDialog = page.getByRole('dialog', {
            name: 'Mark this request as complete?',
        });
        await completeDialog
            .getByLabel('Completion notes')
            .fill('Export generated and response sent.');
        await completeDialog
            .getByRole('button', { name: 'Mark complete' })
            .click();
        await expect(completeDialog).toBeHidden();
        await expect(page.getByTestId('privacy-dsr-status')).toContainText(
            'completed',
        );
        expect(
            auditLogCount('App\\\\Models\\\\DataSubjectRequest', dsrId),
        ).toBeGreaterThanOrEqual(3);

        await page.goto('/privacy/breaches/create');
        await expect(page).toHaveURL(/\/privacy\/dashboard(?:\?|$)/);
        const breachWizard = page.getByRole('dialog', {
            name: 'Log data breach',
        });
        await expect(breachWizard).toBeVisible();
        await breachWizard.getByLabel('Nature of breach').fill(breachNature);
        await breachWizard.getByRole('button', { name: 'Continue' }).click();
        await breachWizard.getByLabel('Approx. individuals affected').fill('3');
        await breachWizard
            .getByLabel('Likely consequences')
            .fill('Possible access to contact details.');
        await breachWizard.getByRole('button', { name: 'Continue' }).click();
        await breachWizard
            .getByLabel('Measures taken')
            .fill('Link revoked and access logs reviewed.');
        await breachWizard.getByRole('switch').nth(0).click();
        await breachWizard.getByRole('switch').nth(1).click();
        await breachWizard.getByRole('button', { name: 'Continue' }).click();
        await breachWizard.getByRole('button', { name: 'Log breach' }).click();
        await expect(breachWizard.getByText('Breach logged!')).toBeVisible();

        const breachId = Number(
            runLaravelPhp(
                "echo \\App\\Models\\DataBreachLog::query()->where('nature_of_breach', " +
                    JSON.stringify(breachNature) +
                    ")->value('id');",
            ).trim(),
        );
        expect(breachId).toBeGreaterThan(0);
        await page.goto('/privacy/breaches/' + breachId);
        await expect(page.getByTestId('privacy-breach-show')).toBeVisible();
        await expect(page.getByTestId('privacy-breach-status')).toContainText(
            'discovered',
        );
        await expect(
            page.getByText('OPC notification pending', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByTestId('privacy-breach-notify-opc'),
        ).toBeVisible();

        await page.getByTestId('privacy-breach-notify-opc').click();
        const opcDialog = page.getByRole('dialog', {
            name: "Record that you've notified the Privacy Commissioner?",
        });
        await opcDialog
            .getByLabel("Privacy Commissioner's reference")
            .fill('OPC-PW-001');
        await opcDialog
            .getByRole('button', { name: 'Record notification' })
            .click();
        await expect(opcDialog).toBeHidden();
        await expect(page.getByTestId('privacy-breach-status')).toContainText(
            'notified',
        );
        await expect(page.getByText('OPC Notified').first()).toBeVisible();

        await page.getByTestId('privacy-breach-notify-subjects').click();
        const subjectsDialog = page.getByRole('dialog', {
            name: 'Record that the affected people were told?',
        });
        await subjectsDialog
            .getByLabel('How you told them')
            .fill('Email and phone');
        await subjectsDialog
            .getByRole('button', { name: 'Record notification' })
            .click();
        await expect(subjectsDialog).toBeHidden();
        await expect(page.getByText('Subjects Notified').first()).toBeVisible();

        await page.getByTestId('privacy-breach-resolve').click();
        const resolveDialog = page.getByRole('dialog', {
            name: 'Mark this breach as resolved?',
        });
        await resolveDialog
            .getByLabel('Resolution notes')
            .fill('Root cause fixed and monitoring added.');
        await resolveDialog
            .getByRole('button', { name: 'Mark resolved' })
            .click();
        await expect(resolveDialog).toBeHidden();
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
