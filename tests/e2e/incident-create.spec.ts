import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

import { collectConsoleErrors, loginAsStaff } from './helpers';

type DraftPayload = {
    data: Record<string, string>;
    meta: { step: number; incidentId: number | null };
    savedAt: number;
};

function expectNoUnexpectedConsoleErrors(errors: string[]) {
    expect(
        errors.filter(
            (error) =>
                !error.includes('net::ERR_INTERNET_DISCONNECTED') &&
                error !== 'Network Error',
        ),
    ).toEqual([]);
}

async function expectNoBlockingAxeViolations(page: Page) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();

    const blocking = results.violations.filter((violation) =>
        ['serious', 'critical'].includes(violation.impact ?? ''),
    );

    expect(
        blocking,
        `axe found blocking violations:\n${blocking
            .map(
                (v) =>
                    `  - [${v.impact}] ${v.id}: ${v.help} (${v.nodes.length} nodes)`,
            )
            .join('\n')}`,
    ).toEqual([]);
}

async function selectFirstClient(page: Page) {
    await page.getByTestId('incident-client-select').click();
    await expect(page.getByRole('option').nth(1)).toBeVisible();
    await page.getByRole('option').nth(1).click();
}

async function getIncidentDraftKey(page: Page) {
    return page.evaluate(() => {
        const root = document.getElementById('app');
        const pagePayload = root?.getAttribute('data-page');
        const parsed = pagePayload ? JSON.parse(pagePayload) : {};
        const userId = parsed?.props?.auth?.user?.id ?? 0;

        return `oblivion:incident-draft:v1:u${userId}`;
    });
}

async function seedLocalIncidentDraft(page: Page, key: string) {
    const payload: DraftPayload = {
        data: {
            client_id: '',
            type: 'injury',
            severity: 'low',
            occurred_at: '',
            description: 'Saved local draft from Playwright.',
            immediate_action_taken: '',
            witnesses: '',
            injured_person_name: '',
            injured_person_role: '',
            injury_body_part: '',
            injury_nature: '',
            medical_treatment_type: '',
        },
        meta: { step: 1, incidentId: null },
        savedAt: Date.now(),
    };

    await page.evaluate(
        ({ draftKey, draft }) => {
            window.localStorage.setItem(draftKey, JSON.stringify(draft));
        },
        { draftKey: key, draft: payload },
    );
}

test.describe('incident wizard', () => {
    test('desktop: full happy path', async ({ page }, testInfo) => {
        test.skip(
            !testInfo.project.name.includes('desktop'),
            'Desktop layout smoke runs only on the desktop project.',
        );

        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/incidents/create');

        await expect(page.getByTestId('incident-wizard-root')).toBeVisible();
        await expect(page.getByTestId('incident-wizard-summary')).toBeVisible();
        await expectNoBlockingAxeViolations(page);

        await selectFirstClient(page);
        await page.getByTestId('incident-type-injury').click();
        await page.getByTestId('incident-severity-medium').click();
        await page.getByTestId('incident-wizard-next').click();

        await expect(page.getByTestId('incident-wizard-step-1')).toBeVisible();
        await page
            .getByTestId('incident-description')
            .fill('Client slipped in hallway, no injury.');
        await page.getByTestId('incident-wizard-next').click();

        await expect(page).toHaveURL(/\/incidents\/create\?incident=\d+/);
        await expect(page.getByTestId('incident-wizard-step-2')).toBeVisible();

        await page
            .getByTestId('incident-immediate-action')
            .fill('Helped client up; observation only.');
        await page.getByTestId('incident-wizard-finish').click();

        await expect(page).toHaveURL(/\/incidents\/\d+$/);
        await expect(
            page.getByRole('heading', { name: /Incident #\d+/ }),
        ).toBeVisible();
        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('mobile: sticky action bar persists', async ({ page }, testInfo) => {
        test.skip(
            !testInfo.project.name.includes('mobile'),
            'Mobile sticky-bar smoke runs only on the mobile project.',
        );

        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/incidents/create');

        await expect(page.getByTestId('incident-wizard-root')).toBeVisible();
        await expect(page.getByTestId('incident-wizard-summary')).toBeHidden();
        await expect(page.getByTestId('incident-wizard-actions')).toHaveClass(
            /fixed/,
        );

        await selectFirstClient(page);
        await page.getByTestId('incident-type-injury').click();
        await page.getByTestId('incident-severity-medium').click();
        await page.getByTestId('incident-wizard-next').click();

        await expect(page.getByTestId('incident-wizard-step-1')).toBeVisible();
        await expect(page.getByTestId('incident-wizard-actions')).toHaveClass(
            /fixed/,
        );
        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('resume prompt: discard returns to empty Step 0', async ({
        page,
    }, testInfo) => {
        test.skip(
            !testInfo.project.name.includes('desktop'),
            'Resume prompt smoke runs on the desktop project.',
        );

        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/incidents/create');
        const draftKey = await getIncidentDraftKey(page);
        await seedLocalIncidentDraft(page, draftKey);
        await page.reload();

        await expect(
            page.getByTestId('incident-wizard-resume-prompt'),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Discard' }).click();

        await expect(page.getByTestId('incident-wizard-step-0')).toBeVisible();
        await expect(page.getByTestId('incident-client-select')).toContainText(
            /Select/i,
        );
        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('validation: missing client blocks Next', async ({ page }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/incidents/create');

        await page.getByTestId('incident-wizard-next').click();

        await expect(page.getByTestId('incident-wizard-step-0')).toBeVisible();
        await expect(page.getByTestId('incident-client-error')).toContainText(
            /Please choose/i,
        );
        expectNoUnexpectedConsoleErrors(consoleErrors);
    });
});
