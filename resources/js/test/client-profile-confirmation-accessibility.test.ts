import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const profileSources = [
    'resources/js/components/clients/profile/goal-dialog.tsx',
    'resources/js/components/clients/profile/abc-dialog.tsx',
    'resources/js/pages/operations/clients/documents.tsx',
    'resources/js/pages/operations/clients/tabs/care-support-plan.tsx',
    'resources/js/pages/operations/clients/tabs/risk-management.tsx',
];

describe('Client Profile destructive confirmations', () => {
    it.each(profileSources)(
        '%s uses an accessible confirmation dialog',
        (file) => {
            const source = readFileSync(resolve(process.cwd(), file), 'utf8');

            expect(source).not.toMatch(/\bwindow\.confirm\s*\(/);
            expect(source).not.toMatch(/(?<![\w.])confirm\s*\(/);
            expect(source).toContain('<ConfirmDialog');
        },
    );
});
