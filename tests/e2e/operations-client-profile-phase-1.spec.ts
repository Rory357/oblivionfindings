import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    runLaravelPhp,
} from './helpers';

const fixtureClientIds = new Set<number>();

function seedClientProfilePhaseOneFixture() {
    const output = runLaravelPhp(`
$client = \\App\\Models\\Client::factory()->create([
    'first_name' => 'Playwright',
    'last_name' => 'Profile',
    'status' => 'active',
]);
$recentClient = \\App\\Models\\Client::factory()->create([
    'first_name' => 'Recent',
    'last_name' => 'Playwright Client',
    'status' => 'active',
]);

echo json_encode([
    'clientId' => $client->id,
    'recentClientId' => $recentClient->id,
]);
`);

    const fixture = JSON.parse(output) as {
        clientId: number;
        recentClientId: number;
    };

    fixtureClientIds.add(fixture.clientId);
    fixtureClientIds.add(fixture.recentClientId);

    return fixture;
}

function cleanupClientProfilePhaseOneFixtures() {
    if (fixtureClientIds.size === 0) {
        return;
    }

    const ids = [...fixtureClientIds];
    runLaravelPhp(`
$ids = ${JSON.stringify(ids)};
foreach (\\App\\Models\\Client::query()->whereIn('id', $ids)->get() as $client) {
    $client->delete();
}
\\Illuminate\\Support\\Facades\\DB::table('audit_logs')
    ->where('auditable_type', 'client')
    ->whereIn('auditable_id', $ids)
    ->delete();
`);
    fixtureClientIds.clear();
}

test.describe('operations client profile phase 1', () => {
    test.afterEach(() => {
        cleanupClientProfilePhaseOneFixtures();
    });

    test('canonicalizes the legacy care plan tab without breaking Inertia history or dialog state', async ({
        page,
    }) => {
        const { clientId, recentClientId } = seedClientProfilePhaseOneFixture();
        const consoleErrors = collectConsoleErrors(page);
        const failedTargetRequests: string[] = [];
        page.on('response', (response) => {
            if (
                response.url().includes(`/operations/clients/${clientId}`) &&
                response.status() >= 400
            ) {
                failedTargetRequests.push(
                    `${response.status()} ${response.request().method()} ${response.url()}`,
                );
            }
        });

        await loginAsStaff(page);
        await page.evaluate(
            ({ id }) => {
                window.localStorage.setItem(
                    'recentClients',
                    JSON.stringify([
                        {
                            id,
                            name: 'Recent Playwright Client',
                            photo: null,
                            house: null,
                        },
                    ]),
                );
            },
            { id: recentClientId },
        );
        const previousUrl = page.url();
        const historyLength = await page.evaluate(() => window.history.length);

        await page.goto(
            `/operations/clients/${clientId}?tab=support_plan&dialog=quick_note&record=99&source=legacy`,
        );

        await expect
            .poll(() => new URL(page.url()).searchParams.get('tab'))
            .toBe('care_plans');
        expect(await page.evaluate(() => window.history.length)).toBe(
            historyLength + 1,
        );
        expect(new URL(page.url()).searchParams.get('dialog')).toBe(
            'quick_note',
        );
        expect(new URL(page.url()).searchParams.get('record')).toBe('99');
        expect(new URL(page.url()).searchParams.get('source')).toBe('legacy');
        await expect(page.getByTestId('client-group-plans')).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await expect(page.getByTestId('client-tab-care_plans')).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await expect(
            page.getByTestId('client-quick-note-dialog'),
        ).toBeVisible();
        await expect(
            page.getByTitle('Recent Playwright Client'),
        ).toHaveAttribute(
            'href',
            `/operations/clients/${recentClientId}?tab=care_plans`,
        );

        await page.goBack();
        await expect(page).toHaveURL(previousUrl);
        await page.goForward();
        await expect
            .poll(() => new URL(page.url()).searchParams.get('tab'))
            .toBe('care_plans');
        await expect(page.getByTestId('client-tab-care_plans')).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await expect(
            page.getByTestId('client-quick-note-dialog'),
        ).toBeVisible();

        await page.reload();
        await expect
            .poll(() => new URL(page.url()).searchParams.get('tab'))
            .toBe('care_plans');
        await expect(
            page.getByTestId('client-quick-note-dialog'),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
        expect(failedTargetRequests).toEqual([]);
    });

    test('submits a quick note from the web profile hero and projects it to the timeline', async ({
        page,
    }) => {
        const { clientId } = seedClientProfilePhaseOneFixture();
        await loginAsStaff(page);

        await page.goto(`/operations/clients/${clientId}`);
        await expect(
            page.getByRole('heading', { name: /Playwright Profile/i }),
        ).toBeVisible();
        await expect(
            page.getByTestId('client-profile-quick-note-button'),
        ).toBeVisible();

        await page.getByTestId('client-profile-quick-note-button').click();
        await expect(
            page.getByTestId('client-quick-note-dialog'),
        ).toBeVisible();
        await page
            .getByTestId('client-quick-note-subject')
            .fill('Quick note from Playwright');
        await page
            .getByTestId('client-quick-note-body')
            .fill('Desktop web quick note submission.');
        await page.getByTestId('client-quick-note-submit').click();

        await expect(page.getByText('Quick note from Playwright')).toBeVisible({
            timeout: 15_000,
        });

        const saved = runLaravelPhp(`
$note = \\App\\Models\\ClientNote::query()
    ->where('client_id', ${clientId})
    ->where('subject', 'Quick note from Playwright')
    ->firstOrFail();

echo json_encode([
    'type' => $note->type,
    'timelineCount' => \\App\\Models\\TimelineEvent::query()
        ->where('source_type', \\App\\Models\\ClientNote::class)
        ->where('source_id', $note->id)
        ->count(),
]);
`);

        expect(JSON.parse(saved)).toMatchObject({
            type: 'quick',
            timelineCount: 1,
        });
    });

    test('supports desktop keyboard shortcuts and daily note wizard step navigation', async ({
        page,
    }) => {
        const { clientId } = seedClientProfilePhaseOneFixture();
        await loginAsStaff(page);

        await page.goto(`/operations/clients/${clientId}`);
        await expect(
            page.getByTestId('client-profile-quick-note-button'),
        ).toBeVisible();

        await page.keyboard.press('n');
        await expect(
            page.getByTestId('client-quick-note-dialog'),
        ).toBeVisible();
        await page.getByTestId('client-quick-note-cancel').click();
        await expect(page.getByTestId('client-quick-note-dialog')).toBeHidden();

        await page.keyboard.press('Shift+N');
        await expect(
            page.getByTestId('client-daily-note-dialog'),
        ).toBeVisible();
        await expect(
            page.getByTestId('daily-note-step-category'),
        ).toBeVisible();
        await page.getByTestId('daily-note-category-concern').click();
        await page.getByTestId('daily-note-next').click();
        await expect(page.getByTestId('daily-note-step-details')).toBeVisible();
        await page.getByTestId('daily-note-body').fill('Concern note body.');
        await page.getByTestId('daily-note-next').click();
        await expect(page.getByTestId('daily-note-step-review')).toBeVisible();
        await page.keyboard.press('Escape');

        await page.keyboard.press('g');
        await page.keyboard.press('d');
        await expect(
            page.getByTestId('client-tab-progress_notes'),
        ).toHaveAttribute('aria-pressed', 'true');
        await expect(
            page
                .getByTestId('client-daily-notes-tab')
                .getByText('Review Queue'),
        ).toBeVisible();
    });
});
