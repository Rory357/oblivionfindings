import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

import {
    loginAsFixture,
    seedIncidentHandoverFixtures,
    type FixtureUser,
} from './incident-handover-helpers';

const surfaces = [
    { path: '/control-room', title: 'Desk', slug: 'desk' },
    {
        path: '/control-room/alerts',
        title: 'Active alerts',
        slug: 'active-alerts',
    },
    {
        path: '/control-room/escalations',
        title: 'Escalations',
        slug: 'escalations',
    },
    {
        path: '/control-room/incidents',
        title: 'Safety handovers',
        slug: 'safety-handovers',
    },
    {
        path: '/control-room/my-tasks',
        title: 'My queue',
        slug: 'my-queue',
    },
    { path: '/control-room/shifts', title: 'Shifts', slug: 'shifts' },
] as const;

const viewports = [
    { width: 1440, height: 900, slug: 'desktop' },
    { width: 1024, height: 768, slug: 'compact' },
    { width: 390, height: 844, slug: 'mobile' },
] as const;

const heroDensityViewports = [
    { width: 1440, height: 900, maxHeroToAction: 180 },
    { width: 1280, height: 800, maxHeroToAction: 180 },
    { width: 1024, height: 768, maxHeroToAction: 180 },
    { width: 390, height: 844, maxHeroToAction: 240 },
] as const;

const evidenceDirectory = resolve(
    process.env.CONTROL_ROOM_UI_EVIDENCE_DIR ??
        resolve(
            process.cwd(),
            'output',
            'playwright',
            'control-room-ui-consistency',
        ),
);

async function createAlert(page: Page, siteId: number, marker: string) {
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
    const response = await page.request.post('/control-room/alerts', {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.value) : '',
        },
        data: {
            source: 'manual',
            alert_type: marker,
            severity: 'critical',
            site_id: siteId,
        },
    });

    expect(response.status(), await response.text()).toBe(201);

    return (await response.json()) as {
        alert: { id: number };
    };
}

test.describe('control room — UI consistency acceptance', () => {
    test.describe.configure({ mode: 'serial' });
    test.setTimeout(240_000);

    let operator: FixtureUser;
    let incoming: FixtureUser;
    let siteId: number;

    test.beforeAll(() => {
        const manifest = seedIncidentHandoverFixtures();
        operator = manifest.users.operator;
        incoming = manifest.users.incoming;
        siteId = manifest.site.id;
        mkdirSync(evidenceDirectory, { recursive: true });
    });

    test.beforeEach(async ({ page }, testInfo) => {
        test.skip(
            !testInfo.project.name.includes('desktop'),
            'This acceptance matrix sets its own exact desktop, compact, and mobile viewports.',
        );
        await loginAsFixture(page, operator);
    });

    test('all six primary surfaces share the workspace shell without page overflow', async ({
        page,
    }) => {
        for (const viewport of viewports) {
            await page.setViewportSize(viewport);

            for (const surface of surfaces) {
                await page.goto(surface.path, {
                    waitUntil: 'domcontentloaded',
                });
                await expect(
                    page.getByRole('heading', {
                        name: surface.title,
                        exact: true,
                        level: 1,
                    }),
                ).toBeVisible();
                await expect(
                    page.getByRole('navigation', {
                        name: 'Control Room workspace',
                    }),
                ).toBeVisible();
                await expect(
                    page.locator('[data-testid="control-room-hero"]'),
                ).toBeVisible();

                const documentWidth = await page.evaluate(() => ({
                    client: document.documentElement.clientWidth,
                    scroll: document.documentElement.scrollWidth,
                }));
                expect(documentWidth.scroll).toBeLessThanOrEqual(
                    documentWidth.client + 1,
                );

                await page.screenshot({
                    path: resolve(
                        evidenceDirectory,
                        `${viewport.slug}-${viewport.width}x${viewport.height}-${surface.slug}.png`,
                    ),
                    fullPage: false,
                    animations: 'disabled',
                });
            }
        }
    });

    test('the compact task-page hero keeps the first queue action above the fold', async ({
        page,
    }) => {
        for (const viewport of heroDensityViewports) {
            await page.setViewportSize(viewport);
            await page.goto('/control-room/integration-alerts', {
                waitUntil: 'domcontentloaded',
            });

            const hero = page.locator(
                '[data-page-hero-type="task"][data-page-hero-variant="compact"]',
            );
            const quickFilters = page.getByRole('group', {
                name: 'Alert queue filters',
            });
            const firstAction = quickFilters
                .locator('[data-page-first-action]')
                .first();

            await expect(hero).toBeVisible();
            await expect(quickFilters).toBeVisible();
            await expect(firstAction).toBeVisible();
            await expect(
                quickFilters.locator('[aria-pressed="true"]'),
            ).toHaveCount(1);

            const [heroBounds, actionBounds] = await Promise.all([
                hero.boundingBox(),
                firstAction.boundingBox(),
            ]);

            expect(heroBounds).not.toBeNull();
            expect(actionBounds).not.toBeNull();
            expect(actionBounds!.width).toBeGreaterThanOrEqual(44);
            expect(actionBounds!.height).toBeGreaterThanOrEqual(44);
            expect(actionBounds!.y - heroBounds!.y).toBeLessThanOrEqual(
                viewport.maxHeroToAction,
            );
            expect(actionBounds!.y + actionBounds!.height).toBeLessThanOrEqual(
                viewport.height,
            );

            await firstAction.focus();
            await expect(firstAction).toBeFocused();
            const focusOutline = await firstAction.evaluate((element) => {
                const style = window.getComputedStyle(element);
                return {
                    style: style.outlineStyle,
                    width: Number.parseFloat(style.outlineWidth),
                };
            });
            expect(focusOutline.style).not.toBe('none');
            expect(focusOutline.width).toBeGreaterThanOrEqual(2);

            const documentWidth = await page.evaluate(() => ({
                client: document.documentElement.clientWidth,
                scroll: document.documentElement.scrollWidth,
            }));
            expect(documentWidth.scroll).toBeLessThanOrEqual(
                documentWidth.client + 1,
            );

            await page.screenshot({
                path: resolve(
                    evidenceDirectory,
                    `hero-density-${viewport.width}x${viewport.height}-integration-alerts.png`,
                ),
                fullPage: false,
                animations: 'disabled',
            });
        }
    });

    test('right click and overflow expose the same actions and restore focus', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        const marker = `UI consistency context menu proof ${Date.now()}`;
        await createAlert(page, siteId, marker);
        await page.goto('/control-room/alerts', {
            waitUntil: 'domcontentloaded',
        });
        const search = page.getByPlaceholder('Search alerts...');
        await search.fill(marker);
        await search.press('Enter');
        await expect(
            page.locator('[data-testid="alert-worklist-row"]'),
        ).toHaveCount(1);
        await page.evaluate(
            () =>
                new Promise<void>((resolveNavigation) =>
                    window.requestAnimationFrame(() =>
                        window.requestAnimationFrame(() => resolveNavigation()),
                    ),
                ),
        );

        const row = page
            .locator('[data-testid="alert-worklist-row"]')
            .filter({ hasText: marker });
        await expect(row).toBeVisible();
        const reference = (
            (await row.locator('.font-mono').first().textContent()) ?? ''
        ).trim();
        expect(reference).not.toBe('');
        await row.focus();
        await row.click({ button: 'right', position: { x: 24, y: 24 } });

        const menu = page.getByRole('menu', {
            name: `Actions for ${reference}`,
        });
        await expect(menu).toBeVisible();
        const contextLabels = await menu
            .getByRole('menuitem')
            .allTextContents();
        await page.keyboard.press('Escape');
        await expect(menu).toBeHidden();
        await expect(row).toBeFocused();

        const overflow = row.getByRole('button', {
            name: `Actions for ${reference}`,
        });
        await overflow.click();
        await expect(menu).toBeVisible();
        const overflowLabels = await menu
            .getByRole('menuitem')
            .allTextContents();
        expect(overflowLabels).toEqual(contextLabels);

        await page.keyboard.press('Escape');
        await expect(overflow).toBeFocused();

        await row.evaluate((element) => {
            element.dispatchEvent(
                new MouseEvent('contextmenu', {
                    bubbles: true,
                    cancelable: true,
                    clientX: 1438,
                    clientY: 898,
                    button: 2,
                    buttons: 2,
                }),
            );
        });
        await expect(menu).toBeVisible();
        const menuBounds = await menu.boundingBox();
        expect(menuBounds).not.toBeNull();
        expect(menuBounds!.x + menuBounds!.width).toBeLessThanOrEqual(1432);
        expect(menuBounds!.y + menuBounds!.height).toBeLessThanOrEqual(892);
    });

    test('server permissions remove incident creation from an unauthorised operator menu', async ({
        page,
    }) => {
        const marker = `UI permission menu proof ${Date.now()}`;
        await createAlert(page, siteId, marker);
        await loginAsFixture(page, incoming);
        await page.goto('/control-room/alerts', {
            waitUntil: 'domcontentloaded',
        });
        const search = page.getByPlaceholder('Search alerts...');
        await search.fill(marker);
        await search.press('Enter');
        await expect(
            page.locator('[data-testid="alert-worklist-row"]'),
        ).toHaveCount(1);

        const row = page
            .locator('[data-testid="alert-worklist-row"]')
            .filter({ hasText: marker });
        await expect(row).toBeVisible();
        await page.evaluate(
            () =>
                new Promise<void>((resolveNavigation) =>
                    window.requestAnimationFrame(() =>
                        window.requestAnimationFrame(() => resolveNavigation()),
                    ),
                ),
        );
        const reference = (
            (await row.locator('.font-mono').first().textContent()) ?? ''
        ).trim();
        expect(reference).not.toBe('');
        const overflow = row.getByRole('button', {
            name: `Actions for ${reference}`,
        });
        await expect(overflow).toHaveAttribute('aria-expanded', 'false');
        await overflow.click();
        await expect(overflow).toHaveAttribute('aria-expanded', 'true');

        const menu = page.getByRole('menu', {
            name: `Actions for ${reference}`,
        });
        await expect(menu).toBeVisible();
        await expect(
            menu.getByRole('menuitem', {
                name: 'Create incident in workspace',
            }),
        ).toHaveCount(0);
    });
});
