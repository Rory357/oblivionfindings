import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = process.cwd();
const tabs = resolve(root, 'resources/js/pages/sites/tabs');
const safetyFiles = [
    'hazards.tsx',
    'risk-assessments.tsx',
    'inspections.tsx',
    'drills.tsx',
    'first-aid.tsx',
    'ppe.tsx',
    'emergency-plan.tsx',
];

describe('site profile safety ownership', () => {
    it('extracts each Safety tab into a focused summary component', () => {
        for (const file of safetyFiles) {
            expect(existsSync(resolve(tabs, file)), file).toBe(true);
        }
    });

    it('routes Safety work to canonical Health and Safety owners', () => {
        const sources = safetyFiles.map((file) =>
            readFileSync(resolve(tabs, file), 'utf8'),
        );

        for (const source of sources) {
            expect(source).not.toContain('useForm');
            expect(source).not.toContain('router.post');
            expect(source).toContain('SiteProfileModuleSummary');
        }

        const backend = readFileSync(
            resolve(root, 'app/Services/Sites/SiteProfileData.php'),
            'utf8',
        );
        for (const routeName of [
            'health-safety.risk-assessments.index',
            'health-safety.drills.index',
            'health-safety.first-aid.index',
            'health-safety.ppe.index',
        ]) {
            expect(backend).toContain(routeName);
        }
    });

    it('keeps Site-owned emergency metadata without a duplicate editor', () => {
        const emergency = readFileSync(
            resolve(tabs, 'emergency-plan.tsx'),
            'utf8',
        );

        expect(emergency).toContain('medication_storage_location');
        expect(emergency).not.toContain('<form');
    });
});
