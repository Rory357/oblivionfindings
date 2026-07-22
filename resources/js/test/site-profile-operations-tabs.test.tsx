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

    it('embeds the complete canonical Checklists workspace', () => {
        const checklists = readFileSync(
            resolve(tabs, 'checklists.tsx'),
            'utf8',
        );

        expect(checklists).toContain('ChecklistsWorkspace');
        expect(checklists).toContain('embedded');
        expect(checklists).not.toContain('SiteProfileModuleSummary');
    });

    it('embeds the complete canonical Site Calendar', () => {
        const calendar = readFileSync(resolve(tabs, 'calendar.tsx'), 'utf8');

        expect(calendar).toContain('SiteCalendar');
        expect(calendar).toContain('context="profile"');
        expect(calendar).not.toContain('SiteProfileModuleSummary');
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

    it('restores full asset, fleet, hardware, and plan surfaces', () => {
        for (const file of [
            'assets.tsx',
            'fleet.tsx',
            'hardware.tsx',
            'plan.tsx',
        ]) {
            const source = readFileSync(resolve(tabs, file), 'utf8');
            expect(source, file).not.toContain('SiteProfileModuleSummary');
        }
        expect(readFileSync(resolve(tabs, 'plan.tsx'), 'utf8')).toContain(
            'SitePlanSurface',
        );
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
