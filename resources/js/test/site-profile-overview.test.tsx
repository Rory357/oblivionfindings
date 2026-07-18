import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const tabs = resolve(process.cwd(), 'resources/js/pages/sites/tabs');

describe('site profile overview ownership', () => {
    it('extracts overview, readiness, and attention into focused components', () => {
        for (const file of [
            'overview.tsx',
            'readiness.tsx',
            'attention-panel.tsx',
        ]) {
            expect(existsSync(resolve(tabs, file))).toBe(true);
        }
    });

    it('navigates readiness actions by tab and keeps semantic status cues', () => {
        const readiness = readFileSync(resolve(tabs, 'readiness.tsx'), 'utf8');
        const attention = readFileSync(
            resolve(tabs, 'attention-panel.tsx'),
            'utf8',
        );

        expect(readiness).toContain('onNavigate');
        expect(readiness).not.toContain('scrollIntoView');
        expect(attention).toContain('text-status-critical');
        expect(attention).toContain('text-status-warning');
    });
});
