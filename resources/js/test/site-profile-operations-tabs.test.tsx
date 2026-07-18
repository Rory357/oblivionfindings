import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = process.cwd();
const tabs = resolve(root, 'resources/js/pages/sites/tabs');
const operationFiles = [
    'calendar.tsx',
    'checklists.tsx',
    'meal-planner.tsx',
    'assets.tsx',
    'fleet.tsx',
    'hardware.tsx',
    'plan.tsx',
];

describe('site profile operations ownership', () => {
    it('extracts each Operations tab into a focused component', () => {
        for (const file of operationFiles) {
            expect(existsSync(resolve(tabs, file)), file).toBe(true);
        }
    });

    it('keeps Checklists compact and routes work to its canonical workspace', () => {
        const profile = readFileSync(
            resolve(root, 'resources/js/pages/sites/show.tsx'),
            'utf8',
        );
        const checklists = readFileSync(
            resolve(tabs, 'checklists.tsx'),
            'utf8',
        );
        const backend = readFileSync(
            resolve(root, 'app/Services/Sites/SiteProfileData.php'),
            'utf8',
        );

        expect(profile).not.toContain('ChecklistsWorkspace');
        expect(checklists).not.toContain('ChecklistsWorkspace');
        expect(checklists).toContain('SiteProfileModuleSummary');
        expect(backend).toContain("route('sites.checklists.index'");
        expect(backend).toContain('->limit(12)');
    });

    it('reuses the canonical Meal Planner in embedded mode', () => {
        const mealPlanner = readFileSync(
            resolve(tabs, 'meal-planner.tsx'),
            'utf8',
        );

        expect(mealPlanner).toContain("import('../meal-planner')");
        expect(mealPlanner).toContain('mode="embedded"');
        expect(mealPlanner).not.toContain('<form');
    });

    it('links asset, fleet, hardware, and plan summaries to their owners', () => {
        const backend = readFileSync(
            resolve(root, 'app/Services/Sites/SiteProfileData.php'),
            'utf8',
        );

        for (const routeName of [
            'fleet-assets.assets.index',
            'fleet-assets.dashboard',
            'sites.hardware.index',
            'sites.plan.show',
        ]) {
            expect(backend).toContain(routeName);
        }
    });

    it('keeps Meal Planner hidden for head office Sites', () => {
        const registry = readFileSync(resolve(tabs, 'registry.ts'), 'utf8');
        const mealPlannerDefinition = registry.slice(
            registry.indexOf("id: 'meal_planner'"),
            registry.indexOf("id: 'assets'"),
        );

        expect(mealPlannerDefinition).toContain("hiddenFor: ['head_office']");
    });
});
