import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

const source = readFileSync(
    'resources/js/pages/fleet-assets/maintenance/checklists/index.tsx',
    'utf8',
);

describe('Checklist template workflow', () => {
    it('uses an accessible three-step wizard with labelled fields and review', () => {
        expect(source).toContain('title="Create checklist template"');
        expect(source).toContain(
            'description="Build a reusable Fleet checklist and review its items before creating it."',
        );
        expect(source).toContain("label: 'Template details'");
        expect(source).toContain("label: 'Items'");
        expect(source).toContain("label: 'Review'");
        expect(source).toContain('htmlFor="checklist-template-name"');
        expect(source).not.toMatch(/<DialogContent(?:\s|>)/);
    });
});
