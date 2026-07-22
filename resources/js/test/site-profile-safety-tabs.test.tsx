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
    it('extracts each Safety tab into a focused full-depth component', () => {
        for (const file of safetyFiles) {
            expect(existsSync(resolve(tabs, file)), file).toBe(true);
        }
    });

    it('renders complete registers while canonical Health and Safety owns writes', () => {
        const sources = safetyFiles.map((file) =>
            readFileSync(resolve(tabs, file), 'utf8'),
        );

        for (const source of sources) {
            expect(source).not.toContain('SiteProfileModuleSummary');
        }

        const expectedContent: Record<string, string[]> = {
            'hazards.tsx': ['risk_rating', 'ApplicableProceduresPanel'],
            'risk-assessments.tsx': ['RaRegisterSection'],
            'inspections.tsx': ['schedules', 'records'],
            'drills.tsx': ['drill_status', 'open_findings'],
            'first-aid.tsx': ['follow', 'ambulance'],
            'ppe.tsx': ['condition', 'expiry'],
        };
        for (const [file, terms] of Object.entries(expectedContent)) {
            const source = readFileSync(resolve(tabs, file), 'utf8');
            for (const term of terms) {
                expect(source, file + ':' + term).toContain(term);
            }
        }
    });

    it('embeds the complete canonical Emergency Plan surface', () => {
        const emergency = readFileSync(
            resolve(tabs, 'emergency-plan.tsx'),
            'utf8',
        );

        expect(emergency).toContain('SiteEmergencyPlanSurface');
        expect(emergency).toContain('SiteTypePlanBuilderDialog');
    });
});
