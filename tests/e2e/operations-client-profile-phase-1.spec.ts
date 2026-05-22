import { expect, test } from '@playwright/test';

import { loginAsStaff, runLaravelPhp } from './helpers';

function seedClientProfilePhaseOneFixture() {
    const output = runLaravelPhp(`
$client = \\App\\Models\\Client::factory()->create([
    'first_name' => 'Playwright',
    'last_name' => 'Profile',
    'status' => 'active',
]);

echo json_encode(['clientId' => $client->id]);
`);

    return JSON.parse(output) as { clientId: number };
}

test.describe('operations client profile phase 1', () => {
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
