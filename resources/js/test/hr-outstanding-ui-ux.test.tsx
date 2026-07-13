import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const root = resolve(import.meta.dirname, '../../..');
const source = (path: string) => {
    const absolute = resolve(root, path);

    return existsSync(absolute) ? readFileSync(absolute, 'utf8') : '';
};

describe('outstanding HR UI and UX contracts', () => {
    it('formats signature timestamps as en-NZ instants supplied in ISO form', () => {
        const controller = source(
            'app/Http/Controllers/Hr/ESignatureController.php',
        );
        const pending = source('resources/js/pages/hr/signatures/pending.tsx');
        const sign = source('resources/js/pages/hr/signatures/sign.tsx');

        expect(controller).toContain('toIso8601String()');
        expect(pending).toContain('@/lib/datetime');
        expect(pending).toMatch(
            /formatDateTimeLong\(\s*sig\.requested_at,?\s*\)/,
        );
        expect(sign).toContain('@/lib/datetime');
        expect(sign).toMatch(
            /formatDateTimeLong\(\s*signature\.requested_at,?\s*\)/,
        );
        expect(sign).toMatch(
            /formatDateTimeLong\(\s*signature\.signed_at,?\s*\)/,
        );
    });

    it('presents approval items and dates clearly without merging workflow ownership', () => {
        const controller = source(
            'app/Http/Controllers/Hr/ApprovalController.php',
        );
        const page = source('resources/js/pages/hr/approvals/pending.tsx');

        expect(controller).toContain("'item_label' =>");
        expect(controller).toContain('toIso8601String()');
        expect(page).toContain('instance.item_label');
        expect(page).toContain('@/lib/datetime');
        expect(page).toContain('formatDateTimeLong');
        expect(page).toContain('data-approvals-empty');
        expect(page).toContain('No approvals need your attention');
        expect(page).toContain('Native workflow approvals');
        expect(page).toContain('Approval chains');
    });

    it('makes the wellbeing undo boundary explicit and accessible', () => {
        const page = source('resources/js/pages/hr/wellbeing/index.tsx');

        expect(page).toContain('role="status"');
        expect(page).toContain('aria-live="polite"');
        expect(page).toContain('Undo removes only your latest triage action');
        expect(page).toContain('aria-label="Undo your latest triage action"');
    });

    it('uses specialised analytics, headcount, and succession heroes without duplicated KPI grids', () => {
        const analyticsHero = source(
            'resources/js/components/hr/analytics-hero.tsx',
        );
        const headcountHero = source(
            'resources/js/components/hr/headcount-hero.tsx',
        );
        const successionHero = source(
            'resources/js/components/hr/succession-hero.tsx',
        );
        const analytics = source('resources/js/pages/hr/analytics/index.tsx');
        const headcount = source('resources/js/pages/hr/headcount/index.tsx');
        const succession = source('resources/js/pages/hr/succession/index.tsx');

        expect(analyticsHero).toContain('export function AnalyticsHero');
        expect(analyticsHero).toContain("href: '/hr/headcount'");
        expect(headcountHero).toContain('export function HeadcountHero');
        expect(headcountHero).toContain(
            'href="/hr/recruitment?tab=requisitions"',
        );
        expect(successionHero).toContain('export function SuccessionHero');
        expect(analytics).toContain('<AnalyticsHero');
        expect(headcount).toContain('<HeadcountHero');
        expect(succession).toContain('<SuccessionHero');
        expect(analytics).not.toContain('{/* KPI Cards */}');
        expect(headcount).not.toContain('grid gap-4 md:grid-cols-4');
    });
});
