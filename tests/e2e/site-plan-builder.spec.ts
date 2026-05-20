import { expect, test, type Page } from '@playwright/test';
import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    runLaravelPhp,
} from './helpers';

function seedSitePlanBuilderFixture() {
    const output = runLaravelPhp(`
$user = \\App\\Models\\User::query()->where('email', 'admin@demo.test')->first();
$site = \\App\\Models\\Site::factory()->create([
    'name' => 'Plan Builder E2E ' . \\Illuminate\\Support\\Str::random(5),
    'type' => 'house',
]);

$plans = app(\\App\\Services\\Sites\\SiteTypePlanService::class);
$layout = [
    'schema_version' => 1,
    'canvas' => ['width' => 1000, 'height' => 700, 'unit' => 'rel'],
    'grid' => ['enabled' => true, 'size' => 20, 'snap' => true],
    'rooms' => [
        ['id' => 'living', 'label' => 'Living', 'shape' => 'rect', 'x' => 0.08, 'y' => 0.12, 'width' => 0.28, 'height' => 0.22],
    ],
    'walls' => [
        ['id' => 'front-wall', 'points' => [['x' => 0.05, 'y' => 0.55], ['x' => 0.65, 'y' => 0.55]], 'thickness' => 4],
    ],
    'doors' => [],
    'windows' => [],
    'labels' => [],
];

$draft = $plans->storeDraft($site, $layout, 'E2E plan builder fixture', $user?->id);
$plans->replacePins($draft, [
    ['kind' => 'fire_extinguisher', 'label' => null, 'x' => 0.22, 'y' => 0.35],
    ['kind' => 'medication_storage', 'label' => 'Medication safe', 'x' => 0.42, 'y' => 0.35, 'meta' => ['is_locked' => true]],
], true);
$plans->publishDraft($site, $user?->id);

echo json_encode(['siteId' => $site->id]);
`);

    return JSON.parse(output) as { siteId: number };
}

async function clickCanvasAt(page: Page, xRatio: number, yRatio: number) {
    const box = await page.getByTestId('site-plan-canvas').boundingBox();
    expect(box).not.toBeNull();
    await page.mouse.click(
        box!.x + box!.width * xRatio,
        box!.y + box!.height * yRatio,
    );
}

test('site plan builder supports select, retyping, and emergency mode', async ({
    page,
}) => {
    const errors = collectConsoleErrors(page);
    const { siteId } = seedSitePlanBuilderFixture();

    await loginAsStaff(page);
    await page.goto(`/sites/${siteId}?tab=type-plan`);

    await page.getByRole('button', { name: /Edit Plan/i }).click();
    const dialog = page.getByTestId('site-plan-builder-dialog');
    await expect(dialog).toBeVisible();
    await expect(page.getByTestId('site-plan-select-tool')).toBeVisible();

    const canvas = page.getByTestId('site-plan-canvas');
    const box = await canvas.boundingBox();
    expect(box).not.toBeNull();
    await page.mouse.move(
        box!.x + box!.width * 0.04,
        box!.y + box!.height * 0.1,
    );
    await page.mouse.down();
    await page.mouse.move(
        box!.x + box!.width * 0.5,
        box!.y + box!.height * 0.48,
    );
    await page.mouse.up();
    await expect(page.getByTestId('site-plan-marquee-count')).toContainText(
        /items selected/i,
    );

    await clickCanvasAt(page, 0.22, 0.35);
    await page.getByTestId('site-plan-pin-kind-picker').click();
    await page.getByTestId('site-plan-pin-kind-option-smoke_alarm').click();
    await expect(dialog).toContainText(/smoke alarm/i);

    await page.getByRole('button', { name: /Save Draft/i }).click();
    await expect(dialog).toContainText(/All changes saved/i);
    await dialog.getByRole('button', { name: /Close/i }).click();
    await expect(dialog).toBeHidden();

    await page.goto(`/sites/${siteId}/emergency-plan`);
    await page.getByRole('button', { name: /Edit emergency plan/i }).click();
    await expect(
        page.getByTestId('site-plan-emergency-mode-badge'),
    ).toBeVisible();
    await expect(
        page.getByTestId('site-plan-emergency-checklist'),
    ).toBeVisible();
    await expect(page.getByTestId('site-plan-wall-tool')).toHaveCount(0);

    await dialog.getByRole('button', { name: /Assembly point/i }).click();
    await expect(page.getByTestId('site-plan-tool-hint')).toContainText(
        /Assembly point/i,
    );
    await clickCanvasAt(page, 0.78, 0.82);
    await dialog.getByRole('button', { name: /Emergency exit/i }).click();
    await expect(page.getByTestId('site-plan-tool-hint')).toContainText(
        /Emergency exit/i,
    );
    await clickCanvasAt(page, 0.12, 0.86);
    await expect(
        page.getByTestId('site-plan-emergency-checklist'),
    ).toContainText(/Ready to publish/i);

    await page.getByRole('button', { name: /Publish/i }).click();
    await expect(dialog).toContainText(/All changes saved/i);
    await dialog.getByRole('button', { name: /Close/i }).click();
    await expect(page.getByText(/Ready to export/i).first()).toBeVisible();

    expectNoConsoleErrors(errors);
});
