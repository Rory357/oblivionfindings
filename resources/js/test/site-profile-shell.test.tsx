import { readFileSync, statSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const showPage = resolve(process.cwd(), 'resources/js/pages/sites/show.tsx');
const source = readFileSync(showPage, 'utf8');

describe('site profile shell ownership', () => {
    it('is a slim branded orchestrator rather than a module monolith', () => {
        expect(statSync(showPage).size).toBeLessThan(60_000);
        expect(source).toContain(
            "brandColour={site.brand_colour || 'var(--primary)'}",
        );
        expect(source).toContain('testIdPrefix="site-profile"');
    });

    it('loads optional groups without scroll-driven navigation', () => {
        expect(source).toContain('router.reload({');
        expect(source).toContain('only: [dataGroup]');
        expect(source).not.toContain('scrollIntoView');
    });

    it('settles failed deferred loads instead of restarting them', () => {
        expect(source).toContain('const loadingGroupsRef = useRef');
        expect(source).toContain('loadingGroupsRef.current[dataGroup]');
        expect(source).toContain("router.on('exception'");
        expect(source).toContain('exceptionTargetsGroup(');
        expect(source).not.toContain('[loadingGroups, props]');
    });

    it('does not host duplicate cross-module workspaces or credential dialogs', () => {
        expect(source).not.toContain('ChecklistsWorkspace');
        expect(source).not.toContain('AddVendorDialog');
        expect(source).not.toContain('ShowCredentialDialog');
        expect(source).not.toContain('AddCredentialDialog');
    });
});
