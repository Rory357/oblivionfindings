import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = process.cwd();
const read = (path: string) => readFileSync(resolve(root, path), 'utf8');

describe('corrective Site Profile contract', () => {
    it('opens with the Event Horizon profile header and typed dialog host', () => {
        const source = read('resources/js/pages/sites/show.tsx');

        expect(source).toContain('<PageHeader');
        expect(source).toContain('variant="profile"');
        expect(source).toContain('PageHeaderRail');
        expect(source).toContain('SiteProfileDialogHost');
        // Superseded page-top patterns must not come back: the legacy
        // PageHero, the bespoke site hero, and the alert ribbon (alert
        // counts live in the header meter row now).
        expect(source).not.toContain('import { PageHero');
        expect(source).not.toContain("from '@/components/sites/profile/hero'");
        expect(source).not.toContain('SiteProfileAlertRibbon');
        // Band first, then the tier-2 sub-nav strip on the page ground.
        expect(source.indexOf('<PageHeader')).toBeLessThan(
            source.indexOf('<TierTwoTabs'),
        );
    });

    it('maps each deferred tab to an individual optional prop', () => {
        const registry = read('resources/js/pages/sites/tabs/registry.ts');
        const expectedProps = [
            'clientsData',
            'contactsData',
            'staffRequirementsData',
            'shiftCoverageData',
            'hazardsData',
            'riskAssessmentsData',
            'inspectionsData',
            'drillsData',
            'firstAidData',
            'ppeData',
            'emergencyPlanData',
            'calendarData',
            'checklistsData',
            'mealPlannerData',
            'assetsData',
            'fleetData',
            'hardwareData',
            'planData',
            'documentsData',
            'financialsData',
            'vendorsCredentialsData',
            'servicesData',
        ];

        for (const prop of expectedProps) {
            expect(registry, prop).toContain("dataProp: '" + prop + "'");
        }
        expect(registry).not.toContain("dataGroup: 'peopleData'");
        expect(registry).not.toContain("dataGroup: 'safetyData'");
        expect(registry).not.toContain("dataGroup: 'operationsData'");
        expect(registry).not.toContain("dataGroup: 'adminData'");
    });

    it('does not retain summary-only replacement tabs', () => {
        const tabNames = [
            'hazards',
            'risk-assessments',
            'inspections',
            'drills',
            'first-aid',
            'ppe',
            'calendar',
            'checklists',
            'assets',
            'fleet',
            'hardware',
            'plan',
            'documents',
            'vendors',
            'services',
        ];

        for (const name of tabNames) {
            expect(
                read('resources/js/pages/sites/tabs/' + name + '.tsx'),
                name,
            ).not.toContain('SiteProfileModuleSummary');
        }

        expect(read('resources/js/pages/sites/show.tsx')).not.toContain(
            'module-summary-panel',
        );
    });
});
