import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const root = resolve(import.meta.dirname, '../../..');
const source = (path: string) => readFileSync(resolve(root, path), 'utf8');

describe('deferred HR UI contracts', () => {
    it('uses specialised payroll heroes with server-derived deep links', () => {
        const hero = source('resources/js/components/hr/payroll-hero.tsx');
        const runs = source('resources/js/pages/hr/payroll/index.tsx');
        const payslips = source('resources/js/pages/hr/payroll/payslips.tsx');

        expect(hero).toContain('export function PayrollHero');
        expect(hero).toContain("href: '/hr/payroll?status=draft'");
        expect(hero).toContain("href: '/hr/payroll/payslips?status=paid'");
        expect(runs).toContain('<PayrollHero');
        expect(payslips).toContain('<PayrollHero');
    });

    it('keeps payroll row actions available on desktop and mobile', () => {
        for (const path of [
            'resources/js/pages/hr/payroll/index.tsx',
            'resources/js/pages/hr/payroll/payslips.tsx',
        ]) {
            const page = source(path);
            expect(page).toContain('data-payroll-desktop');
            expect(page).toContain('data-payroll-mobile');
            expect(page).toContain('md:hidden');
            expect(page).toContain('hidden md:block');
            expect(page).toContain('useRowContextMenu');
        }
    });

    it('moves training onto the HR hero kit and repository colour tokens', () => {
        const hero = source('resources/js/components/hr/training-hero.tsx');
        const page = source('resources/js/pages/hr/training/catalog.tsx');

        expect(hero).toContain('export function TrainingHero');
        expect(page).toContain('<TrainingHero');
        expect(page).not.toContain('oklch(');
        expect(hero).not.toContain('oklch(');
    });

    it('uses a specialised feedback hero with deep-linked server stats', () => {
        const hero = source('resources/js/components/hr/feedback-hero.tsx');
        const page = source('resources/js/pages/hr/feedback/index.tsx');

        expect(hero).toContain('export function FeedbackHero');
        expect(hero).toContain("href: '/hr/feedback?status=pending'");
        expect(hero).toContain("href: '/hr/feedback?status=completed'");
        expect(hero).toContain("href: '/hr/feedback?status=overdue'");
        expect(page).toContain('<FeedbackHero');
    });

    it('owns TextPromptDialog in the neutral HR component layer', () => {
        const dialog = source(
            'resources/js/components/hr/text-prompt-dialog.tsx',
        );
        const documents = source('resources/js/pages/hr/documents/index.tsx');
        const recruitment = source(
            'resources/js/pages/hr/recruitment/index.tsx',
        );

        expect(dialog).toContain('export function TextPromptDialog');
        expect(documents).toContain('@/components/hr/text-prompt-dialog');
        expect(recruitment).toContain('@/components/hr/text-prompt-dialog');
        const oldOwner = ['recruitment', 'text-prompt-dialog'].join('/');
        expect(documents).not.toContain(oldOwner);
        expect(recruitment).not.toContain(oldOwner);
    });

    it('soft-refreshes visible entries without replacing filter state', () => {
        const page = source('resources/js/pages/hr/time/index.tsx');
        const controller = source(
            'app/Http/Controllers/Hr/TimeTrackingController.php',
        );

        expect(page).toMatch(
            /only:\s*\[[^\]]*'entries'[^\]]*'kpiStats'[^\]]*\]/s,
        );
        expect(page).toContain('preserveState: true');
        expect(controller).toContain("'entries' => $entries");
        expect(controller).toContain("'filters' => [");
    });
});
