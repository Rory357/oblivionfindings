import { expect, test, type Page } from '@playwright/test';

import { loginAs } from '../e2e/helpers';

const email = process.env.VISUAL_EMAIL ?? 'admin@demo.test';
const password = process.env.VISUAL_PASSWORD ?? 'password';

const authenticatedPages = [
    { name: 'dashboard', path: '/dashboard' },
    { name: 'settings-appearance', path: '/settings/appearance' },
    { name: 'settings-branding', path: '/settings/branding' },
    { name: 'settings-notifications', path: '/settings/notifications' },
    { name: 'control-room-broadcasts', path: '/control-room/broadcast' },
    { name: 'finance-dashboard', path: '/finance' },
    { name: 'finance-accounts', path: '/finance/accounts' },
    { name: 'governance-policies', path: '/governance/policies' },
    { name: 'health-clinical-events', path: '/health-clinical/events' },
    { name: 'incident-create', path: '/incidents/create' },
    { name: 'checklists', path: '/checklists' },
    { name: 'notifications', path: '/notifications' },
] as const;

const volatileTextReplacements: Partial<
    Record<
        (typeof authenticatedPages)[number]['name'],
        Array<{ pattern: string; flags: string; replacement: string }>
    >
> = {
    'finance-dashboard': [
        {
            pattern:
                '\\b(January|February|March|April|May|June|July|August|September|October|November|December)\\s+\\d{4}\\b',
            flags: 'gi',
            replacement: 'Reference period',
        },
        {
            pattern: '\\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\\b',
            flags: 'g',
            replacement: 'Mon',
        },
    ],
    checklists: [
        {
            pattern:
                '\\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\\s+\\d{1,2}\\s+(January|February|March|April|May|June|July|August|September|October|November|December)\\b',
            flags: 'g',
            replacement: 'Reference date',
        },
        {
            pattern:
                '\\b\\d{1,2}(?:\\s+[A-Z][a-z]{2})?\\s*[–-]\\s*\\d{1,2}\\s+[A-Z][a-z]{2}\\b',
            flags: 'g',
            replacement: 'reference week',
        },
    ],
};

async function stabiliseVolatileText(
    page: Page,
    targetName: (typeof authenticatedPages)[number]['name'],
) {
    const replacements = volatileTextReplacements[targetName];
    if (!replacements) return;

    await page.evaluate((rules) => {
        const walker = document.createTreeWalker(
            document.body,
            NodeFilter.SHOW_TEXT,
        );
        let node = walker.nextNode();
        while (node) {
            let value = node.textContent ?? '';
            for (const rule of rules) {
                value = value.replace(
                    new RegExp(rule.pattern, rule.flags),
                    rule.replacement,
                );
            }
            node.textContent = value;
            node = walker.nextNode();
        }
    }, replacements);
}

test.beforeEach(async ({ page }) => {
    await loginAs(page, email, password);
});

for (const target of authenticatedPages) {
    test(`${target.name} visual baseline`, async ({ page }) => {
        await page.goto(target.path);
        await page.waitForLoadState('networkidle');
        const main = page.locator('#main-content');
        await expect(main).toBeVisible();
        await expect(main).toContainText(/[A-Za-z0-9]/);
        await stabiliseVolatileText(page, target.name);
        await expect(page).toHaveScreenshot(`${target.name}.png`, {
            fullPage: true,
        });
    });
}
